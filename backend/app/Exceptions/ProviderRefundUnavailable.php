<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Raised when the payment provider's refund outcome is unknown: a timeout, a
 * connection failure, a 5xx response, or a duplicate-reference conflict.
 *
 * The provider may or may not have accepted the refund, so the refund is NOT
 * rolled back to `approved`. It is held in `submitting` (unresolved) and must
 * be reconciled against the provider before any further submission.
 */
class ProviderRefundUnavailable extends RuntimeException
{
    public static function from(Throwable $exception): self
    {
        return new self('The payment provider did not confirm the refund outcome.', 0, $exception);
    }
}
