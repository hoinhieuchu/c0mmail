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
        $notice=$_SESSION['mailbox_notice']??null;unset($_SESSION['mailbox_notice']);$this->view->render('admin/mailboxes',['title'=>'Mailbox administration','members'=>$this->users->allMembers(),'mailboxes'=>$this->mailboxes->all(),'error'=>null,'notice'=>$notice,'created'=>$created,'providerName'=>$this->providerName]);
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
            $this->view->render('admin/mailboxes',['title'=>'Mailbox administration','members'=>$this->users->allMembers(),'mailboxes'=>$this->mailboxes->all(),'error'=>$e->getMessage(),'notice'=>null,'created'=>null,'providerName'=>$this->providerName]);
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
    public function updateCredentials(int $id):void
    {
        $this->auth->requireAdmin();Csrf::verify();
        if($this->providerName!=='manual'||($_POST['confirm_external']??'')!=='yes'){http_response_code(422);exit('Change the mailbox password at the provider first, then confirm the update here.');}
        try{
            $password=(string)($_POST['password']??'');
            $this->service->updateManualPassword($id,$password);
            $_SESSION['mailbox_notice']='Encrypted mailbox credentials updated. Verify IMAP and SMTP access with this mailbox.';
        }catch(\Throwable $e){$_SESSION['mailbox_notice']='Credential update failed: '.$e->getMessage();}
        header('Location: /admin/mailboxes');exit;
    }

}
