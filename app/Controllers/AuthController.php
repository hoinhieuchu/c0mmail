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
        session_regenerate_id(true);$_SESSION['user_id']=(int)$u['id'];header('Location: /dashboard');exit;
    }
    public function logout():void{Csrf::verify();$_SESSION=[];session_regenerate_id(true);header('Location: /login');exit;}
}
