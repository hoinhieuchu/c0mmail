<?php
declare(strict_types=1);
use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Router;
use App\Core\View;
use App\Controllers\AccountController;
use App\Controllers\AdminMailboxController;
use App\Controllers\AdminMemberController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\WebmailController;
use App\Providers\CraneMailProvider;
use App\Providers\LocalSessionMemberProvider;
use App\Providers\ManualMailProvider;
use App\Repositories\LoginRateLimitRepository;
use App\Repositories\MailboxRepository;
use App\Repositories\SendAttemptRepository;
use App\Repositories\UserRepository;
use App\Services\ClientIpResolver;
use App\Services\ImapClient;
use App\Services\MailboxService;
use App\Services\PasswordPolicy;
use App\Services\SmtpClient;

require dirname(__DIR__).'/app/bootstrap.php';
$config=Config::load(dirname(__DIR__).'/.env');
$isProduction = $config->get('APP_ENV') === 'production';
$trustedProxyCidrs = array_values(array_filter(array_map('trim', explode(',', (string)$config->get('APP_TRUSTED_PROXY_CIDRS', '')))));
$isHttps = ClientIpResolver::isSecureRequest($_SERVER, $trustedProxyCidrs);
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name($config->get('APP_SESSION_NAME', 'c0mmail_session'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isProduction || $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cross-Origin-Opener-Policy: same-origin');
header('Cross-Origin-Resource-Policy: same-origin');
header('X-Permitted-Cross-Domain-Policies: none');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; img-src 'self' data:; script-src 'self'; style-src 'self'");
header('Cache-Control: private, no-store, max-age=0');
if ($isHttps) header('Strict-Transport-Security: max-age=31536000');

$db=Database::connect($config);
$users=new UserRepository($db);
$mailboxes=new MailboxRepository($db);
$sendAttempts=new SendAttemptRepository($db);
$auth=new Auth(new LocalSessionMemberProvider($users));
$view=new View(dirname(__DIR__).'/app/Views');
$passwordPolicy=new PasswordPolicy();
$providerName=$config->get('MAIL_PROVIDER','manual');
if(!in_array($providerName,['manual','cranemail'],true)) throw new RuntimeException('Unsupported MAIL_PROVIDER value.');
$provider=$providerName==='cranemail'?new CraneMailProvider($config):new ManualMailProvider($config);
$service=new MailboxService($mailboxes,$provider,$config);
$loginLimits=new LoginRateLimitRepository($db,$config->required('APP_KEY'));
$authController=new AuthController($users,$loginLimits,$view,$trustedProxyCidrs);
$accountController=new AccountController($auth,$users,$view,$passwordPolicy);
$adminMemberController=new AdminMemberController($auth,$users,$view,$passwordPolicy);
$webmail=new WebmailController($auth,$mailboxes,$service,new ImapClient(),new SmtpClient(),$view,$sendAttempts);
$dashboard=new DashboardController($auth,$mailboxes,$view,$provider);
$admin=new AdminMailboxController($auth,$users,$mailboxes,$service,$view,$providerName,$config->required('MAIL_DOMAIN'));

$router=new Router();
$router->get('/',fn()=>$auth->check()?$dashboard->index():$authController->showLogin());
$router->get('/login',fn()=>$authController->showLogin());
$router->post('/login',fn()=>$authController->login());
$router->post('/logout',fn()=>$authController->logout());
$router->get('/dashboard',fn()=>$dashboard->index());
$router->get('/account/password',fn()=>$accountController->showPassword());
$router->post('/account/password',fn()=>$accountController->updatePassword());
$router->get('/mail',fn()=>$webmail->index());
$router->get('/mail/updates',fn()=>$webmail->updates());
$router->get('/mail/attachment',fn()=>$webmail->downloadAttachment());
$router->post('/mail/send',fn()=>$webmail->send());
$router->post('/mail/draft',fn()=>$webmail->saveDraft());
$router->post('/mail/move',fn()=>$webmail->move());
$router->post('/mail/flag',fn()=>$webmail->flag());
$router->get('/admin/mailboxes',fn()=>$admin->index());
$router->post('/admin/mailboxes',fn()=>$admin->create());
$router->post('/admin/mailboxes/{id}/activate',fn(array $p)=>$admin->activateManual((int)$p['id']));
$router->post('/admin/mailboxes/{id}/suspend',fn(array $p)=>$admin->suspend((int)$p['id']));
$router->get('/admin/members',fn()=>$adminMemberController->index());
$router->post('/admin/members',fn()=>$adminMemberController->create());
$router->post('/admin/members/{id}/status',fn(array $p)=>$adminMemberController->updateStatus((int)$p['id']));
$router->dispatch($_SERVER['REQUEST_METHOD']??'GET',parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/');
