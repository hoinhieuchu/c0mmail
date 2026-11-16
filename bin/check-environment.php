<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$errors=[];$warnings=[];$ok=[];
$check=static function(bool $pass,string $label)use(&$errors,&$ok):void{if($pass){$ok[]=$label;}else{$errors[]=$label;}};
$warn=static function(string $label)use(&$warnings):void{$warnings[]=$label;};
$check(PHP_VERSION_ID>=80200,'PHP 8.2 or newer');
foreach(['pdo_mysql','imap','openssl','dom','fileinfo','json','session'] as $extension)$check(extension_loaded($extension),'PHP extension: '.$extension);
$root=dirname(__DIR__);$envPath=$root.'/.env';
if(is_file($envPath)){
    $check(is_readable($envPath),'.env is readable');
    $publicRoot=realpath($root.'/public');
    $envReal=realpath($envPath);
    $check($publicRoot===false||$envReal===false||!str_starts_with($envReal,$publicRoot.DIRECTORY_SEPARATOR),'.env is outside the public document root');
}else{
    $warn('.env is absent; all required configuration must be injected through the process environment.');
}
try{$config=Config::load($envPath);}catch(Throwable $e){$config=null;$errors[]='Configuration file can be read';}
if($config){
    $url=(string)$config->get('APP_URL','');
    $parts=parse_url($url);
    $check(is_array($parts)&&($parts['scheme']??'')==='https'&&!empty($parts['host']),'APP_URL uses HTTPS and has a hostname');
    $check(strtolower((string)$config->get('APP_ENV','production'))==='production','APP_ENV is production');
    $check(strtolower((string)$config->get('APP_DEBUG','false'))!=='true','APP_DEBUG is disabled');
    $key=(string)$config->get('APP_KEY','');$decoded=str_starts_with($key,'base64:')?base64_decode(substr($key,7),true):false;
    $check(is_string($decoded)&&strlen($decoded)===32,'APP_KEY decodes to exactly 32 random bytes');
    $check((bool)filter_var((string)$config->get('MAIL_DOMAIN',''),FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME),'MAIL_DOMAIN is a valid hostname');
    foreach(['DB_HOST','DB_DATABASE','DB_USERNAME'] as $keyName)$check((string)$config->get($keyName,'')!=='','Configuration set: '.$keyName);
    $provider=(string)$config->get('MAIL_PROVIDER','manual');
    $check(in_array($provider,['manual','cranemail'],true),'MAIL_PROVIDER is supported');
    if($provider==='cranemail')$warn('CraneMail provisioning is intentionally disabled until its official API contract is verified; use manual provisioning.');
    foreach([['MAIL_SMTP_HOST','MAIL_SMTP_PORT','MAIL_SMTP_ENCRYPTION',['ssl','starttls']],['MAIL_IMAP_HOST','MAIL_IMAP_PORT','MAIL_IMAP_ENCRYPTION',['ssl','starttls']]] as [$hostKey,$portKey,$modeKey,$modes]){
        $host=(string)$config->get($hostKey,'');
        if($host==='')$warn($hostKey.' is not set; real webmail cannot connect until supplied by your mail provider.');
        else{
            $check((bool)preg_match('/^[a-zA-Z0-9.-]+$/',$host),$hostKey.' is syntactically valid');
            $port=(int)$config->get($portKey,'0');$check($port>=1&&$port<=65535,$portKey.' is a valid TCP port');
            $check(in_array(strtolower((string)$config->get($modeKey,'')),$modes,true),$modeKey.' uses SSL or STARTTLS');
        }
    }
    try{
        if((string)$config->get('DB_HOST','')!==''){
            $db=Database::connect($config);$db->query('SELECT 1');
            $ok[]='Database connection';
            foreach(['users','mailboxes','mail_send_attempts','login_rate_limits','schema_migrations'] as $table){
                $exists=$db->query('SHOW TABLES LIKE '.$db->quote($table))->fetchColumn();
                if($exists)$ok[]='Database table: '.$table;else $warnings[]='Database table missing: '.$table.' (run php bin/migrate.php after backup if this is a new installation).';
            }
        }
    }catch(Throwable $e){$errors[]='Database connection (check DB_* settings and privileges): '.$e->getMessage();}
}
foreach($ok as $item)fwrite(STDOUT,"[OK]   {$item}\n");
foreach($warnings as $item)fwrite(STDOUT,"[WARN] {$item}\n");
foreach($errors as $item)fwrite(STDERR,"[FAIL] {$item}\n");
fwrite(STDOUT,sprintf("\nPreflight: %d OK, %d warning(s), %d failure(s).\n",count($ok),count($warnings),count($errors)));
exit($errors?1:0);
