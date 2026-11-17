<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Repositories\UserRepository;
use App\Services\PasswordPolicy;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (($argc !== 2 && $argc !== 3) || !filter_var($argv[1] ?? '', FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php bin/reset-password.php user@example.com [test-only-password]\n");
    exit(1);
}

$config = Config::load(dirname(__DIR__) . '/.env');
if ($argc === 3) {
    if (strtolower((string)$config->get('APP_ENV', 'production')) !== 'testing') {
        fwrite(STDERR, "Password arguments are disabled outside APP_ENV=testing. Omit the argument and enter the password at the hidden prompt.\n");
        exit(1);
    }
    $password = (string)$argv[2];
} else {
    fwrite(STDOUT, "New account password (minimum 14 characters): ");
    $terminalState = null;
    if (PHP_OS_FAMILY !== 'Windows' && function_exists('stream_isatty') && stream_isatty(STDIN)) {
        if (function_exists('shell_exec')) {
            $state = trim((string)@shell_exec('stty -g 2>/dev/null'));
            if ($state !== '') {
                $terminalState = $state;
                @shell_exec('stty -echo');
                register_shutdown_function(static function () use (&$terminalState): void {
                    if ($terminalState !== null) {
                        @shell_exec('stty ' . escapeshellarg($terminalState));
                        $terminalState = null;
                    }
                });
            } else {
                fwrite(STDERR, "\nWarning: terminal echo could not be disabled; use a secure input method.\n");
            }
        } else {
            fwrite(STDERR, "\nWarning: shell_exec is disabled; password input may be echoed. Use a secure input method.\n");
        }
    } elseif (PHP_OS_FAMILY !== 'Windows') {
        // Piped stdin does not echo input to a terminal.
    } else {
        fwrite(STDERR, "\nWarning: password input may be echoed by this terminal.\n");
    }

    $input = fgets(STDIN);
    if ($terminalState !== null) {
        @shell_exec('stty ' . escapeshellarg($terminalState));
        $terminalState = null;
        fwrite(STDOUT, "\n");
    }
    $password = $input === false ? '' : rtrim($input, "\r\n");
}

$error = (new PasswordPolicy())->validate($password);
if ($error !== null) {
    fwrite(STDERR, $error . "\n");
    exit(1);
}

try {
    $users = new UserRepository(Database::connect($config));
    $account = $users->findByEmail(strtolower($argv[1]));
    if ($account === null || ($account['status'] ?? '') !== 'active') {
        fwrite(STDERR, "Active account not found.\n");
        exit(1);
    }

    $version = $users->resetPasswordHash(
        (int)$account['id'],
        password_hash($password, PASSWORD_DEFAULT)
    );
    if ($version === null) {
        fwrite(STDERR, "Account is no longer active. No password was changed.\n");
        exit(1);
    }

    fwrite(STDOUT, "Password reset successfully. Existing sessions have been revoked.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "Password reset failed. Check the application configuration and server logs.\n");
    exit(1);
}
