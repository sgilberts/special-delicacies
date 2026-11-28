<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A structured, secret-free Paystack settlement failure.
 *
 * The exception carries a stable state so the API and the frontend can tell
 * "not configured" from "authentication failed" and "provider unavailable".
 * Messages are written by this application only: a provider response body is
 * never copied into a message, because it can contain account identifiers.
 */
final class PaystackSettlementException extends RuntimeException
{
    public const STATE_NOT_CONFIGURED = 'not_configured';

    public const STATE_AUTHENTICATION_FAILED = 'authentication_failed';

    public const STATE_PROVIDER_UNAVAILABLE = 'provider_unavailable';

    public const STATE_RATE_LIMITED = 'rate_limited';

    public const STATE_INVALID_RESPONSE = 'invalid_response';

    public const STATE_REQUEST_REJECTED = 'request_rejected';

    private function __construct(
        private readonly string $state,
        private readonly ?int $httpStatus,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function notConfigured(): self
    {
        return new self(
            self::STATE_NOT_CONFIGURED,
            null,
            'Paystack settlement retrieval is not configured. Set PAYSTACK_SECRET_KEY and enable PAYSTACK_SETTLEMENT_ENABLED.',
        );
    }

    public static function authenticationFailed(int $status): self
    {
        return new self(
            self::STATE_AUTHENTICATION_FAILED,
            $status,
            'Paystack rejected the configured credentials. Check the secret key for the intended test or live account.',
        );
    }

    public static function rateLimited(?Throwable $previous = null): self
    {
        return new self(
            self::STATE_RATE_LIMITED,
            429,
            'Paystack is rate limiting settlement requests. Try again shortly.',
            $previous,
        );
    }

    public static function providerUnavailable(string $message, ?int $status = null, ?Throwable $previous = null): self
    {
        return new self(self::STATE_PROVIDER_UNAVAILABLE, $status, $message, $previous);
    }

    public static function invalidResponse(string $message): self
    {
        return new self(self::STATE_INVALID_RESPONSE, null, $message);
    }

    public static function requestRejected(int $status): self
    {
        return new self(
            self::STATE_REQUEST_REJECTED,
            $status,
            'Paystack rejected the settlement request.',
        );
    }

    public function state(): string
    {
        return $this->state;
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }

    /** Transient failures may be retried or fall back to another retrieval path. */
    public function isTransient(): bool
    {
        return in_array($this->state, [self::STATE_PROVIDER_UNAVAILABLE, self::STATE_RATE_LIMITED], true);
    }
}
