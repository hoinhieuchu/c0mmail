<?php
declare(strict_types=1);
namespace App\Services;

/** Minimal authenticated SMTP client with STARTTLS/implicit TLS and MIME text bodies. */
final class SmtpClient
{
    public function send(array $settings, array $credentials, string $from, array $recipients, string $subject, string $body): void
    {
        $host = (string)($settings['smtp_host'] ?? '');
        $port = (int)($settings['smtp_port'] ?? 587);
        $mode = strtolower((string)($settings['smtp_encryption'] ?? 'starttls'));
        if ($host === '' || !preg_match('/^[a-zA-Z0-9.-]+$/', $host)) throw new \RuntimeException('SMTP host is not configured correctly.');
        if ($port < 1 || $port > 65535) throw new \RuntimeException('Invalid SMTP port.');
        if (!$recipients) throw new \InvalidArgumentException('At least one recipient is required.');
        foreach ($recipients as $recipient) {
            if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Invalid recipient address.');
        }
        $this->assertHeader($from);
        $this->assertHeader($subject);
        $target = ($mode === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $socket = @stream_socket_client($target, $errno, $error, 20, STREAM_CLIENT_CONNECT);
        if (!$socket) throw new \RuntimeException('SMTP connection failed: ' . $error . ' (' . $errno . ').');
        stream_set_timeout($socket, 20);
        try {
            $this->expect($socket, [220]);
            $this->command($socket, 'EHLO c0m.me', [250]);
            if ($mode === 'starttls') {
                $this->command($socket, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new \RuntimeException('SMTP STARTTLS negotiation failed.');
                $this->command($socket, 'EHLO c0m.me', [250]);
            } elseif ($mode !== 'ssl') {
                throw new \RuntimeException('Unsupported SMTP encryption mode.');
            }
            $this->command($socket, 'AUTH LOGIN', [334]);
            $this->command($socket, base64_encode($credentials['email']), [334]);
            $this->command($socket, base64_encode($credentials['password']), [235]);
            $this->command($socket, 'MAIL FROM:<' . $from . '>', [250]);
            foreach (array_unique($recipients) as $recipient) $this->command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
            $this->command($socket, 'DATA', [354]);
            $headers = [
                'From: ' . $from,
                'To: ' . implode(', ', array_unique($recipients)),
                'Subject: ' . $this->encodeHeader($subject),
                'Date: ' . date(DATE_RFC2822),
                'Message-ID: <' . bin2hex(random_bytes(16)) . '@c0m.me>',
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: quoted-printable',
            ];
            $message = implode("\r\n", $headers) . "\r\n\r\n" . quoted_printable_encode(str_replace(["\r\n", "\r"], "\n", $body));
            $message = preg_replace('/(?m)^\./', '..', str_replace(["\r\n", "\r"], "\n", $message));
            fwrite($socket, str_replace("\n", "\r\n", $message) . "\r\n.\r\n");
            $this->expect($socket, [250]);
            $this->command($socket, 'QUIT', [221]);
        } finally { fclose($socket); }
    }

    private function command($socket, string $command, array $expected): string
    {
        fwrite($socket, $command . "\r\n");
        return $this->expect($socket, $expected);
    }

    private function expect($socket, array $expected): string
    {
        $response = '';
        do {
            $line = fgets($socket, 515);
            if ($line === false) throw new \RuntimeException('SMTP server closed the connection unexpectedly.');
            $response .= $line;
        } while (isset($line[3]) && $line[3] === '-');
        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $expected, true)) throw new \RuntimeException('SMTP server rejected the operation (code ' . $code . ').');
        return $response;
    }

    private function assertHeader(string $value): void
    {
        if ($value === '' || preg_match('/[\r\n\0]/', $value)) throw new \InvalidArgumentException('Invalid email header value.');
    }

    private function encodeHeader(string $value): string
    {
        if (function_exists('mb_encode_mimeheader')) return mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n");
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
