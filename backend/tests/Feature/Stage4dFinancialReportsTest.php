<?php

namespace Tests\Feature;

use App\Models\CommercialDocument;
use App\Models\Customer;
use App\Models\Organisation;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\PaymentAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\BuildsFinancialReports;
use Tests\TestCase;

/**
 * Stage 4D-1 to 4D-5: authoritative calculations behind the dashboard and the
 * nine financial reports.
 */
class Stage4dFinancialReportsTest extends TestCase
{
    use BuildsFinancialReports;
    use RefreshDatabase;

    private User $actor;
    private User $approver;
    private Customer $customer;
    private Organisation $organisation;
    private CommercialDocument $invoiceOne;
    private CommercialDocument $invoiceTwo;
    private CommercialDocument $invoiceThree;
    private PaymentTransaction $receiptOne;
    private PaymentTransaction $receiptTwo;
    private PaymentTransaction $receiptThree;
    private PaymentTransaction $receiptSix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actor = $this->staffWithPermissions($this->stage4dPermissions());
        $this->approver = $this->staffWithPermissions($this->stage4dPermissions());
        Sanctum::actingAs($this->actor);

        $this->customer = $this->customerFixture('Credit Customer', '0200000711');
        $this->organisation = $this->organisationFixture('Report Org', $this->customer);

        $this->invoiceOne = $this->invoiceFixture($this->customer, '1000.00', 'INV-4D-1');
        $this->invoiceTwo = $this->invoiceFixture($this->customer, '500.00', 'INV-4D-2', [
            'expiry_date' => now()->subDays(40)->toDateString(),
        ]);
        $this->invoiceThree = $this->invoiceFixture($this->organisation, '400.00', 'INV-4D-3');

        $this->receiptOne = $this->receiptFixture([
            'customer_id' => $this->customer->id, 'amount' => '800.00', 'provider' => 'paystack',
            'payment_method' => 'gateway', 'transaction_reference' => 'RPT-4D-1',
        ]);
        $this->allocateReceipt($this->receiptOne, $this->invoiceOne, '600.00', 'alloc-4d-0001', $this->actor);
        $this->designateCredit($this->receiptOne, '100.00', 'credit-4d-0001', $this->actor);

        $this->receiptTwo = $this->receiptFixture([
            'customer_id' => $this->customer->id, 'amount' => '200.00', 'payment_method' => 'cash',
            'transaction_reference' => 'RPT-4D-2',
        ]);

        $this->receiptThree = $this->orderReceiptFixture('150.00', 'RPT-4D-3');

        $this->receiptFixture([
            'customer_id' => $this->customer->id, 'amount' => '50.00', 'status' => 'pending',
            'verified_at' => null, 'received_at' => null, 'transaction_reference' => 'RPT-4D-4',
        ]);
        $this->receiptFixture([
            'customer_id' => $this->customer->id, 'amount' => '70.00', 'status' => 'failed',
            'transaction_reference' => 'RPT-4D-5',
        ]);

        $this->receiptSix = $this->receiptFixture([
            'organisation_id' => $this->organisation->id, 'amount' => '400.00', 'provider' => 'hubtel',
            'payment_method' => 'gateway', 'transaction_reference' => 'RPT-4D-6',
        ]);
        $this->allocateReceipt($this->receiptSix, $this->invoiceThree, '100.00', 'alloc-4d-0002', $this->actor);

        $this->refundFixture($this->receiptTwo, '50.00', 'refund-4d-1', $this->actor, $this->approver, true);
        $this->refundFixture($this->receiptTwo, '30.00', 'refund-4d-2', $this->actor, $this->approver, false);

        $this->issueCreditNote($this->invoiceTwo, '100.00', 'Damaged goods credited', $this->actor, $this->approver);
    }

    public function test_dashboard_overview_keeps_receipts_allocations_credit_and_refunds_distinct(): void
    {
        $response = $this->getJson($this->reportUrl('dashboard'))->assertOk();
        $overview = $response->json('data.overview');

        $this->assertSame('1550.00', $overview['receipts_total']);
        $this->assertSame(4, $overview['receipt_count']);
        $this->assertSame('350.00', $overview['cash_receipts']);
        $this->assertSame('1200.00', $overview['online_receipts']);
        $this->assertSame('700.00', $overview['allocated_total']);
        $this->assertSame('600.00', $overview['unallocated_total']);
        $this->assertSame('1900.00', $overview['invoice_value_total']);
        $this->assertSame('1100.00', $overview['receivables_outstanding']);
        $this->assertSame('100.00', $overview['customer_credit_balance']);
        $this->assertSame('0.00', $overview['organisation_credit_balance']);
        $this->assertSame('30.00', $overview['refunds_pending']);
        $this->assertSame('50.00', $overview['refunds_completed']);
        $this->assertSame('100.00', $overview['credit_notes_issued']);

        $this->assertSame('GHS', $response->json('data.currency'));
        $this->assertNotEmpty($overview['definitions']);
        $this->assertArrayHasKey('previous', $overview);
    }

    public function test_dashboard_trends_are_daily_and_aggregate_into_weeks_and_months(): void
    {
        $response = $this->getJson($this->reportUrl('dashboard'))->assertOk();

        $this->assertCount(1, $response->json('data.trends.daily'));
        $daily = $response->json('data.trends.daily.0');
        $this->assertSame(now()->toDateString(), $daily['date']);
        $this->assertSame('350.00', $daily['cash_receipts']);
        $this->assertSame('1200.00', $daily['online_receipts']);
        $this->assertSame('1550.00', $daily['receipts_total']);
        $this->assertSame('700.00', $daily['allocated']);
        $this->assertSame('50.00', $daily['refunds_confirmed']);
        $this->assertSame('100.00', $daily['credit_notes_issued']);
        // Invoice receipts 1400 - allocated 700 - designated 100 = 600.
        $this->assertSame('600.00', $daily['unallocated_movement']);
        $this->assertCount(1, $response->json('data.trends.weekly'));
        $this->assertCount(1, $response->json('data.trends.monthly'));
    }

    public function test_receipt_register_lists_every_status_with_successful_totals(): void
    {
        $response = $this->getJson($this->reportUrl('receipts', ['per_page' => 50]))->assertOk();

        $this->assertSame(6, $response->json('meta.total'));
        $summary = $response->json('meta.summary');
        $this->assertSame('1550.00', $summary['totals']['succeeded_amount']);
        $this->assertSame('50.00', $summary['by_status']['pending']['amount']);
        $this->assertSame('70.00', $summary['by_status']['failed']['amount']);
        $this->assertSame('350.00', $summary['by_method']['cash']['amount']);
        $this->assertSame('1200.00', $summary['by_method']['gateway']['amount']);

        $row = collect($response->json('data'))->firstWhere('transaction_reference', 'RPT-4D-1');
        $this->assertSame('verified', $row['verification_status']);
        $this->assertSame('600.00', $row['allocated_amount']);
        $this->assertSame('100.00', $row['designated_amount']);
        $this->assertSame('100.00', $row['available_amount']);
    }

    public function test_allocation_report_separates_active_from_reversed_allocations(): void
    {
        $active = $this->getJson($this->reportUrl('allocations'))->assertOk();
        $this->assertSame('700.00', $active->json('meta.summary.active_amount'));
        $this->assertSame(2, $active->json('meta.summary.active_count'));

        app(PaymentAllocationService::class)->reverse(
            \App\Models\PaymentAllocation::query()->where('amount', '100.00')->firstOrFail(),
            'reverse-4d-0001',
            'Invoice corrected after review',
            $this->actor,
        );

        $after = $this->getJson($this->reportUrl('allocations'))->assertOk();
        $this->assertSame('600.00', $after->json('meta.summary.active_amount'));
        $this->assertSame('100.00', $after->json('meta.summary.reversed_amount'));
        $this->assertSame(1, $after->json('meta.summary.reversed_count'));

        $reversed = collect($after->json('data'))->firstWhere('reversed', true);
        $this->assertSame('reverse-4d-0001', $reversed['reversal']['reference']);

        // Reversing the allocation restores the invoice's outstanding balance.
        $receivables = $this->getJson($this->reportUrl('receivables'))->assertOk();
        $this->assertSame('1200.00', $receivables->json('meta.summary.outstanding_total'));
    }

    public function test_unallocated_report_excludes_order_pending_and_failed_receipts(): void
    {
        $response = $this->getJson($this->reportUrl('unallocated', ['per_page' => 50]))->assertOk();

        $references = collect($response->json('data'))->pluck('transaction_reference')->all();
        $this->assertEqualsCanonicalizing(['RPT-4D-1', 'RPT-4D-2', 'RPT-4D-6'], $references);

        $summary = $response->json('meta.summary');
        $this->assertSame('600.00', $summary['total_amount']);
        $this->assertSame(3, $summary['receipts']);
        $this->assertSame('600.00', $summary['buckets']['0-7']['amount']);

        $row = collect($response->json('data'))->firstWhere('transaction_reference', 'RPT-4D-2');
        $this->assertSame('0-7', $row['age_bucket']);
        // Refund reservations never reduce the "available to allocate" figure.
        $this->assertSame('200.00', $row['available_amount']);
        $this->assertSame('120.00', $row['refundable_amount']);
    }

    public function test_accounts_receivable_uses_commercial_documents_and_ageing(): void
    {
        $response = $this->getJson($this->reportUrl('receivables', ['per_page' => 50]))->assertOk();

        $summary = $response->json('meta.summary');
        $this->assertSame('1100.00', $summary['outstanding_total']);
        $this->assertSame('700.00', $summary['buckets']['current']['outstanding']);
        $this->assertSame('400.00', $summary['buckets']['31-60']['outstanding']);

        $overdue = collect($response->json('data'))->firstWhere('invoice_reference', 'INV-4D-2');
        $this->assertSame('400.00', $overdue['outstanding_amount']);
        $this->assertSame('100.00', $overdue['credit_note_applied_amount']);
        $this->assertSame('0.00', $overdue['credit_applied_amount']);
        $this->assertSame(40, $overdue['days_overdue']);
        $this->assertSame('31-60', $overdue['ageing_bucket']);

        $paid = collect($response->json('data'))->firstWhere('invoice_reference', 'INV-4D-1');
        $this->assertSame('partially_paid', $paid['payment_state']);
        $this->assertSame('400.00', $paid['outstanding_amount']);
    }

    public function test_customer_and_organisation_credit_reports_derive_balances_from_the_ledger(): void
    {
        $customers = $this->getJson($this->reportUrl('customer-credit'))->assertOk();
        $this->assertSame('100.00', $customers->json('meta.summary.closing_balance'));
        $this->assertSame('200.00', $customers->json('meta.summary.credited'));
        $this->assertSame('100.00', $customers->json('meta.summary.applied'));
        $this->assertSame('100.00', $customers->json('data.0.closing_balance'));
        $this->assertSame('0.00', $customers->json('data.0.opening_balance'));

        $statement = $this->getJson($this->reportUrl('customer-credit/'.$this->customer->id))->assertOk();
        $this->assertSame('100.00', $statement->json('data.closing_balance'));
        $this->assertSame('0.00', $statement->json('data.opening_balance'));
        $this->assertSame('100.00', $statement->json('data.movement'));
        $types = collect($statement->json('data.entries'))->pluck('entry_type')->all();
        $this->assertContains('designation', $types);
        $this->assertContains('credit_note_credit', $types);
        $this->assertContains('application', $types);

        $organisations = $this->getJson($this->reportUrl('organisation-credit'))->assertOk();
        $this->assertSame('0.00', $organisations->json('meta.summary.closing_balance'));
    }

    public function test_refund_report_treats_only_confirmed_refunds_as_completed(): void
    {
        $response = $this->getJson($this->reportUrl('refunds', ['per_page' => 50]))->assertOk();

        $stages = $response->json('meta.summary.stages');
        $this->assertSame('50.00', $stages['confirmed']['amount']);
        $this->assertSame('30.00', $stages['pipeline']['amount']);
        $this->assertSame('0.00', $stages['in_flight']['amount']);

        $confirmed = collect($response->json('data'))->firstWhere('status', 'confirmed');
        $this->assertSame('completed', $confirmed['stage']);
        $this->assertSame('CASH-refund-4d-1', $confirmed['provider_confirmation_reference']);
        $this->assertSame($this->actor->name, $confirmed['requesting_staff']);
        $this->assertSame($this->approver->name, $confirmed['approving_staff']);

        $pipeline = collect($response->json('data'))->firstWhere('status', 'approved');
        $this->assertSame('pipeline', $pipeline['stage']);
        $this->assertNull($pipeline['completed_at']);
    }

    public function test_credit_note_report_shows_issue_and_application_status(): void
    {
        $response = $this->getJson($this->reportUrl('credit-notes'))->assertOk();

        $this->assertSame('100.00', $response->json('meta.summary.issued_amount'));
        $note = $response->json('data.0');
        $this->assertSame('INV-4D-2', $note['original_invoice']);
        $this->assertSame('500.00', $note['original_invoice_total']);
        $this->assertSame('100.00', $note['amount']);
        // The note auto-applied 100 to its invoice; pooled FIFO then consumed the
        // older cash designation first, so the note's own credit entry remains.
        $this->assertSame('100.00', $note['applied_to_origin_amount']);
        $this->assertSame('100.00', $note['remaining_amount']);
        $this->assertSame('applied', $note['application_status']);
        $this->assertSame($this->approver->name, $note['issuing_staff']);
    }

    public function test_financial_activity_report_keeps_actor_reference_and_reason(): void
    {
        $response = $this->getJson($this->reportUrl('activity', ['per_page' => 100]))->assertOk();

        $types = collect($response->json('data'))->pluck('event_type')->unique()->values()->all();
        foreach (['receipt', 'allocation', 'credit_designation', 'credit_application', 'refund_requested', 'refund_approved', 'refund_submitted', 'refund_confirmed', 'credit_note_issued'] as $expected) {
            $this->assertContains($expected, $types, "Missing activity event: {$expected}");
        }

        $allocation = collect($response->json('data'))->firstWhere('event_type', 'allocation');
        $this->assertSame($this->actor->name, $allocation['actor']);
        $this->assertNotEmpty($allocation['event_at']);
        $this->assertNotEmpty($allocation['reference']);

        $filtered = $this->getJson($this->reportUrl('activity', ['event_type' => 'refund', 'per_page' => 100]))->assertOk();
        $this->assertTrue(collect($filtered->json('data'))->every(fn (array $row) => str_starts_with($row['event_type'], 'refund_')));
    }

    public function test_exports_use_the_same_rows_and_escape_formula_injection(): void
    {
        $this->receiptFixture([
            'customer_id' => $this->customer->id, 'amount' => '10.00', 'transaction_reference' => '=EVIL()',
        ]);

        $response = $this->get($this->reportUrl('receipts/export.csv', ['per_page' => 50]))->assertOk();
        $content = $response->streamedContent();

        $this->assertStringContainsString('Transaction reference', $content);
        $this->assertStringContainsString("'=EVIL()", $content);
        $this->assertStringNotContainsString(',=EVIL()', $content);
    }

    public function test_date_range_includes_the_end_of_the_selected_day_and_excludes_the_next(): void
    {
        $this->receiptFixture([
            'customer_id' => $this->customer->id, 'amount' => '11.00', 'transaction_reference' => 'RPT-LATE',
            'received_at' => now()->endOfDay()->subSecond(),
        ]);
        $this->receiptFixture([
            'customer_id' => $this->customer->id, 'amount' => '12.00', 'transaction_reference' => 'RPT-TOMORROW',
            'received_at' => now()->addDay()->startOfDay()->addSecond(),
        ]);

        $response = $this->getJson($this->reportUrl('receipts', ['per_page' => 50]))->assertOk();
        $references = collect($response->json('data'))->pluck('transaction_reference')->all();

        $this->assertContains('RPT-LATE', $references);
        $this->assertNotContains('RPT-TOMORROW', $references);
    }

    public function test_reports_are_partitioned_per_currency(): void
    {
        $this->receiptFixture([
            'customer_id' => $this->customer->id, 'amount' => '900.00', 'currency' => 'USD',
            'transaction_reference' => 'RPT-USD-1',
        ]);

        $ghs = $this->getJson($this->reportUrl('dashboard'))->assertOk();
        $this->assertSame('1550.00', $ghs->json('data.overview.receipts_total'));
        $this->assertContains('USD', $ghs->json('data.currencies'));

        $usd = $this->getJson($this->reportUrl('dashboard', ['currency' => 'USD']))->assertOk();
        $this->assertSame('900.00', $usd->json('data.overview.receipts_total'));
        $this->assertSame('900.00', $usd->json('data.overview.cash_receipts'));
        $this->assertSame('0.00', $usd->json('data.overview.receivables_outstanding'));
    }

    private function reportUrl(string $path, array $params = []): string
    {
        $query = http_build_query(array_merge([
            'from' => now()->toDateString(),
            'to' => now()->toDateString(),
            'currency' => 'GHS',
        ], $params));

        return '/api/v1/admin/reports/'.$path.'?'.$query;
    }
}
