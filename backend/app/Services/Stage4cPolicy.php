<?php

namespace App\Services;

/**
 * Typed access to the Stage 4C owner-decision policy (config/stage4c.php).
 *
 * Keeping every toggle here prevents hard-coded financial assumptions from
 * spreading across controllers, services and frontend components.
 */
class Stage4cPolicy
{
    public function separationOfDutiesRequired(): bool
    {
        return (bool) config('stage4c.separation_of_duties', true);
    }

    public function partialRefundsAllowed(): bool
    {
        return (bool) config('stage4c.refunds.partial_allowed', true);
    }

    public function refundToDifferentMethodAllowed(): bool
    {
        return (bool) config('stage4c.refunds.allow_different_method', false);
    }

    public function providerRefundsEnabled(): bool
    {
        return (bool) config('stage4c.refunds.provider_submission_enabled', false);
    }

    public function providerSupportsRefunds(?string $provider): bool
    {
        return $provider !== null && in_array($provider, (array) config('stage4c.refunds.providers_with_refund_support', []), true);
    }

    public function markTransactionRefunded(): bool
    {
        return (bool) config('stage4c.refunds.mark_transaction_refunded', false);
    }

    public function partialCreditNotesAllowed(): bool
    {
        return (bool) config('stage4c.credit_notes.partial_allowed', true);
    }

    public function creditNoteAutoApply(): bool
    {
        return (bool) config('stage4c.credit_notes.auto_apply', true);
    }

    public function creditNoteSeparationRequired(): bool
    {
        return $this->separationOfDutiesRequired()
            && (bool) config('stage4c.credit_notes.require_independent_approval', true);
    }

    public function reversalSeparationRequired(): bool
    {
        return $this->separationOfDutiesRequired()
            && (bool) config('stage4c.credit_reversals.require_independent_approval', true);
    }
}
