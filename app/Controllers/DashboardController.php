<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Contracts\MailProviderInterface;
use App\Core\Auth;
use App\Core\View;
use App\Repositories\MailboxRepository;
final class DashboardController
{
    public function __construct(private Auth $auth,private MailboxRepository $mailboxes,private View $view,private MailProviderInterface $provider){}
    public function index():void
    {
        $u=$this->auth->requireLogin();
        $this->view->render('dashboard',['title'=>'Your mailboxes','user'=>$u,'mailboxes'=>$this->mailboxes->forUser((int)$u['id']),'settings'=>$this->provider->connectionSettings()]);
    }
}
