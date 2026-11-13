<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
use App\Core\Config;
use App\Core\Database;
use App\Repositories\UserRepository;
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if ($argc < 3 || !filter_var($argv[1], FILTER_VALIDATE_EMAIL) || strlen($argv[2]) < 14) {
    fwrite(STDERR, "Usage: php bin/create-member.php member@example.com 'minimum-14-char-password'\n"); exit(1);
}
$config=Config::load(dirname(__DIR__).'/.env');$repo=new UserRepository(Database::connect($config));
if ($repo->findByEmail(strtolower($argv[1]))) { fwrite(STDERR,"Account already exists.\n");exit(1); }
$repo->create(strtolower($argv[1]),'Member',password_hash($argv[2],PASSWORD_DEFAULT),'member');
fwrite(STDOUT,"Member created.\n");
