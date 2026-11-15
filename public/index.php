<?php
declare(strict_types=1);
use App\Core\Auth;use App\Core\Config;use App\Core\Database;use App\Core\Router;use App\Core\View;
use App\Controllers\AuthController;use App\Controllers\DashboardController;use App\Controllers\AdminMailboxController;use App\Controllers\WebmailController;
use App\Providers\LocalSessionMemberProvider;use App\Providers\ManualMailProvider;use App\Providers\CraneMailProvider;
use App\Repositories\UserRepository;use App\Repositories\MailboxRepository;use App\Services\MailboxService;use App\Services\ImapClient;use App\Services\SmtpClient;
require dirname(__DIR__).'/app/bootstrap.php';
$config=Config::load(dirname(__DIR__).'/.env');
$isProduction = $config->get('APP_ENV') === 'production';
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name($config->get('APP_SESSION_NAME', 'c0mmail_session'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isProduction || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; img-src 'self' data:; script-src 'self'; style-src 'self'");
header('Cache-Control: private, no-store, max-age=0');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    header('Strict-Transport-Security: max-age=31536000');
}
$db=Database::connect($config);$users=new UserRepository($db);$mailboxes=new MailboxRepository($db);
$auth=new Auth(new LocalSessionMemberProvider($users));$view=new View(dirname(__DIR__).'/app/Views');
$providerName=$config->get('MAIL_PROVIDER','manual');
if(!in_array($providerName,['manual','cranemail'],true))throw new RuntimeException('Unsupported MAIL_PROVIDER value.');
$provider=$providerName==='cranemail'?new CraneMailProvider($config):new ManualMailProvider($config);
$service=new MailboxService($mailboxes,$provider,$config);$authController=new AuthController($users,$view);$webmail=new WebmailController($auth,$mailboxes,$service,new ImapClient(),new SmtpClient(),$view);
$dashboard=new DashboardController($auth,$mailboxes,$view,$provider);
$admin=new AdminMailboxController($auth,$users,$mailboxes,$service,$view,$providerName,$config->required('MAIL_DOMAIN'));
$router=new Router();
$router->get('/',fn()=>$auth->check()?$dashboard->index():$authController->showLogin());
$router->get('/login',fn()=>$authController->showLogin());$router->post('/login',fn()=>$authController->login());$router->post('/logout',fn()=>$authController->logout());
$router->get('/dashboard',fn()=>$dashboard->index());$router->get('/mail',fn()=>$webmail->index());$router->get('/mail/attachment',fn()=>$webmail->downloadAttachment());$router->post('/mail/send',fn()=>$webmail->send());$router->post('/mail/draft',fn()=>$webmail->saveDraft());$router->post('/mail/move',fn()=>$webmail->move());$router->post('/mail/flag',fn()=>$webmail->flag());$router->get('/admin/mailboxes',fn()=>$admin->index());$router->post('/admin/mailboxes',fn()=>$admin->create());
$router->post('/admin/mailboxes/{id}/activate',fn(array $p)=>$admin->activateManual((int)$p['id']));
$router->post('/admin/mailboxes/{id}/suspend',fn(array $p)=>$admin->suspend((int)$p['id']));
$router->dispatch($_SERVER['REQUEST_METHOD']??'GET',parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/');
