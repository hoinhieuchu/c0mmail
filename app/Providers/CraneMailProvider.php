<?php
declare(strict_types=1);
namespace App\Providers;
use App\Contracts\MailProviderInterface;
use App\Core\Config;
final class CraneMailProvider implements MailProviderInterface
{
    public function __construct(private Config $config){}
    public function provision(array $mailbox):array
    {
        throw new \LogicException('CraneMail provisioning is disabled until its official API contract and credentials are supplied and verified.');
    }
    public function suspend(string $id):void
    {
        throw new \LogicException('CraneMail suspension is disabled until its official API contract and credentials are supplied and verified.');
    }
    public function delete(string $id):void
    {
        throw new \LogicException('CraneMail deletion is disabled until its official API contract and credentials are supplied and verified.');
    }
    public function connectionSettings():array{return [
        'smtp_host'=>$this->config->get('MAIL_SMTP_HOST',''),'smtp_port'=>$this->config->get('MAIL_SMTP_PORT','587'),'smtp_encryption'=>$this->config->get('MAIL_SMTP_ENCRYPTION','starttls'),
        'imap_host'=>$this->config->get('MAIL_IMAP_HOST',''),'imap_port'=>$this->config->get('MAIL_IMAP_PORT','993'),'imap_encryption'=>$this->config->get('MAIL_IMAP_ENCRYPTION','ssl'),
    ];}
}
