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
  $mailbox=$this->mailboxes->findForUser($mailboxId,$userId);
  if(!$mailbox||$mailbox['status']!=='active')throw new \RuntimeException('Mailbox is unavailable.');
  return $this->decrypt($mailbox);
 }
 public function forAdmin(int $mailboxId):array
 {
  $mailbox=$this->mailboxes->find($mailboxId);
  if(!$mailbox||!in_array($mailbox['status'],['active','manual_pending'],true))throw new \RuntimeException('Mailbox is unavailable.');
  return $this->decrypt($mailbox);
 }
 private function decrypt(array $mailbox):array
 {
  if(!$mailbox['password_ciphertext']||!$mailbox['password_nonce']||!$mailbox['password_tag'])throw new \RuntimeException('Mailbox credentials are not configured.');
  $raw=$this->config->required('APP_KEY');$key=str_starts_with($raw,'base64:')?base64_decode(substr($raw,7),true):false;
  if($key===false||strlen($key)!==32)throw new \RuntimeException('Invalid application encryption key.');
  $cipher=base64_decode($mailbox['password_ciphertext'],true);
  if($cipher===false)throw new \RuntimeException('Mailbox credential decryption failed.');
  $nonce=hex2bin($mailbox['password_nonce']);$tag=hex2bin($mailbox['password_tag']);
  if($nonce===false||$tag===false)throw new \RuntimeException('Mailbox credential decryption failed.');
  $password=openssl_decrypt($cipher,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag);
  if($password===false)throw new \RuntimeException('Mailbox credential decryption failed.');
  return ['id'=>(int)$mailbox['id'],'email'=>$mailbox['email'],'display_name'=>$mailbox['display_name'],'password'=>$password];
 }
}
