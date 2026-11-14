<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Core\Config;
use App\Repositories\UserRepository;
use App\Repositories\MailboxRepository;
use App\Services\MailboxCredentialService;
use App\Services\LoginRateLimiter;

function dbCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo "PASS {$message}\n";}
$host=getenv('DB_HOST')?:'127.0.0.1';$port=getenv('DB_PORT')?:'3306';$database=getenv('DB_DATABASE')?:'c0mmail';$username=getenv('DB_USERNAME')?:'c0m';$password=getenv('DB_PASSWORD')?:'secret';
$pdo=new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",$username,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$users=new UserRepository($pdo);$mailboxes=new MailboxRepository($pdo);$email='db-test-'.bin2hex(random_bytes(5)).'@example.test';$mailboxEmail='mailbox-'.bin2hex(random_bytes(5)).'@example.test';$userId=0;$mailboxId=0;
try{
 $oldPassword='old-test-password-123';$userId=$users->create($email,'Integration Test',password_hash($oldPassword,PASSWORD_DEFAULT),'member');$user=$users->findByEmail($email);dbCheck($user!==null&&$user['role']==='member','MySQL user insert and lookup work');dbCheck(count(array_filter($users->allAccounts(),fn($a)=>(int)$a['id']===$userId))===1,'Admin account listing includes newly created members');
 $users->setMemberStatus($userId,'suspended');dbCheck($users->findById($userId)['status']==='suspended','Member suspension is persisted');$users->setMemberStatus($userId,'active');dbCheck($users->findById($userId)['status']==='active','Member reactivation is persisted');
 $oldHash=$users->passwordHashForId($userId);$newHash=password_hash('new-test-password-456',PASSWORD_DEFAULT);dbCheck($oldHash!==null&&$users->updatePasswordHash($userId,$oldHash,$newHash),'Password hash update uses compare-and-swap');
 dbCheck(password_verify('new-test-password-456',$users->passwordHashForId($userId)??''),'Updated password hash is persisted');dbCheck(!$users->updatePasswordHash($userId,$oldHash,password_hash('stale-password-789',PASSWORD_DEFAULT)),'Stale password update is rejected');
 $key=random_bytes(32);$rateLimiter=new LoginRateLimiter($pdo,new Config(['APP_KEY'=>'base64:'.base64_encode($key)]));$rateEmail='rate-'.bin2hex(random_bytes(4)).'@example.test';$rateIp='198.51.100.42';dbCheck(!$rateLimiter->isBlocked($rateEmail,$rateIp),'Fresh login identity is not throttled');for($attempt=0;$attempt<10;$attempt++)$rateLimiter->recordFailure($rateEmail,$rateIp);dbCheck($rateLimiter->isBlocked($rateEmail,$rateIp),'Ten failed login attempts trigger throttling');$storedAttempt=$pdo->query('SELECT email_hash,ip_hash FROM login_attempts ORDER BY id DESC LIMIT 1')->fetch();dbCheck(is_array($storedAttempt)&&strlen($storedAttempt['email_hash'])===64&&strlen($storedAttempt['ip_hash'])===64&&$storedAttempt['email_hash']!==$rateEmail&&$storedAttempt['ip_hash']!==$rateIp,'Login throttle stores HMAC identifiers instead of raw email or IP');$rateLimiter->recordSuccess($rateEmail);dbCheck(!$rateLimiter->isBlocked($rateEmail,$rateIp),'Successful login clears prior failed attempts');$sharedIp='203.0.113.77';for($attempt=0;$attempt<120;$attempt++)$rateLimiter->recordFailure('spray-'.$attempt.'@example.test',$sharedIp);dbCheck($rateLimiter->isBlocked('different-user@example.test',$sharedIp),'IP-wide login throttling limits password spraying across many email addresses');
 $nonce=random_bytes(12);$tag='';$cipher=openssl_encrypt('mailbox-secret-one','aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag);if($cipher===false)throw new RuntimeException('Test credential encryption failed.');
 $mailboxId=$mailboxes->create(['user_id'=>$userId,'email'=>$mailboxEmail,'display_name'=>'Test mailbox','provider'=>'manual','provider_id'=>null,'status'=>'active','ciphertext'=>base64_encode($cipher),'nonce'=>bin2hex($nonce),'tag'=>bin2hex($tag)]);
 dbCheck($mailboxes->findForUser($mailboxId,$userId)!==null,'Mailbox ownership lookup works');dbCheck($mailboxes->findForUser($mailboxId,$userId+1)===null,'Mailbox credentials are scoped to their owner');
 $config=new Config(['APP_KEY'=>'base64:'.base64_encode($key)]);$credentialService=new MailboxCredentialService($mailboxes,$config);dbCheck($credentialService->forUser($mailboxId,$userId)['password']==='mailbox-secret-one','AES-256-GCM mailbox credentials decrypt for the owning member');dbCheck($credentialService->forAdmin($mailboxId)['password']==='mailbox-secret-one','Admin-only mailbox connection test can decrypt active mailbox credentials');
 $nonce2=random_bytes(12);$tag2='';$cipher2=openssl_encrypt('mailbox-secret-two','aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce2,$tag2);$mailboxes->updateCredentials($mailboxId,base64_encode($cipher2),bin2hex($nonce2),bin2hex($tag2));
 dbCheck($credentialService->forUser($mailboxId,$userId)['password']==='mailbox-secret-two','Rotated mailbox credentials persist and decrypt correctly');
}finally{
 if($mailboxId>0){$s=$pdo->prepare('DELETE FROM mailboxes WHERE id=?');$s->execute([$mailboxId]);}
 if($userId>0){$s=$pdo->prepare('DELETE FROM users WHERE id=?');$s->execute([$userId]);}
}
echo "MySQL integration suite passed.\n";
