<?php
declare(strict_types=1);
namespace App\Repositories;

use PDO;
use InvalidArgumentException;
use RuntimeException;

final class SendAttemptRepository
{
    public function __construct(private PDO $db) {}

    public function createPending(string $submissionId, int $userId, int $mailboxId): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $submissionId) || $userId < 1 || $mailboxId < 1) {
            throw new InvalidArgumentException('Invalid send-attempt identity.');
        }
        $this->db->exec("DELETE FROM mail_send_attempts WHERE created_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 30 DAY) OR (status='pending' AND created_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 DAY))");
        $stmt = $this->db->prepare(
            "INSERT INTO mail_send_attempts (submission_id, user_id, mailbox_id, status) VALUES (?, ?, ?, 'pending')"
        );
        $stmt->execute([$submissionId, $userId, $mailboxId]);
    }

    public function claim(string $submissionId, int $userId, int $mailboxId, string $payloadHash, string $messageId): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $submissionId)
            || !preg_match('/^[a-f0-9]{64}$/', $payloadHash)
            || !preg_match('/^<[a-f0-9]{32}@c0m\\.me>$/', $messageId)) {
            throw new InvalidArgumentException('Invalid send-attempt token or payload fingerprint.');
        }

        // A crashed worker must never make an old SMTP attempt retryable. Once
        // its lease expires, the outcome is unknown until the operator checks Sent.
        $stale = $this->db->prepare(
            "UPDATE mail_send_attempts SET status='uncertain'
             WHERE submission_id=? AND user_id=? AND mailbox_id=? AND status='sending'
               AND updated_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE)"
        );
        $stale->execute([$submissionId, $userId, $mailboxId]);

        // Pending tokens are claimed once. Failed tokens may be reclaimed only
        // when the exact original payload fingerprint and Message-ID still match.
        $claim = $this->db->prepare(
            "UPDATE mail_send_attempts
             SET status='sending', payload_hash=?, message_id=?, updated_at=CURRENT_TIMESTAMP
             WHERE submission_id=? AND user_id=? AND mailbox_id=? AND status='pending'"
        );
        $claim->execute([$payloadHash, $messageId, $submissionId, $userId, $mailboxId]);
        if ($claim->rowCount() === 1) {
            return ['status'=>'sending', 'claimed'=>true, 'payload_hash'=>$payloadHash, 'message_id'=>$messageId];
        }

        $attempt = $this->find($submissionId, $userId, $mailboxId);
        if ($attempt === null) {
            throw new InvalidArgumentException('This compose form has expired. Reload Webmail before sending.');
        }
        if (!empty($attempt['payload_hash']) && !hash_equals((string)$attempt['payload_hash'], $payloadHash)) {
            throw new InvalidArgumentException('This send token was already used for different message content. Reload Webmail before sending.');
        }

        if (($attempt['status'] ?? '') === 'failed') {
            $retry = $this->db->prepare(
                "UPDATE mail_send_attempts SET status='sending', updated_at=CURRENT_TIMESTAMP
                 WHERE submission_id=? AND user_id=? AND mailbox_id=? AND status='failed'
                   AND payload_hash=? AND message_id=?"
            );
            $retry->execute([$submissionId, $userId, $mailboxId, $payloadHash, $messageId]);
            if ($retry->rowCount() === 1) {
                return ['status'=>'sending', 'claimed'=>true, 'payload_hash'=>$payloadHash, 'message_id'=>$messageId];
            }
            $attempt = $this->find($submissionId, $userId, $mailboxId) ?? $attempt;
        }
        return $attempt;
    }

    public function find(string $submissionId, int $userId, int $mailboxId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT submission_id, user_id, mailbox_id, payload_hash, status, message_id, sent_folder, sent_copy_status, created_at, updated_at
             FROM mail_send_attempts WHERE submission_id=? AND user_id=? AND mailbox_id=? LIMIT 1'
        );
        $stmt->execute([$submissionId, $userId, $mailboxId]);
        return $stmt->fetch() ?: null;
    }

    public function markAccepted(string $submissionId, int $userId, int $mailboxId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE mail_send_attempts SET status='accepted', sent_copy_status='pending', updated_at=CURRENT_TIMESTAMP
             WHERE submission_id=? AND user_id=? AND mailbox_id=? AND status='sending'"
        );
        $stmt->execute([$submissionId, $userId, $mailboxId]);
        if ($stmt->rowCount() !== 1) {
            $attempt = $this->find($submissionId, $userId, $mailboxId);
            if (($attempt['status'] ?? '') !== 'accepted') {
                throw new RuntimeException('Unable to persist SMTP acceptance state.');
            }
        }
    }

    public function markFailed(string $submissionId, int $userId, int $mailboxId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE mail_send_attempts SET status='failed', updated_at=CURRENT_TIMESTAMP
             WHERE submission_id=? AND user_id=? AND mailbox_id=? AND status='sending'"
        );
        $stmt->execute([$submissionId, $userId, $mailboxId]);
    }

    public function markUncertain(string $submissionId, int $userId, int $mailboxId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE mail_send_attempts SET status='uncertain', updated_at=CURRENT_TIMESTAMP
             WHERE submission_id=? AND user_id=? AND mailbox_id=? AND status='sending'"
        );
        $stmt->execute([$submissionId, $userId, $mailboxId]);
    }

    public function markSentCopy(string $submissionId, int $userId, int $mailboxId, ?string $folder, string $status): void
    {
        if (!in_array($status, ['saved','failed','unavailable'], true)) {
            throw new InvalidArgumentException('Invalid Sent-copy status.');
        }
        $stmt = $this->db->prepare(
            "UPDATE mail_send_attempts SET sent_folder=?, sent_copy_status=?, updated_at=CURRENT_TIMESTAMP
             WHERE submission_id=? AND user_id=? AND mailbox_id=? AND status='accepted'"
        );
        $stmt->execute([$folder, $status, $submissionId, $userId, $mailboxId]);
    }
}
