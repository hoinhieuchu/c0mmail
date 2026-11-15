<?php
declare(strict_types=1);
namespace App\Services;

/**
 * Thin IMAP adapter. Requires PHP's IMAP extension; it never stores credentials.
 */
final class ImapClient
{
    public function available(): bool { return function_exists('imap_open'); }

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

    public function listMessages(array $settings, array $credentials, string $folder, int $limit = 50): array
    {
        $this->requireExtension();
        $stream = $this->open($this->prefix($settings) . $this->encodeFolder($folder), $credentials, OP_READONLY);
        try {
            $uids = imap_search($stream, 'ALL', SE_UID);
            if ($uids === false) return [];
            rsort($uids, SORT_NUMERIC);
            $uids = array_slice($uids, 0, max(1, min(100, $limit)));
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
            $body = $this->extractBody($stream, $uid, $structure);
            imap_setflag_full($stream, (string)$uid, '\\Seen', ST_UID);
            return [
                'uid' => $uid,
                'subject' => $this->decodeHeader((string)($item->subject ?? '(no subject)')),
                'from' => $this->decodeHeader((string)($item->from ?? '')),
                'to' => $this->decodeHeader((string)($item->to ?? '')),
                'date' => (string)($item->date ?? ''),
                'body' => $body,
            ];
        } finally { imap_close($stream); }
    }


    public function appendTextMessage(array $settings, array $credentials, string $folder, string $from, array $recipients, string $subject, string $body, string $flag): void
    {
        $this->requireExtension();
        if (!in_array($flag, ['\\\\Seen', '\\\\Draft'], true)) throw new \\InvalidArgumentException('Unsupported IMAP message flag.');
        foreach ([$from, $subject] as $header) if (preg_match('/[\r\n\0]/', $header)) throw new \\InvalidArgumentException('Invalid message header.');
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) throw new \\InvalidArgumentException('Invalid sender address.');
        foreach ($recipients as $recipient) if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) throw new \\InvalidArgumentException('Invalid recipient address.');
        $stream = $this->open($this->prefix($settings) . 'INBOX', $credentials, 0);
        try {
            $headers = [
                'Date: ' . date(DATE_RFC2822),
                'From: ' . $from,
                'To: ' . implode(', ', $recipients),
                'Subject: ' . (function_exists('mb_encode_mimeheader') ? mb_encode_mimeheader($subject, 'UTF-8', 'B') : '=?UTF-8?B?' . base64_encode($subject) . '?='),
                'Message-ID: <' . bin2hex(random_bytes(16)) . '@c0m.me>',
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: quoted-printable',
            ];
            $raw = implode("\\r\\n", $headers) . "\\r\\n\\r\\n" . quoted_printable_encode(str_replace(["\\r\\n", "\\r"], "\\n", $body)) . "\\r\\n";
            $target = $this->prefix($settings) . $this->encodeFolder($folder);
            if (!imap_append($stream, $target, $raw, $flag)) throw new \\RuntimeException('Unable to save message to IMAP folder: ' . $this->lastError());
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
        $flags = $encryption === 'ssl' ? '/imap/ssl' : ($encryption === 'starttls' ? '/imap/tls' : '/imap');
        return '{' . $host . ':' . $port . $flags . '}';
    }

    private function encodeFolder(string $folder): string
    {
        if ($folder === '' || str_contains($folder, "\0")) throw new \InvalidArgumentException('Invalid folder name.');
        return function_exists('imap_utf7_encode') ? imap_utf7_encode($folder) : $folder;
    }

    private function extractBody($stream, int $uid, $structure): string
    {
        if (!$structure) return (string)imap_body($stream, $uid, FT_UID | FT_PEEK);
        $parts = [];
        $this->collectParts($stream, $uid, $structure, '', $parts);
        foreach (['plain', 'html'] as $wanted) {
            foreach ($parts as $part) if ($part['type'] === $wanted) return $part['body'];
        }
        return '';
    }

    private function collectParts($stream, int $uid, $structure, string $section, array &$parts): void
    {
        if (!empty($structure->parts)) {
            foreach ($structure->parts as $index => $part) {
                $partSection = $section === '' ? (string)($index + 1) : $section . '.' . ($index + 1);
                $this->collectParts($stream, $uid, $part, $partSection, $parts);
            }
            return;
        }
        $subtype = strtolower((string)($structure->subtype ?? 'plain'));
        $type = (int)($structure->type ?? 0);
        if ($type !== TYPETEXT || !in_array($subtype, ['plain', 'html'], true)) return;
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
