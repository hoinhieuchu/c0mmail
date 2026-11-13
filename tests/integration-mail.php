<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Providers\NativeImapClient;
use App\Providers\SocketSmtpClient;
use App\Services\MimeMessageBuilder;
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo "PASS {$message}\n";}
$host=getenv('MAIL_TEST_HOST')?:'127.0.0.1';$imapPort=(int)(getenv('MAIL_TEST_IMAP_PORT')?:3143);$smtpPort=(int)(getenv('MAIL_TEST_SMTP_PORT')?:3025);$user=getenv('MAIL_TEST_USER')?:'test';$email=getenv('MAIL_TEST_EMAIL')?:'test@example.test';$pass=getenv('MAIL_TEST_PASSWORD')?:'secret';
check(function_exists('imap_open'),'PHP IMAP extension loaded');
$imap=new NativeImapClient($host,$imapPort,'none',$user,$pass);
try{
 $folders=$imap->folders();check((bool)array_filter($folders,fn($f)=>strtoupper($f['path'])==='INBOX'),'IMAP INBOX exists');
 $custom='Milestone2Integration';if(!array_filter($folders,fn($f)=>$f['path']===$custom))$imap->createFolder($custom);
 $tmp=tempnam(sys_get_temp_dir(),'c0m-');file_put_contents($tmp,"real attachment test\n");
 $message=['from'=>$email,'to'=>$email,'subject'=>'c0m.me Mail integration '.bin2hex(random_bytes(4)),'body'=>'Message delivered through the test SMTP server.','attachments'=>[['name'=>'probe.txt','path'=>$tmp,'type'=>'text/plain']]];
 $raw=(new MimeMessageBuilder())->build($message);
 (new SocketSmtpClient($host,$smtpPort,'none',$user,$pass))->send($message);
 $listing=null;for($i=0;$i<20;$i++){usleep(250000);$listing=$imap->messages('INBOX',1,25);if($listing['total']>0)break;}
 check($listing!==null&&$listing['total']>0,'SMTP message delivered into the real IMAP inbox');
 $uid=(int)$listing['messages'][0]['uid'];$read=$imap->message('INBOX',$uid);
 check(str_contains($read['subject'],'c0m.me Mail integration'),'IMAP fetched message headers and body');
 check(count($read['attachments'])===1,'MIME attachment metadata parsed');
 $download=$imap->downloadPart('INBOX',$uid,$read['attachments'][0]['part']);check(str_contains($download['data'],'real attachment test'),'MIME attachment downloaded and decoded');
 $imap->setFlag('INBOX',$uid,'flagged',true);$imap->move('INBOX',$uid,$custom);$moved=$imap->messages($custom,1,25);check($moved['total']>0,'IMAP message moved to custom folder');
 $movedUid=(int)$moved['messages'][0]['uid'];$imap->delete($custom,$movedUid);check($imap->messages($custom,1,25)['total']===0,'IMAP message deleted and expunged');
 $imap->append($custom,$raw,'\\Draft');check($imap->messages($custom,1)['total']===1,'IMAP APPEND saves a draft copy');
 unlink($tmp);
}finally{$imap->close();}
echo "IMAP/SMTP integration suite passed.\n";
