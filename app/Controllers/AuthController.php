<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Csrf;
use App\Core\View;
use App\Repositories\UserRepository;
use App\Repositories\LoginRateLimitRepository;
use App\Services\ClientIpResolver;
final class AuthController
{
    public function __construct(private UserRepository $users, private LoginRateLimitRepository $loginLimits, private View $view, private array $trustedProxyCidrs = []){}
    public function showLogin():void{$this->view->render('login',['title'=>'Sign in','error'=>null]);}
    public function login():void
    {
        Csrf::verify();
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $ip = ClientIpResolver::resolve($_SERVER, $this->trustedProxyCidrs);
        if ($this->loginLimits->isBlocked($email, $ip)) {
            $this->view->render('login', ['title'=>'Sign in', 'error'=>'Email or password is incorrect.']);
            return;
        }
        $validEmail = strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        $u = $validEmail ? $this->users->findByEmail($email) : null;
        $dummyHash = '$2y$10$92IXUNpkjO0rQO5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        $passwordValid = password_verify($password, (string)($u['password_hash'] ?? $dummyHash));
        if (!$u || $u['status'] !== 'active' || !$passwordValid) {
            $this->loginLimits->recordFailure($email, $ip);
            $this->view->render('login', ['title'=>'Sign in', 'error'=>'Email or password is incorrect.']);
            return;
        }
        $this->loginLimits->clearAccount($email);
        session_regenerate_id(true);
        $_SESSION = [
            '_csrf' => bin2hex(random_bytes(32)),
            'user_id' => (int)$u['id'],
            'auth_version' => (int)$u['auth_version'],
            'auth_created_at' => time(),
            'auth_last_activity' => time(),
        ];
        header('Location: /dashboard');
        exit;
    }
    public function logout():void
    {
        Csrf::verify();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        header('Location: /login');
        exit;
    }
}
