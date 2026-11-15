<?php
declare(strict_types=1);
namespace App\\Services;

use RuntimeException;

/** An explicit, syntactically valid SMTP reply that did not match the expected codes. */
final class SmtpProtocolException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $responseCode
    ) {
        parent::__construct($message, $responseCode);
    }

    /** A 4xx/5xx reply proves the server rejected the current SMTP operation. */
    public function isNegativeReply(): bool
    {
        return $this->responseCode >= 400 && $this->responseCode <= 599;
    }
}
