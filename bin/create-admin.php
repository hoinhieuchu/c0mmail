<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Core\Config;
use App\Core\Database;
use App\Repositories\UserRepository;

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if($argc<2||!filter_var($argv[1],FILTER_VALIDATE_EMAIL)){
    fwrite(STDERR,"Usage: php bin/create-admin.php admin@example.com < password-from-stdin\n");
    exit(1);
}
if($argc>2){
    fwrite(STDERR,"Do not pass the password as a command-line argument; it may be exposed in shell history or process listings. Pipe it through STDIN instead.\n");
    exit(1);
}

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
if(strlen($password)<14){
    fwrite(STDERR,"Password must be at least 14 characters long.\n");
    exit(1);
}

$config=Config::load(dirname(__DIR__).'/.env');
$users=new UserRepository(Database::connect($config));
$email=strtolower($argv[1]);
if($users->findByEmail($email)){
    fwrite(STDERR,"Account already exists.\n");
    exit(1);
}
$users->create($email,'Administrator',password_hash($password,PASSWORD_DEFAULT),'admin');
fwrite(STDOUT,"Administrator created.\n");
