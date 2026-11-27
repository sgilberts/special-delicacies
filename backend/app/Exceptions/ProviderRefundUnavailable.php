<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Raised when the payment provider cannot accept a refund instruction.
 *
 * The refund is never marked submitted or confirmed: the surrounding
 * transaction rolls back, the failure is recorded as a `provider_error`
 * audit event, and the refund stays approved for a safe retry.
 */
class ProviderRefundUnavailable extends RuntimeException
{
    public static function from(Throwable $exception): self
    {
        return new self('The payment provider could not accept the refund instruction.', 0, $exception);
    }
}
