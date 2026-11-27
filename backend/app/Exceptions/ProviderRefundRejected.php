<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Raised when the payment provider definitively rejected a refund request
 * (a business error response, not a transport failure).
 *
 * Because the provider explicitly refused the instruction, no money moved and
 * the refund can safely return to `approved` for a corrected retry.
 */
class ProviderRefundRejected extends RuntimeException
{
    public static function from(Throwable $exception): self
    {
        return new self('The payment provider rejected the refund instruction.', 0, $exception);
    }
}
