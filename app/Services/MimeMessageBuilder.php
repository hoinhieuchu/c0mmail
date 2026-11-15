<?php
declare(strict_types=1);
namespace App\Services;

/** Builds RFC 5322/MIME messages shared by SMTP delivery and IMAP Sent/Drafts copies. */
final class MimeMessageBuilder
{
    public static function build(string $from, array $recipients, string $subject, string $textBody, ?string $htmlBody = null, array $attachments = [], array $threadHeaders = [], ?string $messageId = null): string
    {
        self::assertHeader($from);
        self::assertHeader($subject, true);
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Invalid sender address.');
        if (count($recipients) > 50) throw new \InvalidArgumentException('A maximum of 50 recipients is allowed.');
        foreach ($recipients as $recipient) {
            self::assertHeader((string)$recipient);
            if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Invalid recipient address.');
        }
        if (count($attachments) > 10) throw new \InvalidArgumentException('A maximum of 10 attachments is allowed.');
        $totalBytes = 0;
        foreach ($attachments as $attachment) {
            $filename = (string)($attachment['filename'] ?? '');
            $mime = (string)($attachment['mime'] ?? '');
            $data = (string)($attachment['data'] ?? '');
            self::assertHeader($filename);
            if (strlen($filename) > 255) throw new \InvalidArgumentException('Attachment filename is too long.');
            if ($filename === '' || !preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', $mime)) throw new \InvalidArgumentException('Invalid attachment metadata.');
            $totalBytes += strlen($data);
            if ($totalBytes > 25_000_000) throw new \InvalidArgumentException('Total attachment size exceeds 25 MB.');
        }
        $toHeader = implode(', ', array_unique($recipients));
        if (strlen($toHeader) > 900) throw new \InvalidArgumentException('Recipient header exceeds the allowed length.');
        $messageId ??= '<' . bin2hex(random_bytes(16)) . '@c0m.me>';
        if (!preg_match('/^<[^<>\s@]+@[a-zA-Z0-9.-]+>$/D', $messageId)) {
            throw new \InvalidArgumentException('Invalid Message-ID value.');
        }
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . $from,
            'To: ' . $toHeader,
            'Subject: ' . self::encodeHeader($subject),
            'Message-ID: ' . $messageId,
            'MIME-Version: 1.0',
        ];
        foreach (['in_reply_to' => 'In-Reply-To', 'references' => 'References'] as $key => $headerName) {
            $value = trim((string)($threadHeaders[$key] ?? ''));
            if ($value !== '') {
                self::assertHeader($value);
                if (strlen($value) > 998) throw new \InvalidArgumentException('Thread header exceeds the allowed length.');
                $headers[] = $headerName . ': ' . $value;
            }
        }
        $hasHtml = $htmlBody !== null && trim($htmlBody) !== '';
        if ($attachments) {
            $mixed = 'mixed_' . bin2hex(random_bytes(12));
            $headers[] = 'Content-Type: multipart/mixed; boundary="' . $mixed . '"';
            $body = '--' . $mixed . "\r\n";
            $body .= $hasHtml ? self::alternativePart($textBody, (string)$htmlBody) : self::textPart('text/plain', $textBody);
            foreach ($attachments as $attachment) {
                $filename = basename(str_replace('\\', '/', (string)$attachment['filename']));
                $fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
                if ($fallback === '') $fallback = 'attachment.bin';
                $body .= "\r\n--" . $mixed . "\r\n";
                $body .= 'Content-Type: ' . $attachment['mime'] . "\r\n";
                $body .= 'Content-Disposition: attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($filename) . "\r\n";
                $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
                $body .= rtrim(chunk_split(base64_encode((string)$attachment['data']), 76, "\r\n")) . "\r\n";
            }
            $body .= '--' . $mixed . "--\r\n";
        } elseif ($hasHtml) {
            $boundary = 'alt_' . bin2hex(random_bytes(12));
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
            $body = self::alternativeBody($textBody, (string)$htmlBody, $boundary);
        } else {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: quoted-printable';
            $body = self::quotedPrintable($textBody) . "\r\n";
        }
        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    private static function alternativePart(string $textBody, string $htmlBody): string
    {
        $boundary = 'alt_' . bin2hex(random_bytes(12));
        return 'Content-Type: multipart/alternative; boundary="' . $boundary . '"' . "\r\n\r\n"
            . self::alternativeBody($textBody, $htmlBody, $boundary);
    }

    private static function alternativeBody(string $textBody, string $htmlBody, string $boundary): string
    {
        return '--' . $boundary . "\r\n" . self::textPart('text/plain', $textBody)
            . "\r\n--" . $boundary . "\r\n" . self::textPart('text/html', $htmlBody)
            . "\r\n--" . $boundary . "--\r\n";
    }

    private static function textPart(string $mime, string $body): string
    {
        return 'Content-Type: ' . $mime . "; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            . self::quotedPrintable($body) . "\r\n";
    }

    private static function quotedPrintable(string $body): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $body);
        $encoded = quoted_printable_encode($normalized);
        return str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $encoded));
    }

    private static function encodeHeader(string $value): string
    {
        if (function_exists('mb_encode_mimeheader')) return mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n");
        $chunks = str_split(base64_encode($value), 48);
        return implode("\r\n ", array_map(
            static fn(string $chunk): string => '=?UTF-8?B?' . $chunk . '?=',
            $chunks
        ));
    }

    private static function assertHeader(string $value, bool $allowEmpty = false): void
    {
        if ((!$allowEmpty && $value === '') || preg_match('/[\r\n\0]/', $value)) throw new \InvalidArgumentException('Invalid email header value.');
    }
}
