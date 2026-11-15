<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\View;
use App\Repositories\UserRepository;
use App\Repositories\MailboxRepository;
use App\Services\MailboxService;
final class AdminMailboxController
{
    public function __construct(private Auth $auth,private UserRepository $users,private MailboxRepository $mailboxes,private MailboxService $service,private View $view,private string $providerName,private string $mailDomain){}
    public function index():void
    {
        $this->auth->requireAdmin();$created=$_SESSION['created_mailbox']??null;unset($_SESSION['created_mailbox']);
        $this->view->render('admin/mailboxes',['title'=>'Mailbox administration','members'=>$this->users->allMembers(),'mailboxes'=>$this->mailboxes->all(),'error'=>null,'created'=>$created,'providerName'=>$this->providerName]);
    }
    public function create():void
    {
        $this->auth->requireAdmin();Csrf::verify();
        try {
            $local=trim((string)($_POST['local_part']??''));$name=trim((string)($_POST['display_name']??''));$password=(string)($_POST['password']??'');
            if(!preg_match('/^[a-zA-Z0-9._+-]{1,64}$/',$local))throw new \InvalidArgumentException('Local part contains unsupported characters.');
            if(strlen($password)<12)throw new \InvalidArgumentException('Mailbox password must be at least 12 characters.');
            $id=$this->service->create(['user_id'=>(int)($_POST['user_id']??0),'local_part'=>$local,'display_name'=>$name,'password'=>$password]);
            $_SESSION['created_mailbox']=['id'=>$id,'email'=>$local.'@'.$this->mailDomain,'password'=>$password];
            header('Location: /admin/mailboxes');exit;
        } catch (\Throwable $e) {
            error_log('Mailbox registration failed: ' . get_class($e));
            $message = $e instanceof \InvalidArgumentException
                ? $e->getMessage()
                : 'Mailbox registration could not be completed. Check provider settings and application logs.';
            $this->view->render('admin/mailboxes',['title'=>'Mailbox administration','members'=>$this->users->allMembers(),'mailboxes'=>$this->mailboxes->all(),'error'=>$message,'created'=>null,'providerName'=>$this->providerName]);
        }
    }
    public function activateManual(int $id):void
    {
        $this->auth->requireAdmin();Csrf::verify();
        if($this->providerName!=='manual'||($_POST['confirm_external']??'')!=='yes'){http_response_code(422);exit('Confirm external provisioning first.');}
        $this->service->activateManual($id);header('Location: /admin/mailboxes');exit;
    }
    public function suspend(int $id):void
    {
        $this->auth->requireAdmin();Csrf::verify();
        if($this->providerName==='manual'&&($_POST['confirm_external']??'')!=='yes'){http_response_code(422);exit('Confirm external suspension first.');}
        $this->service->suspend($id);header('Location: /admin/mailboxes');exit;
    }
}
