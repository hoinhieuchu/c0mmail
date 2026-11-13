<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Core\Config;use App\Core\Database;use App\Repositories\UserRepository;
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if($argc<3||!filter_var($argv[1],FILTER_VALIDATE_EMAIL)||strlen($argv[2])<14){fwrite(STDERR,"Usage: php bin/create-admin.php admin@example.com 'minimum-14-character-password'\n");exit(1);}
$c=Config::load(dirname(__DIR__).'/.env');$r=new UserRepository(Database::connect($c));$email=strtolower($argv[1]);
if($r->findByEmail($email)){fwrite(STDERR,"Account already exists.\n");exit(1);}
$r->create($email,'Administrator',password_hash($argv[2],PASSWORD_DEFAULT),'admin');fwrite(STDOUT,"Administrator created.\n");
