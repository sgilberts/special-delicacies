<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\BuildsFinancialReports;
use Tests\TestCase;

/** Stage 4D-3: every report, export and reconciliation action is backend-authorised. */
class Stage4dPermissionTest extends TestCase
{
    use BuildsFinancialReports;
    use RefreshDatabase;

    public function test_report_permissions_are_granular_and_exports_need_both_grants(): void
    {
        $receiptsOnly = $this->staffWithPermissions(['financials.reports.receipts.view']);
        Sanctum::actingAs($receiptsOnly);

        $this->getJson('/api/v1/admin/reports/receipts')->assertOk();
        $this->getJson('/api/v1/admin/reports/refunds')->assertForbidden();
        $this->getJson('/api/v1/admin/reports/dashboard')->assertForbidden();
        // The report read permission alone does not grant export.
        $this->get('/api/v1/admin/reports/receipts/export.csv')->assertForbidden();

        $exportOnly = $this->staffWithPermissions(['financials.reports.export']);
        Sanctum::actingAs($exportOnly);
        $this->get('/api/v1/admin/reports/receipts/export.csv')->assertForbidden();

        $both = $this->staffWithPermissions(['financials.reports.receipts.view', 'financials.reports.export']);
        Sanctum::actingAs($both);
        $this->get('/api/v1/admin/reports/receipts/export.csv')->assertOk();
    }

    public function test_ordinary_administrators_receive_no_reporting_access(): void
    {
        $operator = $this->staffWithPermissions(['orders.view', 'customers.view']);
        Sanctum::actingAs($operator);

        foreach (['dashboard', 'receipts', 'allocations', 'unallocated', 'receivables', 'customer-credit', 'organisation-credit', 'refunds', 'credit-notes', 'activity'] as $report) {
            $this->getJson('/api/v1/admin/reports/'.$report)->assertForbidden();
        }
        $this->getJson('/api/v1/admin/reconciliation')->assertForbidden();
    }

    public function test_reconciliation_view_prepare_and_review_are_separate_permissions(): void
    {
        $viewer = $this->staffWithPermissions(['financials.reconciliation.view']);
        Sanctum::actingAs($viewer);
        $this->getJson('/api/v1/admin/reconciliation')->assertOk();
        $this->postJson('/api/v1/admin/reconciliation/runs', [])->assertForbidden();
        $this->getJson('/api/v1/admin/reconciliation/settlements')->assertForbidden();

        $preparer = $this->staffWithPermissions(['financials.reconciliation.view', 'financials.reconciliation.manage']);
        Sanctum::actingAs($preparer);
        $this->postJson('/api/v1/admin/reconciliation/runs', [])->assertCreated();

        $reviewer = $this->staffWithPermissions(['financials.reconciliation.view', 'financials.reconciliation.review']);
        Sanctum::actingAs($reviewer);
        $this->postJson('/api/v1/admin/reconciliation/runs', [])->assertForbidden();
        $this->postJson('/api/v1/admin/reconciliation/runs/1/review', ['status' => 'reconciled_with_exceptions'])->assertOk();
    }

    public function test_settlement_reporting_is_explicitly_unavailable_without_a_feed(): void
    {
        Sanctum::actingAs($this->staffWithPermissions(['financials.reports.settlements.view']));

        $response = $this->getJson('/api/v1/admin/reconciliation/settlements')->assertOk();
        $this->assertFalse($response->json('data.available'));
        $this->assertSame('not_configured', $response->json('data.tier'));
        $this->assertNotEmpty($response->json('data.required_data'));
    }

    public function test_contact_details_are_withheld_without_the_payer_financial_permission(): void
    {
        $customer = $this->customerFixture('Masked Customer', '0200000722');
        $this->receiptFixture(['customer_id' => $customer->id, 'amount' => '25.00', 'transaction_reference' => 'RPT-MASK-1']);

        Sanctum::actingAs($this->staffWithPermissions(['financials.reports.receipts.view']));
        $masked = $this->getJson('/api/v1/admin/reports/receipts')->assertOk();
        $this->assertArrayNotHasKey('email', $masked->json('data.0.payer'));

        Sanctum::actingAs($this->staffWithPermissions(['financials.reports.receipts.view', 'customers.view_financials']));
        $full = $this->getJson('/api/v1/admin/reports/receipts')->assertOk();
        $this->assertArrayHasKey('email', $full->json('data.0.payer'));
    }
}
