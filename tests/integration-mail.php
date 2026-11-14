<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Core\Config;
use App\Providers\NativeImapClient;
use App\Services\MimeMessageBuilder;
use App\Services\MailTransportFactory;
use App\Services\MailboxWebmailService;
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo "PASS {$message}\n";}
$host=getenv('MAIL_TEST_HOST')?:'127.0.0.1';$imapPort=(int)(getenv('MAIL_TEST_IMAP_PORT')?:3143);$smtpPort=(int)(getenv('MAIL_TEST_SMTP_PORT')?:3025);$user=getenv('MAIL_TEST_USER')?:'test@example.com';$email=getenv('MAIL_TEST_EMAIL')?:'test@example.com';$pass=getenv('MAIL_TEST_PASSWORD')?:'secret';
check(function_exists('imap_open'),'PHP IMAP extension loaded');
$config=new Config(['MAIL_IMAP_HOST'=>$host,'MAIL_IMAP_PORT'=>(string)$imapPort,'MAIL_IMAP_ENCRYPTION'=>'none','MAIL_SMTP_HOST'=>$host,'MAIL_SMTP_PORT'=>(string)$smtpPort,'MAIL_SMTP_ENCRYPTION'=>'none','MAIL_PAGE_SIZE'=>'25']);
$mime=new MimeMessageBuilder();$webmail=new MailboxWebmailService(new MailTransportFactory($config),$config,$mime);
$imap=new NativeImapClient($host,$imapPort,'none',$user,$pass);
try{
 $folders=$imap->folders();check((bool)array_filter($folders,fn($f)=>strtoupper($f['path'])==='INBOX'),'IMAP INBOX exists');
 foreach(['Milestone2Integration','Sent','Drafts'] as $folder){if(!array_filter($folders,fn($f)=>$f['path']===$folder))$imap->createFolder($folder);}
 $tmp=tempnam(sys_get_temp_dir(),'c0m-');file_put_contents($tmp,"real attachment test\n");
 $message=['from'=>$email,'to'=>$email,'subject'=>'c0m.me Mail integration '.bin2hex(random_bytes(4)),'body'=>'Message delivered through the test SMTP server.','attachments'=>[['name'=>'probe.txt','path'=>$tmp,'type'=>'text/plain']]];
 $webmail->send(['email'=>$email,'password'=>$pass],$message);
 $listing=null;for($i=0;$i<20;$i++){usleep(250000);$listing=$imap->messages('INBOX',1,25);if($listing['total']>0)break;}
 check($listing!==null&&$listing['total']>0,'SMTP message delivered into the real IMAP inbox');
 check($imap->messages('Sent',1,25)['total']>0,'Sent copy appended to the real Sent folder');
 $uid=(int)$listing['messages'][0]['uid'];$read=$imap->message('INBOX',$uid);
 check(str_contains($read['subject'],'c0m.me Mail integration'),'IMAP fetched message headers and body');
 $search=$imap->messages('INBOX',1,25,'c0m.me Mail integration');check($search['total']>0,'IMAP server-side text search returns matching messages');
 $imap->setFlag('INBOX',$uid,'seen',false);$unread=$imap->messages('INBOX',1,25);$unreadRow=array_values(array_filter($unread['messages'],fn($m)=>$m['uid']===$uid))[0]??null;check($unreadRow!==null&&!$unreadRow['seen'],'IMAP unread flag is reflected in message listing');
 $imap->setFlag('INBOX',$uid,'seen',true);$imap->setFlag('INBOX',$uid,'flagged',true);$flagged=$imap->messages('INBOX',1,25);$flaggedRow=array_values(array_filter($flagged['messages'],fn($m)=>$m['uid']===$uid))[0]??null;check($flaggedRow!==null&&$flaggedRow['flagged'],'IMAP star flag is reflected in message listing');
 check(count($read['attachments'])===1,'MIME attachment metadata parsed');
 $download=$imap->downloadPart('INBOX',$uid,$read['attachments'][0]['part']);check(str_contains($download['data'],'real attachment test'),'MIME attachment downloaded and decoded');
 $imap->setFlag('INBOX',$uid,'flagged',true);$imap->move('INBOX',$uid,'Milestone2Integration');$moved=$imap->messages('Milestone2Integration',1,25);check($moved['total']>0,'IMAP message moved to custom folder');
 $movedUid=(int)$moved['messages'][0]['uid'];$imap->delete('Milestone2Integration',$movedUid);check($imap->messages('Milestone2Integration',1,25)['total']===0,'IMAP message deleted and expunged');
 $webmail->saveDraft(['email'=>$email,'password'=>$pass],['from'=>$email,'to'=>'','cc'=>'','bcc'=>'','subject'=>'Saved draft','body'=>'Draft persisted on IMAP']);
 check($imap->messages('Drafts',1,25)['total']>0,'Draft saved to the real IMAP Drafts folder');
 unlink($tmp);
}finally{$imap->close();}
echo "IMAP/SMTP integration suite passed.\n";
