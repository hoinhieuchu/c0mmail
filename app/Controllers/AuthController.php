<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Csrf;
use App\Core\View;
use App\Repositories\UserRepository;
final class AuthController
{
    public function __construct(private UserRepository $users,private View $view){}
    public function showLogin():void{$this->view->render('login',['title'=>'Sign in','error'=>null]);}
    public function login():void
    {
        Csrf::verify();$email=strtolower(trim((string)($_POST['email']??'')));$password=(string)($_POST['password']??'');$u=$this->users->findByEmail($email);
        if(!$u||$u['status']!=='active'||!password_verify($password,$u['password_hash'])){$this->view->render('login',['title'=>'Sign in','error'=>'Email or password is incorrect.']);return;}
        session_regenerate_id(true);
        $_SESSION = [
            '_csrf' => bin2hex(random_bytes(32)),
            'user_id' => (int)$u['id'],
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
