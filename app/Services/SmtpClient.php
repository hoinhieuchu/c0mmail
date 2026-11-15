<?php
declare(strict_types=1);
namespace App\Services;

/** Authenticated SMTP client with mandatory TLS and RFC MIME message delivery. */
final class SmtpClient
{
    public function send(array $settings, array $credentials, string $from, array $recipients, string $subject, string $body, ?string $htmlBody = null, array $attachments = [], array $threadHeaders = []): string
    {
        $host = (string)($settings['smtp_host'] ?? '');
        $port = (int)($settings['smtp_port'] ?? 587);
        $mode = strtolower((string)($settings['smtp_encryption'] ?? 'starttls'));
        if ($host === '' || !preg_match('/^[a-zA-Z0-9.-]+$/', $host)) throw new \RuntimeException('SMTP host is not configured correctly.');
        if ($port < 1 || $port > 65535) throw new \RuntimeException('Invalid SMTP port.');
        if (!in_array($mode, ['ssl', 'starttls'], true)) throw new \RuntimeException('SMTP connections must use SSL or STARTTLS.');
        if (!$recipients) throw new \InvalidArgumentException('At least one recipient is required.');
        $message = MimeMessageBuilder::build($from, array_values(array_unique($recipients)), $subject, $body, $htmlBody, $attachments, $threadHeaders);
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
            $wireMessage = preg_replace('/(?m)^\./', '..', str_replace(["\r\n", "\r"], "\n", $message));
            fwrite($socket, str_replace("\n", "\r\n", $wireMessage) . "\r\n.\r\n");
            $this->expect($socket, [250]);
            $this->command($socket, 'QUIT', [221]);
            return $message;
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

}
