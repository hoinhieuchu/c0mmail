<?php
declare(strict_types=1);
namespace App\Services;

/**
 * Classifies SMTP submission states without mutating persistent state.
 * A stale in-flight state is uncertain, never automatically retryable.
 */
final class SubmissionState
{
    public const STALE_AFTER_SECONDS = 900;

    public static function classify(array $submission, ?int $now = null): string
    {
        $status = (string)($submission['status'] ?? '');
        if ($status === 'sending') {
            $startedAt = (int)($submission['started_at'] ?? 0);
            $now ??= time();
            if ($startedAt < 1 || ($now - $startedAt) > self::STALE_AFTER_SECONDS) {
                return 'uncertain';
            }
            return 'sending';
        }

        return in_array($status, ['pending', 'accepted'], true) ? $status : 'uncertain';
    }
}
