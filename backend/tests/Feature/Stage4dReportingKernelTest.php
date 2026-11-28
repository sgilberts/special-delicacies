<?php

namespace Tests\Feature;

use App\Models\CustomerCreditLedgerEntry;
use App\Services\CustomerCreditReversalService;
use App\Services\CustomerCreditService;
use App\Services\Reporting\CreditAttribution;
use App\Services\Reporting\Money;
use App\Services\Reporting\ReportPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsFinancialReports;
use Tests\TestCase;

/** Stage 4D-1: money, period and attribution contracts. */
class Stage4dReportingKernelTest extends TestCase
{
    use BuildsFinancialReports;
    use RefreshDatabase;

    public function test_money_round_trips_signed_minor_units(): void
    {
        $this->assertSame(12345, Money::minor('123.45'));
        $this->assertSame(-500, Money::minor('-5.00'));
        $this->assertSame(0, Money::minor(null));
        $this->assertSame(0, Money::minor(''));
        $this->assertSame('123.45', Money::decimal(12345));
        $this->assertSame('-5.00', Money::decimal(-500));
    }

    public function test_report_period_covers_the_whole_end_day_and_builds_the_previous_period(): void
    {
        $period = ReportPeriod::fromFilters(['from' => '2026-11-01', 'to' => '2026-11-30']);

        [$from, $to] = $period->bounds();
        $this->assertSame('2026-11-01 00:00:00', $from);
        $this->assertSame('2026-11-30 23:59:59', $to);
        $this->assertSame(30, $period->days());

        $previous = $period->previous();
        $this->assertSame('2026-10-02', $previous->from);
        $this->assertSame('2026-10-31', $previous->to);
    }

    public function test_report_period_is_capped_and_swaps_reversed_dates(): void
    {
        $capped = ReportPeriod::fromFilters(['from' => '2020-01-01', 'to' => '2026-11-30']);
        $this->assertSame(366, $capped->days());

        $swapped = ReportPeriod::fromFilters(['from' => '2026-11-30', 'to' => '2026-11-01']);
        $this->assertSame('2026-11-01', $swapped->from);
        $this->assertSame('2026-11-30', $swapped->to);
    }

    public function test_credit_attribution_matches_the_stage_4c_fifo_rule(): void
    {
        $actor = $this->staffWithPermissions($this->stage4dPermissions());
        $customer = $this->customerFixture('Attribution Customer', '0200000744');
        $invoice = $this->invoiceFixture($customer, '100.00', 'INV-KERNEL-1');
        $receipt = $this->receiptFixture([
            'customer_id' => $customer->id, 'amount' => '200.00', 'transaction_reference' => 'RPT-KERNEL-1',
        ]);

        $this->designateCredit($receipt, '100.00', 'credit-kernel-1', $actor);
        app(CustomerCreditService::class)->applyCredit($invoice->id, '40.00', 'apply-kernel-1', 'Apply customer credit', $actor);

        $entry = CustomerCreditLedgerEntry::query()->where('entry_type', 'designation')->firstOrFail();
        $remaining = app(CreditAttribution::class)->remainingByEntry('customer', $customer->id, 'GHS');
        $authoritative = app(CustomerCreditReversalService::class)->remainingReversibleMinor($entry);

        $this->assertSame(6000, $authoritative);
        $this->assertSame($authoritative, $remaining[$entry->id]);
    }
}
