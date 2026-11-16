<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Repositories\UserRepository;

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if($argc!==2||!filter_var($argv[1],FILTER_VALIDATE_EMAIL)){
    fwrite(STDERR,"Usage: php bin/create-member.php member@example.com\n");
    exit(1);
}
fwrite(STDOUT,"Member password (minimum 14 characters): ");
$terminalState=null;
if(PHP_OS_FAMILY!=='Windows'&&function_exists('stream_isatty')&&stream_isatty(STDIN)&&function_exists('shell_exec')){
    $state=trim((string)@shell_exec('stty -g 2>/dev/null'));
    if($state!==''){
        $terminalState=$state;
        @shell_exec('stty -echo');
        register_shutdown_function(static function()use(&$terminalState):void{if($terminalState!==null){@shell_exec('stty '.escapeshellarg($terminalState));$terminalState=null;}});
    }else fwrite(STDERR,"\nWarning: terminal echo could not be disabled; use a secure input method.\n");
}elseif(PHP_OS_FAMILY!=='Windows') fwrite(STDERR,"\nWarning: password input may be echoed by this terminal.\n");
$input=fgets(STDIN);
if($terminalState!==null){@shell_exec('stty '.escapeshellarg($terminalState));$terminalState=null;fwrite(STDOUT,"\n");}
$password=$input===false?'':rtrim($input,"\r\n");
if(strlen($password)<14){fwrite(STDERR,"Password must be at least 14 characters long.\n");exit(1);}
try{
    $config=Config::load(dirname(__DIR__).'/.env');
    $repo=new UserRepository(Database::connect($config));
    $email=strtolower($argv[1]);
    if($repo->findByEmail($email)){fwrite(STDERR,"Account already exists.\n");exit(1);}
    $repo->create($email,'Member',password_hash($password,PASSWORD_DEFAULT),'member');
    fwrite(STDOUT,"Member created.\n");
}catch(Throwable $e){fwrite(STDERR,"Member creation failed: ".$e->getMessage()."\n");exit(1);}
