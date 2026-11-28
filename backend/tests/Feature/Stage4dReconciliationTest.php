<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\BuildsFinancialReports;
use Tests\TestCase;

/** Stage 4D-6: internal reconciliation and its auditable run history. */
class Stage4dReconciliationTest extends TestCase
{
    use BuildsFinancialReports;
    use RefreshDatabase;

    public function test_internal_reconciliation_classifies_receipts_and_invoices(): void
    {
        $actor = $this->staffWithPermissions($this->stage4dPermissions());
        Sanctum::actingAs($actor);

        $customer = $this->customerFixture('Recon Customer', '0200000733');
        $invoice = $this->invoiceFixture($customer, '300.00', 'INV-REC-1');
        $full = $this->receiptFixture(['customer_id' => $customer->id, 'amount' => '300.00', 'transaction_reference' => 'RPT-REC-1']);
        $this->allocateReceipt($full, $invoice, '300.00', 'alloc-rec-0001', $actor);
        $this->receiptFixture(['customer_id' => $customer->id, 'amount' => '120.00', 'transaction_reference' => 'RPT-REC-2']);
        $this->receiptFixture(['customer_id' => $customer->id, 'amount' => '200.00', 'transaction_reference' => 'RPT-REC-3']);

        $response = $this->getJson($this->periodQuery('/api/v1/admin/reconciliation'))->assertOk();

        $receipts = $response->json('data.receipts');
        $this->assertSame(3, $receipts['receipts']);
        $this->assertSame('620.00', $receipts['receipt_amount']);
        $this->assertSame(1, $receipts['fully_allocated']);
        $this->assertSame(2, $receipts['unallocated']);
        $this->assertSame('320.00', $receipts['available_amount']);

        $invoices = $response->json('data.invoices');
        $this->assertSame(1, $invoices['invoices']);
        $this->assertSame('0.00', $invoices['outstanding_amount']);
        $this->assertSame(1, $invoices['fully_paid']);
        $this->assertSame(0, $invoices['over_settled']);

        $this->assertFalse($response->json('data.settlements.available'));
    }

    public function test_reconciliation_runs_are_prepared_reviewed_and_then_immutable(): void
    {
        $preparer = $this->staffWithPermissions($this->stage4dPermissions());
        $reviewer = $this->staffWithPermissions($this->stage4dPermissions());
        Sanctum::actingAs($preparer);

        // E-08: a succeeded receipt with no received date is an exception.
        $this->receiptFixture([
            'amount' => '40.00', 'transaction_reference' => 'RPT-REC-LEGACY',
            'received_at' => null, 'verified_at' => null,
        ]);

        $created = $this->postJson('/api/v1/admin/reconciliation/runs', [
            'from' => now()->toDateString(), 'to' => now()->toDateString(), 'currency' => 'GHS',
        ])->assertCreated();

        $run = $created->json('data');
        $this->assertSame('awaiting_review', $run['status']);
        $this->assertGreaterThanOrEqual(1, $run['exception_count']);
        $this->assertSame($preparer->name, $run['prepared_by']);
        $this->assertFalse($run['immutable']);
        $this->assertNotEmpty($run['summary']['receipts']);

        // A run with recorded exceptions cannot be closed as fully reconciled.
        Sanctum::actingAs($reviewer);
        $this->postJson('/api/v1/admin/reconciliation/runs/'.$run['id'].'/review', [
            'status' => 'reconciled', 'resolution_notes' => 'Looks fine',
        ])->assertStatus(422);

        $closed = $this->postJson('/api/v1/admin/reconciliation/runs/'.$run['id'].'/review', [
            'status' => 'reconciled_with_exceptions', 'resolution_notes' => 'Legacy receipt documented.',
        ])->assertOk();

        $this->assertSame('reconciled_with_exceptions', $closed->json('data.status'));
        $this->assertSame($reviewer->name, $closed->json('data.reviewed_by'));
        $this->assertTrue($closed->json('data.immutable'));
        $this->assertTrue(AdminAuditLog::where('action', 'financials.reconciliation.prepared')->exists());
        $this->assertTrue(AdminAuditLog::where('action', 'financials.reconciliation.reviewed')->exists());

        // Closed history cannot be edited by re-reviewing it.
        $this->postJson('/api/v1/admin/reconciliation/runs/'.$run['id'].'/review', [
            'status' => 'reconciled', 'resolution_notes' => 'Second attempt',
        ])->assertStatus(422);

        $list = $this->getJson('/api/v1/admin/reconciliation/runs')->assertOk();
        $this->assertSame(1, $list->json('meta.total'));
        $this->assertSame('reconciled_with_exceptions', $list->json('data.0.status'));
    }

    private function periodQuery(string $url): string
    {
        return $url.'?'.http_build_query([
            'from' => now()->toDateString(),
            'to' => now()->toDateString(),
            'currency' => 'GHS',
        ]);
    }
}
