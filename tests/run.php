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
test('password policy enforces minimum length and safe bounds',function(){
 $p=new PasswordPolicy();
 same(null,$p->validate('correct-horse-battery'));
 same('Password must be at least 14 characters long.',$p->validate('1234567890123'));
 same('Password must not exceed 1024 bytes.',$p->validate(str_repeat('x',1025)));
 same('Password contains an unsupported null character.',$p->validate("contains\0null"));
});
echo "{$count} tests passed.\n";
