<?php
declare(strict_types=1);
namespace App\Services;

/** Resolves a client address without trusting spoofable forwarding headers. */
final class ClientIpResolver
{
    public static function resolve(array $server, array $trustedProxyCidrs = []): string
    {
        $remote = filter_var($server['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP);
        if ($remote === false) return 'unknown';

        $trusted = false;
        foreach ($trustedProxyCidrs as $cidr) {
            if (self::matchesCidr($remote, (string)$cidr)) {
                $trusted = true;
                break;
            }
        }
        if (!$trusted) return $remote;

        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP'] as $header) {
            $candidate = filter_var(trim((string)($server[$header] ?? '')), FILTER_VALIDATE_IP);
            if ($candidate !== false) return $candidate;
        }
        return $remote;
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
