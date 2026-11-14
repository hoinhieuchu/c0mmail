<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\View;
use App\Repositories\UserRepository;
use App\Services\PasswordPolicy;

final class AdminMemberController
{
    public function __construct(private Auth $auth,private UserRepository $users,private View $view,private PasswordPolicy $policy){}
    public function index():void
    {
        $admin=$this->auth->requireAdmin();$notice=$_SESSION['member_notice']??null;unset($_SESSION['member_notice']);
        $this->view->render('admin/members',['title'=>'Member administration','user'=>$admin,'members'=>$this->users->allAccounts(),'error'=>null,'notice'=>$notice]);
    }
    public function create():void
    {
        $admin=$this->auth->requireAdmin();Csrf::verify();$email=strtolower(trim((string)($_POST['email']??'')));$name=trim((string)($_POST['display_name']??''));$password=(string)($_POST['password']??'');
        try{
            if(strlen($email)>254||!filter_var($email,FILTER_VALIDATE_EMAIL))throw new \InvalidArgumentException('Enter a valid email address.');
            if($name===''||mb_strlen($name)>120)throw new \InvalidArgumentException('Display name is required and must be 120 characters or fewer.');
            if(($error=$this->policy->validate($password))!==null)throw new \InvalidArgumentException($error);
            $this->users->create($email,$name,password_hash($password,PASSWORD_DEFAULT),'member');
            $_SESSION['member_notice']='Member account created.';
        }catch(\PDOException $e){$_SESSION['member_notice']=$e->getCode()==='23000'?'That email address is already registered.':'Unable to create the member account.';}
        catch(\Throwable $e){$_SESSION['member_notice']=$e->getMessage();}
        header('Location: /admin/members');exit;
    }
    public function updateStatus(int $id):void
    {
        $admin=$this->auth->requireAdmin();Csrf::verify();$status=(string)($_POST['status']??'');
        if($id===(int)$admin['id']){http_response_code(422);exit('You cannot change your own account status here.');}
        $member=$this->users->findById($id);
        if(!$member||$member['role']!=='member'){http_response_code(404);exit('Member not found.');}
        if(!in_array($status,['active','suspended'],true))throw new \InvalidArgumentException('Invalid member status.');
        $this->users->setMemberStatus($id,$status);$_SESSION['member_notice']=$status==='active'?'Member account activated.':'Member account suspended.';
        header('Location: /admin/members');exit;
    }
}
