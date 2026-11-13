<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Auth;
use App\Core\Config;
use App\Core\View;
use App\Repositories\MailboxRepository;
final class DashboardController
{
    public function __construct(private Auth $auth,private MailboxRepository $mailboxes,private View $view,private Config $config){}
    public function index():void
    {
        $u=$this->auth->requireLogin();
        $settings=['smtp_host'=>$this->config->get('MAIL_SMTP_HOST',''),'smtp_port'=>$this->config->get('MAIL_SMTP_PORT','587'),'smtp_encryption'=>$this->config->get('MAIL_SMTP_ENCRYPTION','starttls'),
            'imap_host'=>$this->config->get('MAIL_IMAP_HOST',''),'imap_port'=>$this->config->get('MAIL_IMAP_PORT','993'),'imap_encryption'=>$this->config->get('MAIL_IMAP_ENCRYPTION','ssl')];
        $this->view->render('dashboard',['title'=>'Your mailboxes','user'=>$u,'mailboxes'=>$this->mailboxes->forUser((int)$u['id']),'settings'=>$settings]);
    }
}
