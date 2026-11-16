<?php
declare(strict_types=1);
namespace App\\Services;

/** Resolves client addresses and proxy-supplied transport metadata safely. */
final class ClientIpResolver
{
    public static function resolve(array $server, array $trustedProxyCidrs = []): string
    {
        $remote = filter_var($server['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP);
        if ($remote === false) return 'unknown';
        if (!self::isTrustedProxy($remote, $trustedProxyCidrs)) return $remote;

        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP'] as $header) {
            $candidate = filter_var(trim((string)($server[$header] ?? '')), FILTER_VALIDATE_IP);
            if ($candidate !== false) return $candidate;
        }
        return $remote;
    }

    public static function isSecureRequest(array $server, array $trustedProxyCidrs = []): bool
    {
        $https = strtolower(trim((string)($server['HTTPS'] ?? '')));
        if ($https !== '' && $https !== 'off' && $https !== '0') return true;

        $remote = filter_var($server['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP);
        if ($remote === false || !self::isTrustedProxy($remote, $trustedProxyCidrs)) return false;

        $forwardedProto = strtolower(trim(explode(',', (string)($server['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        if ($forwardedProto === 'https') return true;

        $cloudflareVisitor = json_decode((string)($server['HTTP_CF_VISITOR'] ?? ''), true);
        return is_array($cloudflareVisitor)
            && strtolower((string)($cloudflareVisitor['scheme'] ?? '')) === 'https';
    }

    private static function isTrustedProxy(string $remote, array $trustedProxyCidrs): bool
    {
        foreach ($trustedProxyCidrs as $cidr) {
            if (self::matchesCidr($remote, (string)$cidr)) return true;
        }
        return false;
    }

    private static function matchesCidr(string $ip, string $cidr): bool
    {
        $parts = explode('/', trim($cidr), 2);
        $network = inet_pton($parts[0]);
        $address = inet_pton($ip);
        if ($network === false || $address === false || strlen($network) !== strlen($address)) return false;

        $maxBits = strlen($network) * 8;
        $prefix = isset($parts[1]) && $parts[1] !== '' ? filter_var($parts[1], FILTER_VALIDATE_INT) : $maxBits;
        if ($prefix === false || $prefix < 0 || $prefix > $maxBits) return false;

        $wholeBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;
        if ($wholeBytes > 0 && substr($network, 0, $wholeBytes) !== substr($address, 0, $wholeBytes)) return false;
        if ($remainingBits === 0) return true;

        $mask = (0xff << (8 - $remainingBits)) & 0xff;
        return (ord($network[$wholeBytes]) & $mask) === (ord($address[$wholeBytes]) & $mask);
    }
}
