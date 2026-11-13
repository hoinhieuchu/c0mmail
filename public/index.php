<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Router;
use App\Core\View;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\AdminMailboxController;
use App\Providers\LocalSessionMemberProvider;
use App\Providers\ManualMailProvider;
use App\Providers\CraneMailProvider;
use App\Repositories\UserRepository;
use App\Repositories\MailboxRepository;
use App\Services\MailboxService;

require dirname(__DIR__) . '/app/bootstrap.php';
$config = Config::load(dirname(__DIR__) . '/.env');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name($config->get('APP_SESSION_NAME', 'c0mmail_session'));
    session_set_cookie_params(['httponly' => true, 'secure' => $config->get('APP_ENV') === 'production', 'samesite' => 'Lax']);
    session_start();
}
$db = Database::connect($config);
$users = new UserRepository($db);
$mailboxes = new MailboxRepository($db);
$auth = new Auth(new LocalSessionMemberProvider($users));
$view = new View(dirname(__DIR__) . '/app/Views');
$providerName = $config->get('MAIL_PROVIDER', 'manual');
$provider = $providerName === 'cranemail' ? new CraneMailProvider($config) : new ManualMailProvider($config);
$mailboxService = new MailboxService($mailboxes, $provider, $config);
$authController = new AuthController($users, $view);
$dashboard = new DashboardController($auth, $mailboxes, $view, $config);
$admin = new AdminMailboxController($auth, $users, $mailboxes, $mailboxService, $view, $providerName);

$router = new Router();
$router->get('/', fn() => $auth->check() ? $dashboard->index() : $authController->showLogin());
$router->get('/login', fn() => $authController->showLogin());
$router->post('/login', fn() => $authController->login());
$router->post('/logout', fn() => $authController->logout());
$router->get('/dashboard', fn() => $dashboard->index());
$router->get('/admin/mailboxes', fn() => $admin->index());
$router->post('/admin/mailboxes', fn() => $admin->create());
$router->post('/admin/mailboxes/{id}/suspend', fn(array $p) => $admin->suspend((int)$p['id']));
$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
