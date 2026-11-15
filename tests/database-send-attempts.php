<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Repositories\SendAttemptRepository;
use App\Repositories\LoginRateLimitRepository;

$host = getenv('DB_HOST') ?: '127.0.0.1';
$database = getenv('DB_DATABASE') ?: 'c0mmail_test';
$user = getenv('DB_USERNAME') ?: 'root';
$password = getenv('DB_PASSWORD') ?: 'root';
$db = new PDO(
    "mysql:host={$host};port=3306;dbname={$database};charset=utf8mb4",
    $user,
    $password,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]
);

foreach (['001_initial_schema.sql', '002_mail_send_attempts.sql', '003_send_attempt_failed_state.sql', '004_login_rate_limits.sql'] as $migration) {
    $sql = file_get_contents(dirname(__DIR__) . '/database/migrations/' . $migration);
    if ($sql === false) throw new RuntimeException('Unable to read migration ' . $migration);
    foreach (explode(';', $sql) as $statement) {
        if (trim($statement) !== '') $db->exec($statement);
    }
}

$suffix = bin2hex(random_bytes(5));
$insertUser = $db->prepare("INSERT INTO users (email,password_hash,display_name) VALUES (?, 'test-hash', 'CI Test')");
$insertUser->execute(["send-attempt-{$suffix}@example.test"]);
$userId = (int)$db->lastInsertId();
$insertMailbox = $db->prepare("INSERT INTO mailboxes (user_id,email,display_name,provider,status) VALUES (?, ?, '', 'manual', 'active')");
$insertMailbox->execute([$userId, "mailbox-{$suffix}@example.test"]);
$mailboxId = (int)$db->lastInsertId();
$repo = new SendAttemptRepository($db);
$loginLimits = new LoginRateLimitRepository($db, 'ci-login-rate-limit-secret');
$check = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS {$message}\n";
};

try {
    for ($i = 0; $i < 10; $i++) $loginLimits->recordFailure('blocked@example.test', '192.0.2.10');
    $check($loginLimits->isBlocked('blocked@example.test', '192.0.2.10'), 'repeated password failures block the account key');
    $loginLimits->clear('blocked@example.test', '192.0.2.10');
    $check(!$loginLimits->isBlocked('blocked@example.test', '192.0.2.10'), 'successful authentication clears the account throttle');

    for ($i = 0; $i < 20; $i++) $loginLimits->recordFailure('other-' . $i . '@example.test', '192.0.2.10');
    $check($loginLimits->isBlocked('other-final@example.test', '192.0.2.10'), 'successful login does not erase the shared IP failure counter');

    $token = bin2hex(random_bytes(16));
    $hash = hash('sha256', 'stable payload');
    $messageId = "<{$token}@c0m.me>";
    $repo->createPending($token, $userId, $mailboxId);
    $first = $repo->claim($token, $userId, $mailboxId, $hash, $messageId);
    $check(($first['status'] ?? '') === 'sending' && !empty($first['claimed']), 'atomic first claim owns the send attempt');

    $duplicate = $repo->claim($token, $userId, $mailboxId, $hash, $messageId);
    $check(($duplicate['status'] ?? '') === 'sending' && empty($duplicate['claimed']), 'concurrent duplicate cannot claim an in-flight send');

    try {
        $repo->claim($token, $userId, $mailboxId, hash('sha256', 'changed payload'), $messageId);
        throw new RuntimeException('Changed payload was accepted for an existing token.');
    } catch (InvalidArgumentException $e) {
        $check(str_contains($e->getMessage(), 'different message content'), 'token cannot be reused with changed payload');
    }

    $failedToken = bin2hex(random_bytes(16));
    $failedHash = hash('sha256', 'retryable payload');
    $repo->createPending($failedToken, $userId, $mailboxId);
    $repo->claim($failedToken, $userId, $mailboxId, $failedHash, "<{$failedToken}@c0m.me>");
    $repo->markFailed($failedToken, $userId, $mailboxId);
    try {
        $repo->claim($failedToken, $userId, $mailboxId, hash('sha256', 'tampered retry'), "<{$failedToken}@c0m.me>");
        throw new RuntimeException('Changed payload was accepted for a failed token.');
    } catch (InvalidArgumentException $e) {
        $check(str_contains($e->getMessage(), 'different message content'), 'failed token cannot be retried with changed payload');
    }
    $safeRetry = $repo->claim($failedToken, $userId, $mailboxId, $failedHash, "<{$failedToken}@c0m.me>");
    $check(($safeRetry['status'] ?? '') === 'sending' && !empty($safeRetry['claimed']), 'definitive pre-DATA failure permits a safe retry with the same token');

    $repo->markUncertain($token, $userId, $mailboxId);
    $uncertain = $repo->claim($token, $userId, $mailboxId, $hash, $messageId);
    $check(($uncertain['status'] ?? '') === 'uncertain', 'uncertain delivery is never automatically retried');

    $acceptedToken = bin2hex(random_bytes(16));
    $acceptedHash = hash('sha256', 'accepted payload');
    $repo->createPending($acceptedToken, $userId, $mailboxId);
    $repo->claim($acceptedToken, $userId, $mailboxId, $acceptedHash, "<{$acceptedToken}@c0m.me>");
    $repo->markAccepted($acceptedToken, $userId, $mailboxId);
    $repo->markSentCopy($acceptedToken, $userId, $mailboxId, 'Sent', 'saved');
    $accepted = $repo->find($acceptedToken, $userId, $mailboxId);
    $check(($accepted['status'] ?? '') === 'accepted'
        && ($accepted['sent_folder'] ?? '') === 'Sent'
        && ($accepted['sent_copy_status'] ?? '') === 'saved', 'SMTP acceptance and Sent-copy result persist independently');

    $staleToken = bin2hex(random_bytes(16));
    $staleHash = hash('sha256', 'stale payload');
    $repo->createPending($staleToken, $userId, $mailboxId);
    $repo->claim($staleToken, $userId, $mailboxId, $staleHash, "<{$staleToken}@c0m.me>");
    $staleUpdate = $db->prepare("UPDATE mail_send_attempts SET updated_at=DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 16 MINUTE) WHERE submission_id=?");
    $staleUpdate->execute([$staleToken]);
    $stale = $repo->claim($staleToken, $userId, $mailboxId, $staleHash, "<{$staleToken}@c0m.me>");
    $check(($stale['status'] ?? '') === 'uncertain', 'stale in-flight attempt transitions to uncertain after fifteen minutes');

    $expiredToken = bin2hex(random_bytes(16));
    $repo->createPending($expiredToken, $userId, $mailboxId);
    $expire = $db->prepare("UPDATE mail_send_attempts SET created_at=DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 2 DAY) WHERE submission_id=?");
    $expire->execute([$expiredToken]);
    $repo->createPending(bin2hex(random_bytes(16)), $userId, $mailboxId);
    $check($repo->find($expiredToken, $userId, $mailboxId) === null, 'old send-attempt records are purged during compose-token creation');
} finally {
    $db->prepare('DELETE FROM mail_send_attempts WHERE user_id=?')->execute([$userId]);
    $db->exec('DELETE FROM login_rate_limits');
    $db->prepare('DELETE FROM mailboxes WHERE user_id=?')->execute([$userId]);
    $db->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
}

echo "Database send-attempt integration checks passed.\n";
