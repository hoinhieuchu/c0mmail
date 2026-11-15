<?php
declare(strict_types=1);
namespace App\Providers;
use App\Contracts\MemberProviderInterface;
use App\Repositories\UserRepository;
final class LocalSessionMemberProvider implements MemberProviderInterface
{
    public function __construct(private UserRepository $users){}
    public function currentMember(): ?array
    {
        $id = $_SESSION['user_id'] ?? null;
        if (!$id) return null;

        $now = time();
        $createdAt = (int)($_SESSION['auth_created_at'] ?? 0);
        $lastActivity = (int)($_SESSION['auth_last_activity'] ?? 0);
        $idleTimeout = 1800;
        $absoluteTimeout = 43200;

        if ($createdAt < 1 || $lastActivity < 1
            || ($now - $lastActivity) > $idleTimeout
            || ($now - $createdAt) > $absoluteTimeout) {
            unset($_SESSION['user_id'], $_SESSION['auth_created_at'], $_SESSION['auth_last_activity']);
            return null;
        }

        $_SESSION['auth_last_activity'] = $now;
        $user = $this->users->findById((int)$id);
        if (!$user || $user['status'] !== 'active') {
            unset($_SESSION['user_id'], $_SESSION['auth_created_at'], $_SESSION['auth_last_activity']);
            return null;
        }
        return $user;
    }
}
