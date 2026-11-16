<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Core\Router;use App\Core\Config;use App\Providers\ManualMailProvider;use App\Providers\CraneMailProvider;
$count=0;
function test(string $name,callable $fn):void{global $count;$fn();$count++;echo "PASS {$name}\n";}
function same($expected,$actual):void{if($expected!==$actual)throw new RuntimeException('Expected '.var_export($expected,true).', got '.var_export($actual,true));}
test('autoload resolves application classes',fn()=>same(true,class_exists(Router::class)));
test('manual mode never reports remote provisioning as active',function(){$p=new ManualMailProvider(new Config(['MAIL_SMTP_HOST'=>'smtp.example.test']));$r=$p->provision(['email'=>'u@example.test','password'=>'secret','display_name'=>'User']);same('manual_pending',$r['status']);same(null,$r['provider_id']);same('smtp.example.test',$p->connectionSettings()['smtp_host']);});
test('manual provider refuses pretend remote suspension',function(){$p=new ManualMailProvider(new Config([]));try{$p->suspend('');throw new RuntimeException('Expected refusal');}catch(LogicException $e){same(true,str_contains($e->getMessage(),'control panel'));}});
test('CraneMail adapter rejects non-HTTPS endpoints',function(){$p=new CraneMailProvider(new Config(['CRANEMAIL_API_BASE_URL'=>'http://invalid.example','CRANEMAIL_API_TOKEN'=>'test','CRANEMAIL_PROVISION_PATH'=>'/mailboxes']));try{$p->provision(['email'=>'x@example.test','password'=>'secret','display_name'=>'X']);throw new RuntimeException('Expected HTTPS guard');}catch(RuntimeException $e){same(true,str_contains($e->getMessage(),'HTTPS'));}});
test('CraneMail adapter exposes SMTP and IMAP transport settings',function(){$p=new CraneMailProvider(new Config(['MAIL_SMTP_HOST'=>'smtp.example.test','MAIL_IMAP_HOST'=>'imap.example.test']));$s=$p->connectionSettings();same('smtp.example.test',$s['smtp_host']);same('imap.example.test',$s['imap_host']);});
test('router extracts named route parameters',function(){$r=new Router();$seen=null;$r->get('/mailboxes/{id}',function($p)use(&$seen){$seen=$p['id'];});ob_start();$r->dispatch('GET','/mailboxes/123');ob_end_clean();same('123',$seen);});

test('SMTP adapter rejects header injection before opening a connection',function(){ $p=new \App\Services\SmtpClient(); try{$p->send(['smtp_host'=>'smtp.example.test','smtp_port'=>'587','smtp_encryption'=>'starttls'],['email'=>'user@example.test','password'=>'secret'],'user@example.test',['to@example.test'],"Hello\r\nBcc: attacker@example.test",'body');throw new RuntimeException('Expected header injection rejection');}catch(InvalidArgumentException $e){same(true,str_contains($e->getMessage(),'header'));}});
test('SMTP adapter rejects invalid recipients before opening a connection',function(){ $p=new \App\Services\SmtpClient(); try{$p->send(['smtp_host'=>'smtp.example.test','smtp_port'=>'587','smtp_encryption'=>'starttls'],['email'=>'user@example.test','password'=>'secret'],'user@example.test',['not-an-email'],'Subject','body');throw new RuntimeException('Expected recipient validation');}catch(InvalidArgumentException $e){same(true,str_contains($e->getMessage(),'recipient'));}});
test('IMAP adapter requires the extension to be available in runtime',function(){same(true,(new \App\Services\ImapClient())->available());});

test('SMTP adapter refuses plaintext transport before connecting',function(){ $p=new \App\Services\SmtpClient(); try{$p->send(['smtp_host'=>'smtp.example.test','smtp_port'=>'25','smtp_encryption'=>'none'],['email'=>'user@example.test','password'=>'secret'],'user@example.test',['to@example.test'],'Subject','body');throw new RuntimeException('Expected encryption requirement');}catch(RuntimeException $e){same(true,str_contains($e->getMessage(),'SSL or STARTTLS'));}});
test('SMTP adapter enforces recipient count limit before connecting',function(){ $p=new \App\Services\SmtpClient(); try{$p->send(['smtp_host'=>'smtp.example.test','smtp_port'=>'587','smtp_encryption'=>'starttls'],['email'=>'user@example.test','password'=>'secret'],'user@example.test',array_map(static fn(int $i): string => 'to'.$i.'@example.test', range(1,51)),'Subject','body');throw new RuntimeException('Expected recipient limit');}catch(InvalidArgumentException $e){same(true,str_contains($e->getMessage(),'50 recipients'));}});

test('IMAP search excludes deleted messages and quotes text queries',function(){same('UNDELETED',\App\Services\ImapClient::buildSearchCriteria(null));same('UNDELETED TEXT "quarterly report"',\App\Services\ImapClient::buildSearchCriteria('quarterly report'));});
test('IMAP empty search result is distinct from protocol failure',function(){same([],\App\Services\ImapClient::normalizeSearchResult(false,[]));same([12,13],\App\Services\ImapClient::normalizeSearchResult([12,'13'],[]));foreach([[false,['NO [SERVERBUG] backend failure']],[ [12], ['WARNING partial result'] ]] as [$result,$errors]){try{\App\Services\ImapClient::normalizeSearchResult($result,$errors);throw new RuntimeException('Expected IMAP search failure');}catch(RuntimeException $e){same(true,str_contains($e->getMessage(),'IMAP search failed'));}}});
test('IMAP search criteria rejects CRLF injection',function(){try{\App\Services\ImapClient::buildSearchCriteria("hello\r\nBcc: attacker@example.test");throw new RuntimeException('Expected search query rejection');}catch(InvalidArgumentException $e){same(true,str_contains($e->getMessage(),'unsupported'));}});
test('IMAP UID cursors produce bounded older and newer searches',function(){same('UID 1:49 UNDELETED',\App\Services\ImapClient::buildUidSearchCriteria(null,50));same('UID 51:* UNDELETED TEXT "invoice"',\App\Services\ImapClient::buildUidSearchCriteria('invoice',null,50));});
test('IMAP older cursor at UID one returns an empty page without opening a connection',function(){same([], (new \App\Services\ImapClient())->listMessages([],[],'INBOX',50,0,null,1,null));});
test('IMAP UID cursors reject conflicting and invalid ranges',function(){foreach([[null,1,1],[null,1,null],[null,null,PHP_INT_MAX]] as $args){try{\App\Services\ImapClient::buildUidSearchCriteria($args[0],$args[1],$args[2]);throw new RuntimeException('Expected cursor validation');}catch(InvalidArgumentException $e){same(true,str_contains($e->getMessage(),'cursor'));}}});

test('MIME builder supports empty subject and plain text bodies',function(){ $raw=\App\Services\MimeMessageBuilder::build('user@example.test',['to@example.test'],'','hello'); same(true,str_contains($raw,'Content-Type: text/plain; charset=UTF-8')); same(true,str_contains($raw,'hello'));});
test('MIME builder emits multipart HTML and base64 attachments',function(){ $raw=\App\Services\MimeMessageBuilder::build('user@example.test',['to@example.test'],'Hello','plain','<p>html</p>',[['filename'=>'report.txt','mime'=>'text/plain','data'=>'attachment payload']]); same(true,str_contains($raw,'multipart/mixed')); same(true,str_contains($raw,'multipart/alternative')); same(true,str_contains($raw,base64_encode('attachment payload')));});
test('MIME builder rejects header injection in subjects',function(){try{\App\Services\MimeMessageBuilder::build('user@example.test',['to@example.test'],"Hi\r\nBcc: attacker@example.test",'body');throw new RuntimeException('Expected MIME header validation');}catch(InvalidArgumentException $e){same(true,str_contains($e->getMessage(),'header'));}});

test('MIME builder preserves reply threading headers',function(){ $raw=\App\Services\MimeMessageBuilder::build('user@example.test',['to@example.test'],'Re: Hello','reply',null,[],['in_reply_to'=>'<one@example.test>','references'=>'<one@example.test>']); same(true,str_contains($raw,'In-Reply-To: <one@example.test>')); same(true,str_contains($raw,'References: <one@example.test>'));});
test('MIME builder accepts a stable validated Message-ID',function(){ $raw=\App\Services\MimeMessageBuilder::build('user@example.test',['to@example.test'],'Hello','body',null,[],[],'<submission-123@c0m.me>'); same(true,str_contains($raw,'Message-ID: <submission-123@c0m.me>'));});
test('MIME builder rejects malformed Message-ID values',function(){try{\App\Services\MimeMessageBuilder::build('user@example.test',['to@example.test'],'Hello','body',null,[],[],"<valid@c0m.me>\\r\\nBcc:bad@example.test");throw new RuntimeException('Expected Message-ID validation');}catch(InvalidArgumentException $e){same(true,str_contains($e->getMessage(),'Message-ID'));}});
test('MIME builder rejects CRLF in reply threading headers',function(){try{\App\Services\MimeMessageBuilder::build('user@example.test',['to@example.test'],'Re: Hello','reply',null,[],['in_reply_to'=>"x\r\nBcc: bad@example.test"]);throw new RuntimeException('Expected thread header rejection');}catch(InvalidArgumentException $e){same(true,str_contains($e->getMessage(),'header'));}});

test('IMAP folder discovery strips the server connection prefix',function(){same('INBOX.Sent',\App\Services\ImapClient::stripServerPrefix('{imap.example.test:993/imap/ssl}INBOX.Sent','{imap.example.test:993/imap/ssl}'));same('INBOX',\App\Services\ImapClient::stripServerPrefix('INBOX','{imap.example.test:993/imap/ssl}'));});
test('IMAP folder names reject control characters and mailbox URL delimiters',function(){same('INBOX/Thư đến',\App\Services\ImapClient::validateFolderName('INBOX/Thư đến'));foreach (["INBOX\r\n{evil}",'{imap.example.test}','INBOX' . chr(0) . 'bad'] as $folder){try{\App\Services\ImapClient::validateFolderName($folder);throw new RuntimeException('Expected unsafe folder rejection');}catch(InvalidArgumentException $e){same(true,str_contains($e->getMessage(),'folder'));}}});

test('durable send attempt repository autoloads',function(){same(true,class_exists(\App\Repositories\SendAttemptRepository::class));});
test('webmail controller and transport services autoload',function(){same(true,class_exists(\App\Controllers\WebmailController::class));same(true,class_exists(\App\Services\ImapClient::class));same(true,class_exists(\App\Services\SmtpClient::class));same(true,class_exists(\App\Services\MimeMessageBuilder::class));});

test('submission state keeps pending and accepted attempts stable',function(){same('pending',\App\Services\SubmissionState::classify(['status'=>'pending'],100));same('accepted',\App\Services\SubmissionState::classify(['status'=>'accepted'],100));});
test('submission state rejects a fresh in-flight duplicate without expiring it',function(){same('sending',\App\Services\SubmissionState::classify(['status'=>'sending','started_at'=>100],150));});
test('submission state marks stale in-flight attempts uncertain',function(){same('uncertain',\App\Services\SubmissionState::classify(['status'=>'sending','started_at'=>100],1001));});
test('submission state treats legacy or unknown states as uncertain',function(){same('uncertain',\App\Services\SubmissionState::classify(['status'=>'sending'],100));same('uncertain',\App\Services\SubmissionState::classify(['status'=>'unexpected'],100));});

test('IMAP attachment decoder preserves ENCOTHER transfer data',function(){same('opaque payload',\App\Services\ImapClient::decodeTransferEncoding('opaque payload',5));});
test('IMAP attachment decoder handles base64 and quoted-printable',function(){same('binary data',\App\Services\ImapClient::decodeTransferEncoding(base64_encode('binary data'),3));same('hello world',\App\Services\ImapClient::decodeTransferEncoding('hello=20world',4));});
test('IMAP attachment decoder rejects invalid encodings and malformed base64',function(){try{\App\Services\ImapClient::decodeTransferEncoding('payload',6);throw new RuntimeException('Expected unsupported-encoding rejection');}catch(InvalidArgumentException $e){same(true,str_contains($e->getMessage(),'encoding'));}try{\App\Services\ImapClient::decodeTransferEncoding('%%%invalid%%%',3);throw new RuntimeException('Expected malformed-base64 rejection');}catch(RuntimeException $e){same(true,str_contains($e->getMessage(),'base64'));}});

test('IMAP filename parser decodes RFC 2231 UTF-8 continuations',function(){$method=new ReflectionMethod(\App\Services\ImapClient::class,'partFilename');$method->setAccessible(true);$structure=(object)['parameters'=>[],'dparameters'=>[(object)['attribute'=>'filename*1*','value'=>'th%C3%A1ng.xlsx'],(object)['attribute'=>'filename*0*','value'=>"UTF-8''B%C3%A1o%20c%C3%A1o%20"]]];same('Báo cáo tháng.xlsx',$method->invoke(new \App\Services\ImapClient(),$structure));});
test('IMAP filename parser prefers RFC 2231 extended values over legacy names',function(){$method=new ReflectionMethod(\App\Services\ImapClient::class,'partFilename');$method->setAccessible(true);$structure=(object)['parameters'=>[(object)['attribute'=>'filename','value'=>'report.txt']],'dparameters'=>[(object)['attribute'=>'filename*','value'=>"UTF-8''b%C3%A1o-c%C3%A1o.txt"]]];same('báo-cáo.txt',$method->invoke(new \App\Services\ImapClient(),$structure));});
test('IMAP filename parser falls back to ordinary MIME filename parameters',function(){$method=new ReflectionMethod(\App\Services\ImapClient::class,'partFilename');$method->setAccessible(true);$structure=(object)['parameters'=>[(object)['attribute'=>'name','value'=>'legacy.txt']],'dparameters'=>[]];same('legacy.txt',$method->invoke(new \App\Services\ImapClient(),$structure));});

test('IMAP filename parser accepts stdClass parameter collections from ext-imap',function(){$method=new ReflectionMethod(\App\Services\ImapClient::class,'partFilename');$method->setAccessible(true);$parameters=(object)['0'=>(object)['attribute'=>'filename','value'=>'object-shape.txt']];$structure=(object)['parameters'=>$parameters,'dparameters'=>(object)[]];same('object-shape.txt',$method->invoke(new \App\Services\ImapClient(),$structure));});
test('MIME builder emits Cc but never exposes Bcc in message headers',function(){
 $raw=\App\Services\MimeMessageBuilder::build('sender@example.test',['to@example.test'],'Hello','body',null,[],[],null,['copy@example.test'],['blind@example.test']);
 same(true,str_contains($raw,'Cc: copy@example.test'));
 same(false,str_contains($raw,'Bcc:'));
});
test('MIME builder validates recipients across To Cc and Bcc',function(){
 try{\App\Services\MimeMessageBuilder::build('sender@example.test',['to@example.test'],'Hello','body',null,[],[],null,['bad-address'],[]);throw new RuntimeException('Expected validation');}
 catch(InvalidArgumentException $e){same(true,str_contains($e->getMessage(),'recipient'));}
});
test('MIME builder encodes sender display name and emits Reply-To',function(){
 $raw=\App\Services\MimeMessageBuilder::build('sender@example.test',['to@example.test'],'Báo cáo tháng','body',null,[],[],null,[],[],'Nguyễn Văn A','reply@example.test');
 same(true,str_contains($raw,'From: '));
 same(true,str_contains($raw,'=?UTF-8?B?'));
 same(true,str_contains($raw,'Reply-To: <reply@example.test>'));
});
test('MIME builder rejects invalid Reply-To and sender display-name injection',function(){
 foreach ([['reply'=>'not-an-email','name'=>'Sender'],['reply'=>null,'name'=>"Sender\r\nBcc: attacker@example.test"]] as $case) {
  try{\App\Services\MimeMessageBuilder::build('sender@example.test',['to@example.test'],'Hello','body',null,[],[],null,[],[],$case['name'],$case['reply']);throw new RuntimeException('Expected display-name or Reply-To validation');}
  catch(InvalidArgumentException $e){same(true,str_contains(strtolower($e->getMessage()),'reply-to') || str_contains(strtolower($e->getMessage()),'header'));}
 }
});
test('SMTP delivery exception exposes whether remote acceptance is uncertain',function(){
 $safe=new \App\Services\SmtpDeliveryException('safe failure',false);
 $uncertain=new \App\Services\SmtpDeliveryException('ambiguous DATA result',true);
 same(false,$safe->deliveryUncertain);
 same(true,$uncertain->deliveryUncertain);
});
test('IMAP message retrieval enforces the 40 MB size boundary',function(){
 \App\Services\ImapClient::validateMessageSize(0);
 \App\Services\ImapClient::validateMessageSize(40_000_000);
 foreach ([-1,40_000_001] as $bytes) {
  try{\App\Services\ImapClient::validateMessageSize($bytes);throw new RuntimeException('Expected message size rejection');}
  catch(RuntimeException $e){same(true,str_contains($e->getMessage(),'40 MB'));}
 }
});

test('SMTP explicit 4xx and 5xx replies are definitive rejections',function(){
 foreach([421,450,550,599] as $code){
  $pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,STREAM_IPPROTO_IP);
  if($pair===false)throw new RuntimeException('Unable to create SMTP protocol test socket pair');
  fwrite($pair[1],$code." rejection\r\n");
  $method=new ReflectionMethod(\App\Services\SmtpClient::class,'expect');
  try{$method->invoke(new \App\Services\SmtpClient(),$pair[0],[250]);throw new RuntimeException('Expected SMTP protocol rejection');}
  catch(\App\Services\SmtpProtocolException $e){same($code,$e->responseCode);same(true,$e->isNegativeReply());}
  finally{fclose($pair[0]);fclose($pair[1]);}
 }
});
test('SMTP unexpected non-negative replies remain ambiguous after DATA',function(){
 $pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,STREAM_IPPROTO_IP);
 if($pair===false)throw new RuntimeException('Unable to create SMTP protocol test socket pair');
 fwrite($pair[1],"354 unexpected intermediate reply\r\n");
 $method=new ReflectionMethod(\App\Services\SmtpClient::class,'expect');
 try{$method->invoke(new \App\Services\SmtpClient(),$pair[0],[250]);throw new RuntimeException('Expected SMTP protocol rejection');}
 catch(\App\Services\SmtpProtocolException $e){same(354,$e->responseCode);same(false,$e->isNegativeReply());}
 finally{fclose($pair[0]);fclose($pair[1]);}
});
test('SMTP connection loss without a final reply is not misclassified as a negative response',function(){
 $pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,STREAM_IPPROTO_IP);
 if($pair===false)throw new RuntimeException('Unable to create SMTP protocol test socket pair');
 fclose($pair[1]);
 $method=new ReflectionMethod(\App\Services\SmtpClient::class,'expect');
 try{$method->invoke(new \App\Services\SmtpClient(),$pair[0],[250]);throw new RuntimeException('Expected SMTP connection-loss exception');}
 catch(\App\Services\SmtpProtocolException $e){throw new RuntimeException('A missing SMTP reply must not be represented as a protocol rejection');}
 catch(\RuntimeException $e){same(true,str_contains($e->getMessage(),'closed the connection'));}
 finally{fclose($pair[0]);}
});
test('login rate-limit repository autoloads',function(){same(true,class_exists(\App\Repositories\LoginRateLimitRepository::class));});

test('conversation grouper follows RFC References and In-Reply-To chains',function(){
 $messages=[
  ['uid'=>10,'subject'=>'Quarterly report','from'=>'a@example.test','date'=>'2026-01-01','message_id'=>'<root@example.test>','references'=>'','in_reply_to'=>''],
  ['uid'=>11,'subject'=>'Re: Quarterly report','from'=>'b@example.test','date'=>'2026-01-02','message_id'=>'<reply@example.test>','references'=>'','in_reply_to'=>'<root@example.test>','seen'=>false],
  ['uid'=>12,'subject'=>'Re: Quarterly report','from'=>'c@example.test','date'=>'2026-01-03','message_id'=>'<followup@example.test>','references'=>'','in_reply_to'=>'<reply@example.test>'],
  ['uid'=>20,'subject'=>'Quarterly report','from'=>'d@example.test','date'=>'2026-01-04','message_id'=>'<separate@example.test>','references'=>'','in_reply_to'=>''],
 ];
 $groups=\App\Services\ConversationGrouper::group($messages);
 same(2,count($groups));
 same(20,$groups[0]['uid']);
 same(12,$groups[1]['uid']);
 same(3,$groups[1]['thread_count']);
 same(false,$groups[1]['seen']);
 same([12,11,10],$groups[1]['thread_uids']);
});
test('conversation grouper does not merge unrelated messages with matching subjects',function(){
 $groups=\App\Services\ConversationGrouper::group([
  ['uid'=>1,'subject'=>'Hello','message_id'=>'<one@example.test>'],
  ['uid'=>2,'subject'=>'Hello','message_id'=>'<two@example.test>'],
 ]);
 same(2,count($groups));
});

test('HTML email sanitizer strips scripts, event handlers, and unsafe links',function(){
 $clean=\App\Services\HtmlEmailSanitizer::sanitize('<p onclick="evil()">Hello <strong>safe</strong><script>alert(1)</script><a href="javascript:alert(1)" onclick="evil()">bad</a><a href="https://example.test">link</a></p>');
 same(false,str_contains(strtolower((string)$clean),'<script'));
 same(false,str_contains(strtolower((string)$clean),'onclick'));
 same(false,str_contains(strtolower((string)$clean),'javascript:'));
 same(true,str_contains((string)$clean,'<strong>safe</strong>'));
 same(true,str_contains((string)$clean,'href="https://example.test"'));
});
test('MIME builder sanitizes HTML before sending and archiving',function(){
 $raw=\App\Services\MimeMessageBuilder::build('sender@example.test',['to@example.test'],'Hello','plain','<p onclick="x()">safe<script>unsafe()</script></p>');
 same(false,str_contains(strtolower($raw),'<script'));
 same(false,str_contains(strtolower($raw),'onclick'));
 same(true,str_contains($raw,'<p>safe</p>'));
});

test('client IP resolver ignores forwarding headers from untrusted peers',function(){
 same('203.0.113.10',\App\Services\ClientIpResolver::resolve(['REMOTE_ADDR'=>'203.0.113.10','HTTP_CF_CONNECTING_IP'=>'198.51.100.20'],[]));
});
test('client IP resolver accepts Cloudflare client IP only from trusted proxy CIDRs',function(){
 same('198.51.100.20',\App\Services\ClientIpResolver::resolve(['REMOTE_ADDR'=>'203.0.113.10','HTTP_CF_CONNECTING_IP'=>'198.51.100.20'],['203.0.113.0/24']));
 same('203.0.113.10',\App\Services\ClientIpResolver::resolve(['REMOTE_ADDR'=>'203.0.113.10','HTTP_CF_CONNECTING_IP'=>'198.51.100.20'],['192.0.2.0/24']));
});
test('client IP resolver supports trusted IPv6 proxies and rejects invalid CIDRs',function(){
 same('2001:db8::20',\App\Services\ClientIpResolver::resolve(['REMOTE_ADDR'=>'2001:db8::10','HTTP_X_REAL_IP'=>'2001:db8::20'],['2001:db8::/32']));
 same('2001:db8::10',\App\Services\ClientIpResolver::resolve(['REMOTE_ADDR'=>'2001:db8::10','HTTP_X_REAL_IP'=>'2001:db8::20'],['not-a-cidr']));
});

test('IMAP attachment reference validator accepts bounded references',function(){
 same(null,\App\Services\ImapClient::validateAttachmentReference(12,'1.2.3',3));
 same(null,\App\Services\ImapClient::validateAttachmentReference(1,'2',0));
});
test('IMAP attachment reference validator rejects invalid UID, section, and encoding',function(){
 foreach([[0,'1',3],[1,'1.2x',3],[1,str_repeat('1.',30).'1',3],[1,'1',99]] as [$uid,$section,$encoding]){
  try{\App\Services\ImapClient::validateAttachmentReference($uid,$section,$encoding);throw new RuntimeException('Expected invalid attachment reference');}
  catch(InvalidArgumentException $e){same('Invalid attachment reference.',$e->getMessage());}
 }
});

test('IMAP conversation lookup rejects invalid message UIDs before connecting',function(){
 try{(new \App\Services\ImapClient())->conversationMessages([],[],'INBOX',0);throw new RuntimeException('Expected invalid UID rejection');}
 catch(InvalidArgumentException $e){same('Invalid message identifier.',$e->getMessage());}
});
test('IMAP conversation lookup enforces bounded result limits',function(){
 foreach([0,101] as $limit){
  try{(new \App\Services\ImapClient())->conversationMessages([],[],'INBOX',1,$limit);throw new RuntimeException('Expected conversation limit rejection');}
  catch(InvalidArgumentException $e){same('Invalid conversation message limit.',$e->getMessage());}
 }
});
echo "{$count} tests passed.\n";
