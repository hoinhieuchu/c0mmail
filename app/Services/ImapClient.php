<?php
declare(strict_types=1);
namespace App\Services;

/**
 * Thin IMAP adapter. Requires PHP's IMAP extension; it never stores credentials.
 */
final class ImapClient
{
    public const MAX_MESSAGE_BYTES = 40_000_000;

    public static function validateMessageSize(int $bytes): void
    {
        if ($bytes < 0 || $bytes > self::MAX_MESSAGE_BYTES) {
            throw new \RuntimeException('Message exceeds the 40 MB retrieval limit.');
        }
    }

    public function available(): bool { return function_exists('imap_open'); }


    public static function stripServerPrefix(string $name, string $prefix): string
    {
        return str_starts_with($name, $prefix) ? substr($name, strlen($prefix)) : $name;
    }

    public static function buildSearchCriteria(?string $query): string
    {
        $query = trim((string)$query);
        if ($query === '') return 'UNDELETED';
        if (strlen($query) > 200 || preg_match('/[\x00-\x1F\x7F]/', $query) || str_contains($query, '"') || str_contains($query, '\\')) {
            throw new \InvalidArgumentException('Search query contains unsupported characters.');
        }
        return 'UNDELETED TEXT "' . $query . '"';
    }

    public static function normalizeSearchResult($result, array $errors = []): array
    {
        if ($errors !== []) {
            throw new \RuntimeException('IMAP search failed: ' . implode('; ', array_map('strval', $errors)));
        }
        if ($result === false) return [];
        if (!is_array($result)) throw new \RuntimeException('IMAP search returned an invalid response.');
        return array_values(array_map('intval', $result));
    }

    public static function validateFolderName(string $folder): string
    {
        if ($folder === '' || preg_match('/[\x00-\x1F\x7F{}]/', $folder)) {
            throw new \InvalidArgumentException('Invalid IMAP folder name.');
        }
        return $folder;
    }

    public static function buildUidSearchCriteria(?string $query, ?int $beforeUid = null, ?int $afterUid = null): string
    {
        if ($beforeUid !== null && $afterUid !== null) {
            throw new \InvalidArgumentException('Only one IMAP pagination cursor may be used.');
        }
        $criteria = self::buildSearchCriteria($query);
        if ($beforeUid !== null) {
            if ($beforeUid < 2) throw new \InvalidArgumentException('Invalid older-message cursor.');
            return 'UID 1:' . ($beforeUid - 1) . ' ' . $criteria;
        }
        if ($afterUid !== null) {
            if ($afterUid < 1 || $afterUid === PHP_INT_MAX) throw new \InvalidArgumentException('Invalid newer-message cursor.');
            return 'UID ' . ($afterUid + 1) . ':* ' . $criteria;
        }
        return $criteria;
    }

    public function uidValidity(array $settings, array $credentials, string $folder): int
    {
        $this->requireExtension();
        $mailbox = $this->prefix($settings) . $this->encodeFolder($folder);
        $stream = $this->open($mailbox, $credentials, OP_READONLY);
        try {
            $status = imap_status($stream, $mailbox, SA_UIDVALIDITY);
            if ($status === false || empty($status->uidvalidity)) {
                throw new \RuntimeException('IMAP server did not provide UIDVALIDITY for this folder.');
            }
            return (int)$status->uidvalidity;
        } finally {
            imap_close($stream);
        }
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
                $rawName = (string)$item->name;
                $rawName = self::stripServerPrefix($rawName, $prefix);
                $name = function_exists('imap_utf7_decode') ? imap_utf7_decode($rawName) : $rawName;
                $folders[] = ['name' => $name, 'delimiter' => $item->delimiter ?? '/'];
            }
            return $folders;
        } finally { imap_close($stream); }
    }

    public function listMessages(array $settings, array $credentials, string $folder, int $limit = 50, int $offset = 0, ?string $query = null, ?int $beforeUid = null, ?int $afterUid = null): array
    {
        if ($beforeUid !== null && $afterUid !== null) throw new \InvalidArgumentException('Only one IMAP pagination cursor may be used.');
        if ($beforeUid !== null && $beforeUid < 2) return [];
        $this->requireExtension();
        $stream = $this->open($this->prefix($settings) . $this->encodeFolder($folder), $credentials, OP_READONLY);
        try {
            if ($offset < 0) throw new \InvalidArgumentException('Invalid message offset.');
            $limit = max(1, min(101, $limit));
            $criteria = self::buildUidSearchCriteria($query, $beforeUid, $afterUid);
            imap_errors();
            $uids = imap_search($stream, $criteria, SE_UID, 'UTF-8');
            $uids = self::normalizeSearchResult($uids, imap_errors() ?: []);
            if ($afterUid !== null) {
                sort($uids, SORT_NUMERIC);
                $uids = array_slice($uids, 0, $limit);
                $uids = array_reverse($uids);
            } else {
                rsort($uids, SORT_NUMERIC);
                $uids = array_slice($uids, $offset, $limit);
            }
            if (!$uids) return [];
            imap_errors();
            $overview = imap_fetch_overview($stream, implode(',', $uids), FT_UID);
            $overviewErrors = imap_errors() ?: [];
            if ($overview === false || $overview === []) {
                $detail = $overviewErrors !== [] ? ' ' . implode('; ', array_map('strval', $overviewErrors)) : '';
                throw new \RuntimeException('IMAP returned no summaries for matching message UIDs.' . $detail);
            }
            $byUid = [];
            foreach ($overview ?: [] as $item) {
                $uid = (int)($item->uid ?? 0);
                if ($uid < 1 || !in_array($uid, $uids, true)) continue;
                $byUid[$uid] = [
                    'uid' => $uid,
                    'subject' => $this->decodeHeader((string)($item->subject ?? '(no subject)')),
                    'from' => $this->decodeHeader((string)($item->from ?? '')),
                    'to' => $this->decodeHeader((string)($item->to ?? '')),
                    'date' => (string)($item->date ?? ''),
                    'message_id' => trim((string)($item->message_id ?? '')),
                    'in_reply_to' => trim((string)($item->in_reply_to ?? '')),
                    'references' => trim((string)($item->references ?? '')),
                    'seen' => !empty($item->seen),
                    'flagged' => !empty($item->flagged),
                    'size' => (int)($item->size ?? 0),
                ];
            }
            $ordered = [];
            foreach ($uids as $uid) {
                if (isset($byUid[$uid])) $ordered[] = $byUid[$uid];
            }
            if (!$ordered) throw new \RuntimeException('IMAP summaries did not include any requested UIDs.');
            return $ordered;
        } finally { imap_close($stream); }
    }

    public function getMessage(array $settings, array $credentials, string $folder, int $uid): array
    {
        $this->requireExtension();
        if ($uid < 1) throw new \InvalidArgumentException('Invalid message identifier.');
        $stream = $this->open($this->prefix($settings) . $this->encodeFolder($folder), $credentials, 0);
        try {
            imap_errors();
            $overview = imap_fetch_overview($stream, (string)$uid, FT_UID);
            $overviewErrors = imap_errors() ?: [];
            if ($overview === false && $overviewErrors !== []) {
                throw new \RuntimeException('IMAP message lookup failed: ' . implode('; ', array_map('strval', $overviewErrors)));
            }
            if (!$overview) throw new \RuntimeException('Message not found.');
            $item = $overview[0];
            if ((int)($item->uid ?? 0) !== $uid) throw new \RuntimeException('Message not found.');
            imap_errors();
            $structure = imap_fetchstructure($stream, $uid, FT_UID);
            $structureErrors = imap_errors() ?: [];
            if ($structure === false) {
                $detail = $structureErrors !== [] ? ' ' . implode('; ', array_map('strval', $structureErrors)) : '';
                throw new \RuntimeException('Message MIME structure could not be retrieved.' . $detail);
            }
            self::validateMessageSize((int)($structure->bytes ?? 0));
            $attachments = [];
            $body = $this->extractBody($stream, $uid, $structure, $attachments);
            if (!imap_setflag_full($stream, (string)$uid, '\\Seen', ST_UID)) {
                throw new \RuntimeException('Message could not be marked as read.');
            }
            return [
                'uid' => $uid,
                'subject' => $this->decodeHeader((string)($item->subject ?? '(no subject)')),
                'from' => $this->decodeHeader((string)($item->from ?? '')),
                'to' => $this->decodeHeader((string)($item->to ?? '')),
                'cc' => $this->decodeHeader((string)($item->cc ?? '')),
                'date' => (string)($item->date ?? ''),
                'seen' => true,
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
        if ($uid < 1 || !preg_match('/^\d+(?:\.\d+)*$/', $section)) {
            throw new \InvalidArgumentException('Invalid attachment reference.');
        }
        self::validateFolderName($folder);
        $stream = $this->open($this->prefix($settings) . $this->encodeFolder($folder), $credentials, OP_READONLY);
        try {
            $raw = imap_fetchbody($stream, $uid, $section, FT_UID | FT_PEEK);
            if ($raw === false) throw new \RuntimeException('Attachment data could not be retrieved.');
            $raw = self::decodeTransferEncoding($raw, $encoding);
            if (strlen($raw) > 25_000_000) throw new \RuntimeException('Attachment exceeds the 25 MB download limit.');
            return $raw;
        } finally { imap_close($stream); }
    }

    /**
     * Decode an IMAP body part's transfer encoding. ENCOTHER (5) is retained
     * as-is, as are the 7bit, 8bit, and binary encodings.
     */
    public static function decodeTransferEncoding(string $raw, int $encoding): string
    {
        if (!in_array($encoding, [0, 1, 2, 3, 4, 5], true)) {
            throw new \InvalidArgumentException('Unsupported IMAP transfer encoding.');
        }
        if ($encoding === 3) {
            $decoded = base64_decode($raw, true);
            if ($decoded === false) throw new \RuntimeException('Attachment has invalid base64 encoding.');
            return $decoded;
        }
        return $encoding === 4 ? quoted_printable_decode($raw) : $raw;
    }

    public function appendRawMessage(array $settings, array $credentials, string $folder, string $raw, string $flag): void
    {
        $this->requireExtension();
        self::validateFolderName($folder);
        if (!in_array($flag, ['\Seen', '\Draft'], true)) throw new \InvalidArgumentException('Unsupported IMAP message flag.');
        // A 25 MB binary attachment set expands to roughly 33.4 MB after base64.
        if (strlen($raw) > 40_000_000 || !str_contains($raw, "\r\n\r\n")) throw new \InvalidArgumentException('Invalid or oversized RFC message.');
        $stream = $this->open($this->prefix($settings) . 'INBOX', $credentials, 0);
        try {
            $target = $this->prefix($settings) . $this->encodeFolder($folder);
            if (!imap_append($stream, $target, $raw, $flag)) throw new \RuntimeException('Unable to save message to IMAP folder: ' . $this->lastError());
        } finally { imap_close($stream); }
    }


    public function setFlag(array $settings, array $credentials, string $folder, int $uid, string $flag, bool $enabled): void
    {
        $this->requireExtension();
        self::validateFolderName($folder);
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
        if ($uid < 1) throw new \InvalidArgumentException('Invalid message identifier.');
        self::validateFolderName($source);
        self::validateFolderName($destination);
        $stream = $this->open($this->prefix($settings) . $this->encodeFolder($source), $credentials, 0);
        try {
            if (!imap_mail_move($stream, (string)$uid, $this->encodeFolder($destination), CP_UID)) {
                throw new \RuntimeException('Unable to move message: ' . $this->lastError());
            }
            // Avoid imap_expunge(): it expunges every message already marked
            // Deleted in this mailbox, not just the UID moved by this operation.
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
        self::validateFolderName($folder);
        return function_exists('imap_utf7_encode') ? imap_utf7_encode($folder) : $folder;
    }

    private function extractBody($stream, int $uid, $structure, array &$attachments): string
    {
        if (!$structure) {
            $raw = imap_body($stream, $uid, FT_UID | FT_PEEK);
            if ($raw === false) throw new \RuntimeException('Message body could not be retrieved.');
            return (string)$raw;
        }
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
        if ($raw === false) throw new \RuntimeException('Message body part could not be retrieved.');
        $body = (string)$raw;
        $encoding = (int)($structure->encoding ?? 0);
        if ($encoding === 3) {
            $decoded = base64_decode($body, true);
            if ($decoded === false) throw new \RuntimeException('Message body has invalid base64 encoding.');
            $body = $decoded;
        }
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

    private function normalizeParameters($parameters): array
    {
        if (is_array($parameters)) return $parameters;
        if ($parameters instanceof \Traversable) return array_values(iterator_to_array($parameters));
        if (is_object($parameters)) {
            if (isset($parameters->attribute)) return [$parameters];
            return array_values(get_object_vars($parameters));
        }
        return [];
    }

    private function partFilename($structure): string
    {
        $parameters = array_merge(
            $this->normalizeParameters($structure->parameters ?? []),
            $this->normalizeParameters($structure->dparameters ?? [])
        );
        $values = [];
        $segments = ['filename' => [], 'name' => []];

        foreach ($parameters as $parameter) {
            $attribute = strtolower((string)($parameter->attribute ?? ''));
            $value = (string)($parameter->value ?? '');
            $values[$attribute] = $value;
            if (preg_match('/^(filename|name)\*(\d+)(\*)?$/', $attribute, $match)) {
                $segments[$match[1]][(int)$match[2]] = [
                    'value' => $value,
                    'encoded' => ($match[3] ?? '') === '*',
                ];
            }
        }

        // RFC 2231 continuation parameters (filename*0*, filename*1*, ...)
        // take precedence over single-part and legacy filename parameters.
        foreach (['filename', 'name'] as $base) {
            if (!$segments[$base] || !array_key_exists(0, $segments[$base])) continue;
            ksort($segments[$base], SORT_NUMERIC);
            $indexes = array_keys($segments[$base]);
            if ($indexes !== range(0, count($indexes) - 1)) continue;

            $charset = 'UTF-8';
            $decoded = '';
            foreach ($segments[$base] as $index => $segment) {
                $value = $segment['value'];
                if ($index === 0 && $segment['encoded']
                    && preg_match("/^([^']*)'[^']*'(.*)$/s", $value, $match)) {
                    $charset = $match[1] !== '' ? $match[1] : 'UTF-8';
                    $value = $match[2];
                }
                $decoded .= $segment['encoded'] ? rawurldecode($value) : $value;
            }
            return $this->convertFilenameCharset($decoded, $charset);
        }

        foreach (['filename*', 'name*'] as $wanted) {
            if (!array_key_exists($wanted, $values)) continue;
            $value = $values[$wanted];
            $charset = 'UTF-8';
            if (preg_match("/^([^']*)'[^']*'(.*)$/s", $value, $match)) {
                $charset = $match[1] !== '' ? $match[1] : 'UTF-8';
                $value = $match[2];
            }
            return $this->convertFilenameCharset(rawurldecode($value), $charset);
        }

        foreach (['filename', 'name'] as $wanted) {
            if (array_key_exists($wanted, $values)) return $values[$wanted];
        }
        return '';
    }

    private function convertFilenameCharset(string $value, string $charset): string
    {
        if (strtoupper($charset) === 'UTF-8' || !function_exists('mb_convert_encoding')) return $value;
        try {
            return mb_convert_encoding($value, 'UTF-8', $charset);
        } catch (\Throwable) {
            return $value;
        }
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
