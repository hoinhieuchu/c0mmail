<?php
declare(strict_types=1);
namespace App\Repositories;

use PDO;

/**
 * Persistent login throttling keyed by HMACs of normalized email and client IP.
 * Raw email addresses and IP addresses are never stored in this table.
 */
final class LoginRateLimitRepository
{
    private const WINDOW_SECONDS = 900;
    private const BLOCK_SECONDS = 900;
    private const EMAIL_LIMIT = 10;
    private const IP_LIMIT = 30;

    public function __construct(private PDO $db, private string $applicationKey)
    {
        if ($this->applicationKey === '') {
            throw new \InvalidArgumentException('Application key is required for login throttling.');
        }
    }

    public function isBlocked(string $email, string $ip): bool
    {
        [$emailHash, $ipHash] = $this->keyHashes($email, $ip);
        $stmt = $this->db->prepare(
            'SELECT 1 FROM login_rate_limits
             WHERE key_hash IN (?, ?) AND blocked_until > UTC_TIMESTAMP()
             LIMIT 1'
        );
        $stmt->execute([$emailHash, $ipHash]);
        return $stmt->fetchColumn() !== false;
    }

    public function recordFailure(string $email, string $ip): void
    {
        [$emailHash, $ipHash] = $this->keyHashes($email, $ip);
        $this->increment($emailHash, self::EMAIL_LIMIT);
        $this->increment($ipHash, self::IP_LIMIT);
    }

    public function clearAccount(string $email): void
    {
        $emailHash = hash_hmac('sha256', 'email:' . strtolower(trim($email)), $this->applicationKey);
        $stmt = $this->db->prepare('DELETE FROM login_rate_limits WHERE key_hash = ?');
        $stmt->execute([$emailHash]);
        $this->db->exec(
            'DELETE FROM login_rate_limits
             WHERE updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)'
        );
    }

    /** @return array{0:string,1:string} */
    private function keyHashes(string $email, string $ip): array
    {
        $email = strtolower(trim($email));
        return [
            hash_hmac('sha256', "email:" . $email, $this->applicationKey),
            hash_hmac('sha256', "ip:" . $ip, $this->applicationKey),
        ];
    }

    private function increment(string $keyHash, int $maxAttempts): void
    {
        $window = self::WINDOW_SECONDS;
        $block = self::BLOCK_SECONDS;
        $sql = "INSERT INTO login_rate_limits
                    (key_hash, attempts, window_started_at, blocked_until, updated_at)
                VALUES (?, 1, UTC_TIMESTAMP(), NULL, UTC_TIMESTAMP())
                ON DUPLICATE KEY UPDATE
                    attempts = IF(
                        window_started_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$window} SECOND),
                        1,
                        attempts + 1
                    ),
                    blocked_until = IF(
                        window_started_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$window} SECOND),
                        NULL,
                        IF(attempts >= {$maxAttempts},
                            DATE_ADD(UTC_TIMESTAMP(), INTERVAL {$block} SECOND),
                            blocked_until
                        )
                    ),
                    window_started_at = IF(
                        window_started_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$window} SECOND),
                        UTC_TIMESTAMP(),
                        window_started_at
                    ),
                    updated_at = UTC_TIMESTAMP()";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$keyHash]);
    }
}
