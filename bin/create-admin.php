<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Core\Config;
use App\Core\Database;
use App\Repositories\UserRepository;

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$config=Config::load(dirname(__DIR__).'/.env');
if($argc<2||!filter_var($argv[1],FILTER_VALIDATE_EMAIL)||$argc>3){
    fwrite(STDERR,"Usage: php bin/create-admin.php admin@example.com [test-only-password]\n");
    exit(1);
}
if($argc===3){
    if(strtolower((string)$config->get('APP_ENV','production'))!=='testing'){
        fwrite(STDERR,"Password arguments are disabled outside APP_ENV=testing. Omit the argument and enter the password at the hidden prompt.\n");
        exit(1);
    }
    $password=(string)$argv[2];
}else{
    fwrite(STDOUT,"Administrator password (minimum 14 characters): ");
    $terminalState=null;
    if(PHP_OS_FAMILY!=='Windows'&&function_exists('stream_isatty')&&stream_isatty(STDIN)&&function_exists('shell_exec')){
        $state=trim((string)@shell_exec('stty -g 2>/dev/null'));
        if($state!==''){
            $terminalState=$state;
            @shell_exec('stty -echo');
        }else{
            fwrite(STDERR,"\nWarning: terminal echo could not be disabled; use a secure input method.\n");
        }
    }
    $input=fgets(STDIN);
    if($terminalState!==null){
        @shell_exec('stty '.escapeshellarg($terminalState));
        fwrite(STDOUT,"\n");
    }
    $password=$input===false?'':rtrim($input,"\r\n");
}
if(strlen($password)<14){
    fwrite(STDERR,"Password must be at least 14 characters long.\n");
    exit(1);
}

$users=new UserRepository(Database::connect($config));
$email=strtolower($argv[1]);
if($users->findByEmail($email)){
    fwrite(STDERR,"Account already exists.\n");
    exit(1);
}
$users->create($email,'Administrator',password_hash($password,PASSWORD_DEFAULT),'admin');
fwrite(STDOUT,"Administrator created.\n");
