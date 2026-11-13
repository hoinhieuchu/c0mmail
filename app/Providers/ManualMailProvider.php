<?php
declare(strict_types=1);
namespace App\Providers;
use App\Contracts\MailProviderInterface;
use App\Core\Config;
final class ManualMailProvider implements MailProviderInterface
{
    public function __construct(private Config $config){}
    public function provision(array $mailbox):array{return ['status'=>'manual_pending','provider_id'=>null];}
    public function suspend(string $providerId):void{throw new \LogicException('Suspend this mailbox in the provider control panel first.');}
    public function delete(string $providerId):void{throw new \LogicException('Delete this mailbox in the provider control panel first.');}
    public function connectionSettings():array{return [
        'smtp_host'=>$this->config->get('MAIL_SMTP_HOST',''),'smtp_port'=>$this->config->get('MAIL_SMTP_PORT','587'),'smtp_encryption'=>$this->config->get('MAIL_SMTP_ENCRYPTION','starttls'),
        'imap_host'=>$this->config->get('MAIL_IMAP_HOST',''),'imap_port'=>$this->config->get('MAIL_IMAP_PORT','993'),'imap_encryption'=>$this->config->get('MAIL_IMAP_ENCRYPTION','ssl'),
    ];}
}
