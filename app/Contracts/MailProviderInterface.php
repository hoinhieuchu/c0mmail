<?php
declare(strict_types=1);
namespace App\Contracts;
interface MailProviderInterface
{
    /** @return array{status:string,provider_id:?string} */
    public function provision(array $mailbox):array;
    public function suspend(string $providerId):void;
    public function delete(string $providerId):void;
}
