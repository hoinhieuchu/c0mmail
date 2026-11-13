<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Config;
use App\Repositories\MailboxRepository;
final class MailboxCredentialService
{
 public function __construct(private MailboxRepository $mailboxes,private Config $config){}
 public function forUser(int $mailboxId,int $userId):array
 {
  $m=$this->mailboxes->findForUser($mailboxId,$userId);
  if(!$m||$m['status']!=='active')throw new \RuntimeException('Mailbox is unavailable.');
  if(!$m['password_ciphertext']||!$m['password_nonce']||!$m['password_tag'])throw new \RuntimeException('Mailbox credentials are not configured.');
  $raw=$this->config->required('APP_KEY');$key=str_starts_with($raw,'base64:')?base64_decode(substr($raw,7),true):false;
  if($key===false||strlen($key)!==32)throw new \RuntimeException('Invalid application encryption key.');
  $cipher=base64_decode($m['password_ciphertext'],true);
  $password=openssl_decrypt($cipher,'aes-256-gcm',$key,OPENSSL_RAW_DATA,hex2bin($m['password_nonce']),hex2bin($m['password_tag']));
  if($password===false)throw new \RuntimeException('Mailbox credential decryption failed.');
  return ['id'=>(int)$m['id'],'email'=>$m['email'],'display_name'=>$m['display_name'],'password'=>$password];
 }
}
