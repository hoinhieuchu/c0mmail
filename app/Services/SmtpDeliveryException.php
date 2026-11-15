<?php
declare(strict_types=1);
namespace App\Services;

use RuntimeException;
use Throwable;

/**
 * Describes whether an SMTP failure may have happened after DATA was submitted.
 * A true value must never be automatically retried because remote acceptance is unknown.
 */
final class SmtpDeliveryException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $deliveryUncertain,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
