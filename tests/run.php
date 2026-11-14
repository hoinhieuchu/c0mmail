<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Core\Router;
use App\Core\Config;
use App\Providers\ManualMailProvider;
use App\Providers\CraneMailProvider;
use App\Services\MimeMessageBuilder;
use App\Services\HtmlEmailSanitizer;
use App\Services\PasswordPolicy;
use App\Providers\SocketSmtpClient;
$count=0;
function test(string $name,callable $fn):void{global $count;$fn();$count++;echo "PASS {$name}\n";}
function same($expected,$actual):void{if($expected!==$actual)throw new RuntimeException('Expected '.var_export($expected,true).', got '.var_export($actual,true));}
test('autoload resolves application classes',fn()=>same(true,class_exists(Router::class)));
test('manual mode never reports remote provisioning as active',function(){$p=new ManualMailProvider(new Config(['MAIL_SMTP_HOST'=>'smtp.example.test']));$r=$p->provision(['email'=>'u@example.test','password'=>'secret','display_name'=>'User']);same('manual_pending',$r['status']);same(null,$r['provider_id']);same('smtp.example.test',$p->connectionSettings()['smtp_host']);});
test('manual provider refuses pretend remote suspension',function(){$p=new ManualMailProvider(new Config([]));try{$p->suspend('');throw new RuntimeException('Expected refusal');}catch(LogicException $e){same(true,str_contains($e->getMessage(),'control panel'));}});
test('CraneMail adapter rejects non-HTTPS endpoints',function(){$p=new CraneMailProvider(new Config(['CRANEMAIL_API_BASE_URL'=>'http://invalid.example','CRANEMAIL_API_TOKEN'=>'test','CRANEMAIL_PROVISION_PATH'=>'/mailboxes']));try{$p->provision(['email'=>'x@example.test','password'=>'secret','display_name'=>'X']);throw new RuntimeException('Expected HTTPS guard');}catch(RuntimeException $e){same(true,str_contains($e->getMessage(),'HTTPS'));}});
test('CraneMail adapter exposes SMTP and IMAP transport settings',function(){$p=new CraneMailProvider(new Config(['MAIL_SMTP_HOST'=>'smtp.example.test','MAIL_IMAP_HOST'=>'imap.example.test']));$s=$p->connectionSettings();same('smtp.example.test',$s['smtp_host']);same('imap.example.test',$s['imap_host']);});
test('router extracts named route parameters',function(){$r=new Router();$seen=null;$r->get('/mailboxes/{id}',function($p)use(&$seen){$seen=$p['id'];});ob_start();$r->dispatch('GET','/mailboxes/123');ob_end_clean();same('123',$seen);});
test('MIME builder omits BCC header and supports drafts without To',function(){$b=new MimeMessageBuilder();$draft=$b->build(['from'=>'sender@example.test','subject'=>'Draft','body'=>'draft body','bcc'=>'hidden@example.test'],true);same(false,str_contains($draft,'To:'));same(true,str_contains($draft,'X-C0m-Draft-Bcc: hidden@example.test'));$sent=$b->build(['from'=>'sender@example.test','to'=>'to@example.test','bcc'=>'hidden@example.test','subject'=>'Hello','body'=>'body','attachments'=>[['name'=>'saved.txt','type'=>'text/plain','data'=>'kept bytes']]]);same(true,str_contains($sent,'To: to@example.test'));same(false,str_contains($sent,'Bcc:'));same(false,str_contains($sent,'X-C0m-Draft-Bcc:'));same(true,str_contains($sent,base64_encode('kept bytes')));});
test('HTML email sanitizer removes active content and unsafe attributes',function(){
 $clean=(new HtmlEmailSanitizer())->sanitize('<p onclick="alert(1)">Safe content</p><script>alert(2)</script><a href="javascript:alert(3)">unsafe</a><iframe src="https://evil.example"></iframe><custom-wrap><script>alert(4)</script><p>Nested safe text</p></custom-wrap>');
 same(false,(bool)preg_match('/<script|<iframe|onclick\\s*=|javascript:/i',$clean));
 same(true,str_contains($clean,'Safe content'));
 same(true,str_contains($clean,'Nested safe text'));
});
test('MIME builder folds long UTF-8 subjects and limits recipients',function(){
 $b=new MimeMessageBuilder();
 $raw=$b->build(['from'=>'sender@example.test','to'=>'one@example.test','subject'=>str_repeat('Tiếng Việt ',20),'body'=>'body']);
 $headers=explode("\r\n\r\n",$raw,2)[0];
 same(true,str_contains($headers,'=?UTF-8?B?'));
 same(false,(bool)preg_match('/^Bcc:/mi',$headers));
 foreach(explode("\r\n",$headers) as $line){if(str_starts_with($line,'Subject:')||str_starts_with($line,' '))same(true,strlen($line)<=78);}
 try{$b->build(['from'=>'sender@example.test','to'=>"victim@example.test\r\nBcc: attacker@example.test",'subject'=>'test','body'=>'']);throw new RuntimeException('Expected header injection refusal');}
 catch(InvalidArgumentException $e){same(true,str_contains($e->getMessage(),'Invalid email address'));}
 $recipients=array_fill(0,51,'user@example.test');
 try{$b->build(['from'=>'sender@example.test','to'=>$recipients,'subject'=>'test','body'=>'']);throw new RuntimeException('Expected recipient limit');}
 catch(InvalidArgumentException $e){same(true,str_contains($e->getMessage(),'50 recipients'));}
 try{$b->build(['from'=>'sender@example.test','to'=>array_fill(0,30,'to@example.test'),'cc'=>array_fill(0,20,'cc@example.test'),'bcc'=>'bcc@example.test','subject'=>'test','body'=>'']);throw new RuntimeException('Expected combined recipient limit');}
 catch(InvalidArgumentException $e){same(true,str_contains($e->getMessage(),'50 recipients'));}
});
test('SMTP validates endpoint syntax and port bounds before connecting',function(){
 try{(new SocketSmtpClient("smtp.example.test\r\n",587,'ssl','','',1))->send(['from'=>'sender@example.test','to'=>'to@example.test','subject'=>'test','body'=>'']);throw new RuntimeException('Expected invalid host refusal');}
 catch(InvalidArgumentException $e){same('Invalid SMTP host.',$e->getMessage());}
 try{new SocketSmtpClient('smtp.example.test',70000,'ssl','','',1);throw new RuntimeException('Expected invalid port refusal');}
 catch(InvalidArgumentException $e){same('Invalid SMTP port.',$e->getMessage());}
});
test('SMTP refuses plaintext authentication unless explicitly allowed',function(){
 $client=new SocketSmtpClient('127.0.0.1',25,'none','user@example.test','secret',1,null,false);
 try{$client->send(['from'=>'user@example.test','to'=>'recipient@example.test','subject'=>'test','body'=>'test']);throw new RuntimeException('Expected plaintext-authentication refusal');}
 catch(RuntimeException $e){same(true,str_contains($e->getMessage(),'unencrypted connection'));}
});
test('environment example exposes separate valid IMAP and SMTP settings',function(){
 $config=Config::load(dirname(__DIR__).'/.env.example');
 same('ssl',$config->get('MAIL_IMAP_ENCRYPTION'));
 same('20',$config->get('MAIL_IMAP_TIMEOUT'));
 same('starttls',$config->get('MAIL_SMTP_ENCRYPTION'));
 same('20',$config->get('MAIL_SMTP_TIMEOUT'));
 same('false',$config->get('MAIL_ALLOW_INSECURE_AUTH'));
 same('25',$config->get('MAIL_PAGE_SIZE'));
 same('INBOX',$config->get('MAIL_FOLDER_INBOX'));
});
test('password policy enforces minimum length and safe bounds',function(){
 $p=new PasswordPolicy();
 same(null,$p->validate('correct-horse-battery'));
 same('Password must be at least 14 characters long.',$p->validate('1234567890123'));
 same('Password must not exceed 1024 bytes.',$p->validate(str_repeat('x',1025)));
 same('Password contains an unsupported null character.',$p->validate("contains\0null"));
});
echo "{$count} tests passed.\n";
