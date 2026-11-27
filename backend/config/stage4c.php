<?php

/**
 * Stage 4C financial policy.
 *
 * Every value here maps to an owner decision (D1–D13) recorded in
 * docs/STAGE-4C-REFUNDS-CREDIT-NOTES-AND-REVERSALS-DESIGN.md §12.
 *
 * The defaults below are the conservative recommendations from that design.
 * They are intentionally configurable so the owner can tighten or change a
 * policy without editing controllers, services or frontend components.
 *
 * Irreversible provider-side money movement is additionally gated by
 * `refunds.provider_submission_enabled`, which defaults to false because no
 * Paystack/Hubtel/Korapay refund capability has been verified for the merchant
 * account. When disabled, refunds can still be requested, approved and
 * submitted as a manual payout, but can only be confirmed with independent
 * evidence — never automatically.
 */
return [
    'separation_of_duties' => env('STAGE4C_SEPARATION_OF_DUTIES', true),

    'refunds' => [
        // D4: partial refunds allowed up to the receipt's uncommitted funds.
        'partial_allowed' => env('STAGE4C_PARTIAL_REFUNDS', true),
        // D6: refunds must return to the same payer and payment method.
        'allow_different_method' => env('STAGE4C_REFUND_DIFFERENT_METHOD', false),
        // D12: never auto-confirm; provider submission is opt-in.
        'provider_submission_enabled' => env('STAGE4C_PROVIDER_REFUNDS_ENABLED', false),
        'providers_with_refund_support' => ['paystack'],
        // D12a: keep payment_transactions.status as succeeded; report refunds
        // from refund_requests instead of rewriting the receipt.
        'mark_transaction_refunded' => env('STAGE4C_MARK_REFUNDED', false),
    ],

    'credit_notes' => [
        // D9: partial credit notes allowed up to the remaining creditable amount.
        'partial_allowed' => env('STAGE4C_PARTIAL_CREDIT_NOTES', true),
        // §6.4 recommended default: apply the credit note against the
        // originating invoice's outstanding balance, leaving any excess as
        // available customer credit.
        'auto_apply' => env('STAGE4C_CREDIT_NOTE_AUTO_APPLY', true),
        // D8: creator must differ from the issuer.
        'require_independent_approval' => env('STAGE4C_CREDIT_NOTE_SEPARATION', true),
    ],

    'credit_reversals' => [
        // D10: initiator must differ from the authorizer.
        'require_independent_approval' => env('STAGE4C_REVERSAL_SEPARATION', true),
        // D13: applications consume credit entries chronologically (FIFO).
        'consumption_method' => 'fifo',
    ],
];
