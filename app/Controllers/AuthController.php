<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Csrf;
use App\Core\View;
use App\Repositories\UserRepository;
use App\Services\LoginRateLimiter;
final class AuthController
{
    private const DUMMY_PASSWORD_HASH='$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
    public function __construct(private UserRepository $users,private View $view,private LoginRateLimiter $rateLimiter){}
    public function showLogin():void{$this->view->render('login',['title'=>'Sign in','error'=>null]);}
    public function login():void
    {
        Csrf::verify();$email=strtolower(trim((string)($_POST['email']??'')));$password=(string)($_POST['password']??'');$ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');
        if($this->rateLimiter->isBlocked($email,$ip)){$this->view->render('login',['title'=>'Sign in','error'=>'Email or password is incorrect.']);return;}
        $u=$this->users->findByEmail($email);$passwordHash=$u['password_hash']??self::DUMMY_PASSWORD_HASH;$passwordValid=password_verify($password,$passwordHash);
        if(!$u||$u['status']!=='active'||!$passwordValid){$this->rateLimiter->recordFailure($email,$ip);$this->view->render('login',['title'=>'Sign in','error'=>'Email or password is incorrect.']);return;}
        $this->rateLimiter->recordSuccess($email);
        session_regenerate_id(true);$_SESSION['user_id']=(int)$u['id'];header('Location: /dashboard');exit;
    }
    public function logout():void{Csrf::verify();$_SESSION=[];session_regenerate_id(true);header('Location: /login');exit;}
}
