<?php
declare(strict_types=1);
namespace App\Contracts;
interface SmtpClientInterface { public function send(array $message):void; }
