<?php
declare(strict_types=1);
namespace App\Services;
use App\Contracts\ImapClientInterface;
use App\Contracts\SmtpClientInterface;
use App\Core\Config;
use App\Providers\NativeImapClient;
use App\Providers\SocketSmtpClient;
final class MailTransportFactory
{
 public function __construct(private Config $config){}
 public function imap(array $mailbox):ImapClientInterface
 {
  return new NativeImapClient($this->config->required('MAIL_IMAP_HOST'),(int)$this->config->get('MAIL_IMAP_PORT','993'),$this->config->get('MAIL_IMAP_ENCRYPTION','ssl'),$mailbox['email'],$mailbox['password'],(int)$this->config->get('MAIL_ATTACHMENT_MAX_BYTES','10485760'),$this->folderRoles());
 }
 private function folderRoles():array{return ['inbox'=>$this->config->get('MAIL_FOLDER_INBOX','INBOX'),'sent'=>$this->config->get('MAIL_FOLDER_SENT',''),'drafts'=>$this->config->get('MAIL_FOLDER_DRAFTS',''),'spam'=>$this->config->get('MAIL_FOLDER_SPAM',''),'trash'=>$this->config->get('MAIL_FOLDER_TRASH','')];}
 public function smtp(array $mailbox):SmtpClientInterface
 {
  return new SocketSmtpClient($this->config->required('MAIL_SMTP_HOST'),(int)$this->config->get('MAIL_SMTP_PORT','587'),$this->config->get('MAIL_SMTP_ENCRYPTION','starttls'),$mailbox['email'],$mailbox['password'],(int)$this->config->get('MAIL_SMTP_TIMEOUT','20'),null,strtolower((string)$this->config->get('MAIL_ALLOW_INSECURE_AUTH','false'))==='true');
 }
}
