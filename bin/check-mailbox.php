<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Repositories\MailboxRepository;
use App\Providers\ManualMailProvider;
use App\Services\MailboxService;
use App\Services\ImapClient;
use App\Services\SmtpConnectionChecker;

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if($argc!==2||!ctype_digit($argv[1])||(int)$argv[1]<1){
    fwrite(STDERR,"Usage: php bin/check-mailbox.php MAILBOX_ID\n");
    exit(1);
}
try{
    $config=Config::load(dirname(__DIR__).'/.env');
    $db=Database::connect($config);
    $repo=new MailboxRepository($db);
    $row=$repo->find((int)$argv[1]);
    if(!$row)throw new RuntimeException('Mailbox not found.');
    $service=new MailboxService($repo,new ManualMailProvider($config),$config);
    $context=$service->credentialsForUser((int)$argv[1],(int)$row['user_id']);
    $imap=new ImapClient();
    $folders=$imap->folders($context['settings'],$context['credentials']);
    if(!$folders)throw new RuntimeException('IMAP connected but returned no folders.');
    fwrite(STDOUT,"[OK] IMAP authenticated; folders: ".implode(', ',array_column($folders,'name'))."\n");
    (new SmtpConnectionChecker())->verify($context['settings'],$context['credentials']);
    fwrite(STDOUT,"[OK] SMTP TLS and AUTH succeeded; NOOP accepted. No email was sent.\n");
    fwrite(STDOUT,"Mailbox connection test passed.\n");
}catch(Throwable $e){
    fwrite(STDERR,"Mailbox test failed: ".$e->getMessage()."\n");
    exit(1);
}
