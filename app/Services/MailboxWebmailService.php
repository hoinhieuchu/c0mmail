<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Config;
final class MailboxWebmailService
{
 public function __construct(private MailTransportFactory $transports,private Config $config,private MimeMessageBuilder $mime){}
 public function folders(array $credentials):array{$imap=$this->transports->imap($credentials);try{return $imap->folders();}finally{$imap->close();}}
 public function messages(array $credentials,string $folder,int $page=1,?string $query=null):array
 {
  $page=max(1,$page);$imap=$this->transports->imap($credentials);try{return $imap->messages($folder,$page,min(50,max(1,(int)$this->config->get('MAIL_PAGE_SIZE','25'))),$query);}finally{$imap->close();}
 }
 public function message(array $credentials,string $folder,int $uid):array{$imap=$this->transports->imap($credentials);try{return $imap->message($folder,$uid);}finally{$imap->close();}}
 public function flag(array $credentials,string $folder,int $uid,string $flag,bool $enabled):void{$imap=$this->transports->imap($credentials);try{$imap->setFlag($folder,$uid,$flag,$enabled);}finally{$imap->close();}}
 public function move(array $credentials,string $folder,int $uid,string $destination):void{$imap=$this->transports->imap($credentials);try{$imap->move($folder,$uid,$destination);}finally{$imap->close();}}
 public function delete(array $credentials,string $folder,int $uid):void
 {
  $folders=$this->folders($credentials);$trash=$this->roleFolder($folders,'trash');
  if($trash!==null&&$folder!==$trash)$this->move($credentials,$folder,$uid,$trash);else{$imap=$this->transports->imap($credentials);try{$imap->delete($folder,$uid);}finally{$imap->close();}}
 }
 public function createFolder(array $credentials,string $name):void{$imap=$this->transports->imap($credentials);try{$imap->createFolder($name);}finally{$imap->close();}}
 public function download(array $credentials,string $folder,int $uid,string $part):array{$imap=$this->transports->imap($credentials);try{return $imap->downloadPart($folder,$uid,$part);}finally{$imap->close();}}
 public function saveDraft(array $credentials,array $message):void
 {
  $folders=$this->folders($credentials);$drafts=$this->roleFolder($folders,'drafts');if($drafts===null)throw new \RuntimeException('The mail server does not expose a Drafts folder.');
  $raw=$this->mime->build($message,true);$imap=$this->transports->imap($credentials);try{$imap->append($drafts,$raw,'\\Draft');}finally{$imap->close();}
 }

 public function replaceDraft(array $credentials,string $folder,int $uid,array $message):void
 {
  if($uid<1)throw new \InvalidArgumentException('Invalid draft identifier.');
  $original=$this->message($credentials,$folder,$uid);$message['attachments']=array_merge($this->preserveAttachments($credentials,$folder,$uid,$original['attachments']),$message['attachments']??[]);if(count($message['attachments'])>5)throw new \InvalidArgumentException('A maximum of five attachments is allowed per message.');
  $raw=$this->mime->build($message,true);$imap=$this->transports->imap($credentials);
  try{$imap->append($folder,$raw,'\\Draft');$imap->delete($folder,$uid);}finally{$imap->close();}
 }
 public function sendDraft(array $credentials,string $folder,int $uid,array $message):array
 {
  if($uid<1)throw new \InvalidArgumentException('Invalid draft identifier.');
  $original=$this->message($credentials,$folder,$uid);$message['attachments']=array_merge($this->preserveAttachments($credentials,$folder,$uid,$original['attachments']),$message['attachments']??[]);if(count($message['attachments'])>5)throw new \InvalidArgumentException('A maximum of five attachments is allowed per message.');
  $sentCopySaved=$this->send($credentials,$message);$draftRemoved=false;$imap=null;
  try{$imap=$this->transports->imap($credentials);$imap->delete($folder,$uid);$draftRemoved=true;}catch(\Throwable $e){error_log('Draft cleanup failed after SMTP delivery was accepted.');}finally{if($imap!==null)$imap->close();}return ['sent_copy_saved'=>$sentCopySaved,'draft_removed'=>$draftRemoved];
 }
 private function preserveAttachments(array $credentials,string $folder,int $uid,array $metadata):array
 {
  $files=[];foreach($metadata as $attachment){$file=$this->download($credentials,$folder,$uid,(string)$attachment['part']);$files[]=['name'=>$file['name'],'type'=>$file['type'],'data'=>$file['data']];}
  return $files;
 }
 public function send(array $credentials,array $message):bool
 {
  $message['from']=$credentials['email'];$raw=$this->mime->build($message);$message['raw']=$raw;
  $this->transports->smtp($credentials)->send($message);
  $saved=false;
  try{
   $folders=$this->folders($credentials);$sent=$this->roleFolder($folders,'sent');
   if($sent!==null){$imap=$this->transports->imap($credentials);try{$imap->append($sent,$raw,'\\Seen');$saved=true;}finally{$imap->close();}}
  }catch(\Throwable $e){error_log('Sent-copy append failed; SMTP delivery already accepted.');}
  return $saved;
 }
 private function roleFolder(array $folders,string $role):?string{$configured=trim((string)$this->config->get('MAIL_FOLDER_'.strtoupper($role),''));if($configured!==''){foreach($folders as $f)if(strcasecmp((string)$f['path'],$configured)===0)return $f['path'];}foreach($folders as $f)if(($f['role']??'')===$role)return $f['path'];return null;}
}
