<?php
declare(strict_types=1);
namespace App\Services;

final class PasswordPolicy
{
    public function validate(string $password): ?string
    {
        if (str_contains($password, "\0")) return 'Password contains an unsupported null character.';
        if (strlen($password) > 1024) return 'Password must not exceed 1024 bytes.';
        $length = preg_match_all('/./us', $password, $matches);
        if ($length === false) $length = strlen($password);
        if ($length < 14) return 'Password must be at least 14 characters long.';
        return null;
    }
}
