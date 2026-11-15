<?php
declare(strict_types=1);
namespace App\Services;

/**
 * Thin IMAP adapter. Requires PHP's IMAP extension; it never stores credentials.
 */
final class ImapClient
{
    public function available(): bool { return function_exists('imap_open'); }


    public static function buildSearchCriteria(?string $query): string
    {
        $query = trim((string)$query);
        if ($query === '') return 'ALL';
        if (strlen($query) > 200 || preg_match('/[\x00-\x1F\x7F]/', $query) || str_contains($query, '"') || str_contains($query, '\\')) {
            throw new \InvalidArgumentException('Search query contains unsupported characters.');
        }
        return 'TEXT "' . $query . '"';
    }

    public function folders(array $settings, array $credentials): array
    {
        $this->requireExtension();
        $prefix = $this->prefix($settings);
        $stream = $this->open($prefix . 'INBOX', $credentials, OP_READONLY);
        try {
            $items = imap_getmailboxes($stream, $prefix, '*');
            if ($items === false) throw new \RuntimeException('Unable to list IMAP folders.');
            $folders = [];
            foreach ($items as $item) {
                $name = function_exists('imap_utf7_decode') ? imap_utf7_decode($item->name) : $item->name;
                $folders[] = ['name' => $name, 'delimiter' => $item->delimiter ?? '/'];
            }
            return $folders;
        } finally { imap_close($stream); }
    }

    public function listMessages(array $settings, array $credentials, string $folder, int $limit = 50, int $offset = 0, ?string $query = null): array
    {
        $this->requireExtension();
        $stream = $this->open($this->prefix($settings) . $this->encodeFolder($folder), $credentials, OP_READONLY);
        try {
            if ($offset < 0) throw new \InvalidArgumentException('Invalid message offset.');
            $limit = max(1, min(101, $limit));
            $criteria = self::buildSearchCriteria($query);
            $uids = imap_search($stream, $criteria, SE_UID);
            if ($uids === false) return [];
            rsort($uids, SORT_NUMERIC);
            $uids = array_slice($uids, $offset, $limit);
            if (!$uids) return [];
            $overview = imap_fetch_overview($stream, implode(',', $uids), FT_UID);
            $byUid = [];
            foreach ($overview ?: [] as $item) {
                $uid = (int)($item->uid ?? 0);
                $byUid[$uid] = [
                    'uid' => $uid,
                    'subject' => $this->decodeHeader((string)($item->subject ?? '(no subject)')),
                    'from' => $this->decodeHeader((string)($item->from ?? '')),
                    'to' => $this->decodeHeader((string)($item->to ?? '')),
                    'date' => (string)($item->date ?? ''),
                    'seen' => !empty($item->seen),
                    'flagged' => !empty($item->flagged),
                    'size' => (int)($item->size ?? 0),
                ];
            }
            return array_values(array_replace(array_fill_keys($uids, []), $byUid));
        } finally { imap_close($stream); }
    }

    public function getMessage(array $settings, array $credentials, string $folder, int $uid): array
    {
        $this->requireExtension();
        if ($uid < 1) throw new \InvalidArgumentException('Invalid message identifier.');
        $stream = $this->open($this->prefix($settings) . $this->encodeFolder($folder), $credentials, 0);
        try {
            $overview = imap_fetch_overview($stream, (string)$uid, FT_UID);
            if (!$overview) throw new \RuntimeException('Message not found.');
            $item = $overview[0];
            $structure = imap_fetchstructure($stream, $uid, FT_UID);
            $attachments = [];
            $body = $this->extractBody($stream, $uid, $structure, $attachments);
            imap_setflag_full($stream, (string)$uid, '\\Seen', ST_UID);
            return [
                'uid' => $uid,
                'subject' => $this->decodeHeader((string)($item->subject ?? '(no subject)')),
                'from' => $this->decodeHeader((string)($item->from ?? '')),
                'to' => $this->decodeHeader((string)($item->to ?? '')),
                'cc' => $this->decodeHeader((string)($item->cc ?? '')),
                'date' => (string)($item->date ?? ''),
                'seen' => !empty($item->seen),
                'flagged' => !empty($item->flagged),
                'message_id' => trim((string)($item->message_id ?? '')),
                'in_reply_to' => trim((string)($item->in_reply_to ?? '')),
                'references' => trim((string)($item->references ?? '')),
                'body' => $body,
                'attachments' => $attachments,
            ];
        } finally { imap_close($stream); }
    }

    public function fetchAttachment(array $settings, array $credentials, string $folder, int $uid, string $section, int $encoding): string
    {
        $this->requireExtension();
        if ($uid < 1 || !preg_match('/^\d+(?:\.\d+)*$/', $section)) throw new \InvalidArgumentException('Invalid attachment reference.');
        $stream = $this->open($this->prefix($settings) . $this->encodeFolder($folder), $credentials, OP_READONLY);
        try {
            $raw = imap_fetchbody($stream, $uid, $section, FT_UID | FT_PEEK);
            if ($raw === false) throw new \RuntimeException('Attachment data could not be retrieved.');
            if ($encoding === 3) {
                $decoded = base64_decode($raw, true);
                if ($decoded === false) throw new \RuntimeException('Attachment has invalid base64 encoding.');
                $raw = $decoded;
            } elseif ($encoding === 4) {
                $raw = quoted_printable_decode($raw);
            }
            if (strlen($raw) > 25_000_000) throw new \RuntimeException('Attachment exceeds the 25 MB download limit.');
            return $raw;
        } finally { imap_close($stream); }
    }

    public function appendRawMessage(array $settings, array $credentials, string $folder, string $raw, string $flag): void
    {
        $this->requireExtension();
        if (!in_array($flag, ['\Seen', '\Draft'], true)) throw new \InvalidArgumentException('Unsupported IMAP message flag.');
        if (strlen($raw) > 30_000_000 || !str_contains($raw, "\r\n\r\n")) throw new \InvalidArgumentException('Invalid or oversized RFC message.');
        $stream = $this->open($this->prefix($settings) . 'INBOX', $credentials, 0);
        try {
            $target = $this->prefix($settings) . $this->encodeFolder($folder);
            if (!imap_append($stream, $target, $raw, $flag)) throw new \RuntimeException('Unable to save message to IMAP folder: ' . $this->lastError());
        } finally { imap_close($stream); }
    }


    public function setFlag(array $settings, array $credentials, string $folder, int $uid, string $flag, bool $enabled): void
    {
        $this->requireExtension();
        if ($uid < 1 || !in_array($flag, ['Seen', 'Flagged'], true)) throw new \InvalidArgumentException('Unsupported message flag operation.');
        $stream = $this->open($this->prefix($settings) . $this->encodeFolder($folder), $credentials, 0);
        try {
            $flags = '\\' . $flag;
            $ok = $enabled
                ? imap_setflag_full($stream, (string)$uid, $flags, ST_UID)
                : imap_clearflag_full($stream, (string)$uid, $flags, ST_UID);
            if (!$ok) throw new \RuntimeException('Unable to update message flags: ' . $this->lastError());
        } finally { imap_close($stream); }
    }

    public function move(array $settings, array $credentials, string $source, int $uid, string $destination): void
    {
        $this->requireExtension();
        $stream = $this->open($this->prefix($settings) . $this->encodeFolder($source), $credentials, 0);
        try {
            if (!imap_mail_move($stream, (string)$uid, $this->encodeFolder($destination), CP_UID)) {
                throw new \RuntimeException('Unable to move message: ' . $this->lastError());
            }
            if (!imap_expunge($stream)) throw new \RuntimeException('Unable to expunge moved message.');
        } finally { imap_close($stream); }
    }

    private function open(string $mailbox, array $credentials, int $flags)
    {
        $stream = @imap_open($mailbox, $credentials['email'], $credentials['password'], $flags, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);
        if ($stream === false) throw new \RuntimeException('IMAP connection failed: ' . $this->lastError());
        return $stream;
    }

    private function prefix(array $settings): string
    {
        $host = (string)($settings['imap_host'] ?? '');
        $port = (int)($settings['imap_port'] ?? 993);
        $encryption = strtolower((string)($settings['imap_encryption'] ?? 'ssl'));
        if ($host === '' || !preg_match('/^[a-zA-Z0-9.-]+$/', $host)) throw new \RuntimeException('IMAP host is not configured correctly.');
        if ($port < 1 || $port > 65535) throw new \RuntimeException('Invalid IMAP port.');
        if (!in_array($encryption, ['ssl', 'starttls'], true)) throw new \RuntimeException('IMAP connections must use SSL or STARTTLS.');
        $flags = $encryption === 'ssl' ? '/imap/ssl' : '/imap/tls';
        return '{' . $host . ':' . $port . $flags . '}';
    }

    private function encodeFolder(string $folder): string
    {
        if ($folder === '' || str_contains($folder, "\0")) throw new \InvalidArgumentException('Invalid folder name.');
        return function_exists('imap_utf7_encode') ? imap_utf7_encode($folder) : $folder;
    }

    private function extractBody($stream, int $uid, $structure, array &$attachments): string
    {
        if (!$structure) return (string)imap_body($stream, $uid, FT_UID | FT_PEEK);
        $parts = [];
        $this->collectParts($stream, $uid, $structure, '', $parts, $attachments);
        foreach ($parts as $part) if ($part['type'] === 'plain') return $part['body'];
        foreach ($parts as $part) if ($part['type'] === 'html') {
            $text = preg_replace('/<(br|\/p|\/div|\/li|\/tr)\b[^>]*>/i', "\n", $part['body']);
            return html_entity_decode(strip_tags((string)$text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return '';
    }

    private function collectParts($stream, int $uid, $structure, string $section, array &$parts, array &$attachments): void
    {
        if (!empty($structure->parts)) {
            foreach ($structure->parts as $index => $part) {
                $partSection = $section === '' ? (string)($index + 1) : $section . '.' . ($index + 1);
                $this->collectParts($stream, $uid, $part, $partSection, $parts, $attachments);
            }
            return;
        }
        $subtype = strtolower((string)($structure->subtype ?? 'plain'));
        $type = (int)($structure->type ?? 0);
        $filename = $this->partFilename($structure);
        $disposition = strtolower((string)($structure->disposition ?? ''));
        if ($filename !== '' || $disposition === 'attachment' || ($type !== 0 && $type !== 1) || ($type === 0 && !in_array($subtype, ['plain', 'html'], true))) {
            $attachments[] = [
                'section' => $section === '' ? '1' : $section,
                'filename' => $filename !== '' ? $this->decodeHeader($filename) : 'attachment-' . (count($attachments) + 1),
                'mime' => $this->mimeType($type, $subtype),
                'size' => (int)($structure->bytes ?? 0),
                'encoding' => (int)($structure->encoding ?? 0),
            ];
            return;
        }
        if ($type !== 0 || !in_array($subtype, ['plain', 'html'], true)) return;
        $raw = $section === ''
            ? imap_body($stream, $uid, FT_UID | FT_PEEK)
            : imap_fetchbody($stream, $uid, $section, FT_UID | FT_PEEK);
        $body = (string)$raw;
        $encoding = (int)($structure->encoding ?? 0);
        if ($encoding === 3) $body = (string)base64_decode($body, true);
        elseif ($encoding === 4) $body = quoted_printable_decode($body);
        $charset = 'UTF-8';
        foreach (($structure->parameters ?? []) as $parameter) {
            if (strtolower((string)$parameter->attribute) === 'charset') $charset = (string)$parameter->value;
        }
        if (strtoupper($charset) !== 'UTF-8' && function_exists('mb_convert_encoding')) {
            $body = mb_convert_encoding($body, 'UTF-8', $charset);
        }
        $parts[] = ['type' => $subtype, 'body' => $body];
    }

    private function partFilename($structure): string
    {
        foreach (array_merge($structure->parameters ?? [], $structure->dparameters ?? []) as $parameter) {
            if (in_array(strtolower((string)($parameter->attribute ?? '')), ['name', 'filename'], true)) {
                return (string)($parameter->value ?? '');
            }
        }
        return '';
    }

    private function mimeType(int $type, string $subtype): string
    {
        $primary = [0=>'text',1=>'multipart',2=>'message',3=>'application',4=>'audio',5=>'image',6=>'video',7=>'application'];
        $major = $primary[$type] ?? 'application';
        return preg_match('/^[a-z0-9.+-]+$/i', $subtype) ? $major . '/' . $subtype : 'application/octet-stream';
    }

    private function decodeHeader(string $value): string
    {
        if ($value === '') return '';
        $decoded = function_exists('imap_mime_header_decode') ? imap_mime_header_decode($value) : false;
        if ($decoded === false) return $value;
        $result = '';
        foreach ($decoded as $part) {
            $text = (string)$part->text;
            $charset = (string)($part->charset ?? 'UTF-8');
            if (strtoupper($charset) !== 'DEFAULT' && strtoupper($charset) !== 'UTF-8' && function_exists('mb_convert_encoding')) {
                $text = mb_convert_encoding($text, 'UTF-8', $charset);
            }
            $result .= $text;
        }
        return $result;
    }

    private function requireExtension(): void
    {
        if (!$this->available()) throw new \RuntimeException('PHP IMAP extension is required. Enable ext-imap on the web server.');
    }

    private function lastError(): string { return (string)(imap_last_error() ?: 'unknown error'); }
}
