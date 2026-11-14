<?php
declare(strict_types=1);

use App\Core\Config;

require dirname(__DIR__).'/app/bootstrap.php';

$root=dirname(__DIR__);
$config=Config::load($root.'/.env');
$errors=0;
$warnings=0;

$report=static function(string $label,bool $ok,string $severity='error')use(&$errors,&$warnings):void{
    if($ok){echo "[PASS] {$label}\n";return;}
    if($severity==='warning'){$warnings++;echo "[WARN] {$label}\n";return;}
    $errors++;echo "[FAIL] {$label}\n";
};

$report('PHP 8.1 or newer',PHP_VERSION_ID>=80100);
foreach(['PDO','pdo_mysql','openssl','imap','fileinfo','session'] as $extension){
    $report("PHP extension: {$extension}",extension_loaded($extension));
}
$report('.env file exists and is readable',is_file($root.'/.env')&&is_readable($root.'/.env'));

$appEnv=strtolower((string)$config->get('APP_ENV','production'));
$appUrl=(string)$config->get('APP_URL','');
$report('APP_URL is a valid HTTPS URL in production',filter_var($appUrl,FILTER_VALIDATE_URL)!==false&&($appEnv!=='production'||str_starts_with(strtolower($appUrl),'https://')));
$report('APP_DEBUG is disabled in production',$appEnv!=='production'||strtolower((string)$config->get('APP_DEBUG','false'))==='false');

$key=(string)$config->get('APP_KEY','');
$decoded=str_starts_with($key,'base64:')?base64_decode(substr($key,7),true):false;
$report('APP_KEY decodes to exactly 32 bytes',is_string($decoded)&&strlen($decoded)===32);

foreach(['DB_HOST','DB_DATABASE','DB_USERNAME','DB_PASSWORD','MAIL_DOMAIN','MAIL_IMAP_HOST','MAIL_SMTP_HOST'] as $name){
    $value=(string)$config->get($name,'');
    $report("Configuration: {$name}",$value!=='');
}
$domain=(string)$config->get('MAIL_DOMAIN','');
$report('MAIL_DOMAIN is a valid email domain',$domain!==''&&filter_var('postmaster@'.$domain,FILTER_VALIDATE_EMAIL)!==false);

foreach(['MAIL_IMAP_PORT','MAIL_SMTP_PORT'] as $name){
    $port=(int)$config->get($name,'0');
    $report("Configuration: {$name} is between 1 and 65535",$port>=1&&$port<=65535);
}
foreach(['MAIL_IMAP_ENCRYPTION','MAIL_SMTP_ENCRYPTION'] as $name){
    $mode=strtolower((string)$config->get($name,''));
    $severity=$mode==='none'&&$appEnv!=='production'?'warning':'error';
    $report("Configuration: {$name} uses SSL/TLS",$mode==='ssl'||$mode==='starttls',$severity);
}
$imapTimeout=(int)$config->get('MAIL_IMAP_TIMEOUT','20');
$report('MAIL_IMAP_TIMEOUT is between 1 and 120 seconds',$imapTimeout>=1&&$imapTimeout<=120);
$smtpTimeout=(int)$config->get('MAIL_SMTP_TIMEOUT','20');
$report('MAIL_SMTP_TIMEOUT is between 1 and 120 seconds',$smtpTimeout>=1&&$smtpTimeout<=120);
$ipWideLimit=strtolower((string)$config->get('LOGIN_RATE_LIMIT_IP_WIDE','false'))==='true';
$report('Global IP login throttling is disabled unless REMOTE_ADDR is the real client IP',!$ipWideLimit,'warning');
$allowInsecure=strtolower((string)$config->get('MAIL_ALLOW_INSECURE_AUTH','false'))==='true';
$report('Insecure SMTP authentication is disabled in production',!($appEnv==='production'&&$allowInsecure));

echo "\n";
echo $errors===0?"Preflight passed with {$warnings} warning(s). No network connectivity test was performed.\n":"Preflight failed: {$errors} error(s), {$warnings} warning(s). No network connectivity test was performed.\n";
exit($errors===0?0:1);
