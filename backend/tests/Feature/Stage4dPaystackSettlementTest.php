<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\BuildsFinancialReports;
use Tests\TestCase;

/**
 * Stage 4D-PAYSTACK: read-only Paystack settlement retrieval, mapping and the
 * read-only reconciliation preview. Every provider response is faked; no live
 * merchant account or real transaction is ever contacted.
 */
class Stage4dPaystackSettlementTest extends TestCase
{
    use BuildsFinancialReports;
    use RefreshDatabase;

    private const SECRET = 'sk_test_secret_value_that_must_never_leak';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.paystack.base_url' => 'https://api.paystack.co',
            'services.paystack.secret_key' => self::SECRET,
            'services.paystack.settlement_enabled' => true,
            'services.paystack.timeout' => 5,
            'services.paystack.retries' => 2,
            'services.paystack.retry_sleep_ms' => 0,
        ]);
    }

    // 1: configuration and connection state -------------------------------

    public function test_status_reports_not_configured_without_credentials_and_listing_is_refused(): void
    {
        config(['services.paystack.secret_key' => null, 'services.paystack.settlement_enabled' => false]);
        Http::fake();
        Sanctum::actingAs($this->viewer());

        $this->getJson('/api/v1/admin/reconciliation/paystack/status')
            ->assertOk()
            ->assertJsonPath('data.state', 'not_configured')
            ->assertJsonPath('data.state_label', 'Not configured')
            ->assertJsonPath('data.configured', false)
            ->assertJsonPath('data.read_only', true);

        $this->getJson('/api/v1/admin/reconciliation/paystack/settlements')
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('state', 'not_configured');

        Http::assertNothingSent();
    }

    public function test_connection_test_is_audited_and_never_exposes_the_secret(): void
    {
        Http::fake(['https://api.paystack.co/settlement?*' => Http::response($this->listResponse([]))]);
        $actor = $this->staffWithPermissions([
            'financials.reports.settlements.view',
            'financials.reconciliation.view',
            'financials.reconciliation.manage',
        ]);
        Sanctum::actingAs($actor);

        $response = $this->postJson('/api/v1/admin/reconciliation/paystack/connection-test')->assertOk();

        $response->assertJsonPath('data.state', 'connected');
        $this->assertStringNotContainsString(self::SECRET, $response->getContent());
        $this->assertNotNull($response->json('data.last_success_at'));
        $this->assertTrue(AdminAuditLog::where('action', 'financials.paystack.connection_tested')->exists());
    }

    public function test_authentication_failure_is_reported_as_its_own_state(): void
    {
        Http::fake(['https://api.paystack.co/settlement?*' => Http::response(['status' => false, 'message' => 'Invalid key'], 401)]);
        Sanctum::actingAs($this->staffWithPermissions([
            'financials.reports.settlements.view',
            'financials.reconciliation.manage',
        ]));

        $this->postJson('/api/v1/admin/reconciliation/paystack/connection-test')
            ->assertOk()
            ->assertJsonPath('data.state', 'authentication_failed')
            ->assertJsonPath('data.last_error.http_status', 401);

        $this->getJson('/api/v1/admin/reconciliation/paystack/settlements')
            ->assertStatus(502)
            ->assertJsonPath('state', 'authentication_failed');
    }

    public function test_forbidden_response_is_also_an_authentication_failure(): void
    {
        Http::fake(['https://api.paystack.co/settlement?*' => Http::response(['status' => false], 403)]);
        Sanctum::actingAs($this->viewer());

        $this->getJson('/api/v1/admin/reconciliation/paystack/settlements')
            ->assertStatus(502)
            ->assertJsonPath('state', 'authentication_failed');
    }

    // 2: settlement listing -----------------------------------------------

    public function test_settlements_are_listed_with_verbatim_statuses_and_ghs_minor_units(): void
    {
        Http::fake(['https://api.paystack.co/settlement?*' => Http::response($this->listResponse([
            $this->settlementPayload(['id' => 1001, 'status' => 'success']),
            $this->settlementPayload(['id' => 1002, 'status' => 'processing']),
            $this->settlementPayload(['id' => 1003, 'status' => 'pending']),
            $this->settlementPayload(['id' => 1004, 'status' => 'failed']),
        ]))]);
        Sanctum::actingAs($this->viewer());

        $response = $this->getJson('/api/v1/admin/reconciliation/paystack/settlements')->assertOk();

        $response->assertJsonPath('data.0.id', '1001')
            ->assertJsonPath('data.0.status', 'success')
            ->assertJsonPath('data.0.status_is_terminal', true)
            ->assertJsonPath('data.0.currency', 'GHS')
            ->assertJsonPath('data.0.currency_supported', true)
            ->assertJsonPath('data.0.provider_amounts.total_amount.minor', 50000)
            ->assertJsonPath('data.0.provider_amounts.total_amount.decimal', '500.00')
            ->assertJsonPath('data.0.provider_amounts.total_fees.decimal', '15.00')
            ->assertJsonPath('data.0.provider_amounts.effective_amount.decimal', '485.00')
            ->assertJsonPath('data.0.provider_amounts.total_processed.decimal', '510.00')
            ->assertJsonPath('data.1.status', 'processing')
            ->assertJsonPath('data.1.status_is_terminal', false)
            ->assertJsonPath('data.2.status', 'pending')
            ->assertJsonPath('data.2.status_is_terminal', false)
            ->assertJsonPath('data.3.status', 'failed')
            ->assertJsonPath('data.3.status_is_terminal', true);

        // A field the provider did not return is absent, never estimated.
        $this->assertArrayNotHasKey('deductions', $response->json('data.0.provider_amounts'));
    }

    public function test_settlement_listing_forwards_filters_and_pagination_metadata(): void
    {
        Http::fake(['https://api.paystack.co/settlement?*' => Http::response($this->listResponse(
            [$this->settlementPayload()],
            ['page' => 2, 'pageCount' => 4, 'total' => 7, 'perPage' => 2],
        ))]);
        Sanctum::actingAs($this->viewer());

        $this->getJson('/api/v1/admin/reconciliation/paystack/settlements?per_page=2&page=2&status=success&from=2026-11-01&to=2026-11-30')
            ->assertOk()
            ->assertJsonPath('meta.page', 2)
            ->assertJsonPath('meta.page_count', 4)
            ->assertJsonPath('meta.total', 7);

        Http::assertSent(function (ClientRequest $request): bool {
            $query = $this->queryParams($request);

            return str_contains($request->url(), '/settlement')
                && ($query['perPage'] ?? null) == 2
                && ($query['page'] ?? null) == 2
                && ($query['status'] ?? null) === 'success'
                && ($query['from'] ?? null) === '2026-11-01'
                && ($query['to'] ?? null) === '2026-11-30';
        });
    }

    public function test_empty_settlement_page_is_reported_as_empty_not_failed(): void
    {
        Http::fake(['https://api.paystack.co/settlement?*' => Http::response($this->listResponse([]))]);
        Sanctum::actingAs($this->viewer());

        $this->getJson('/api/v1/admin/reconciliation/paystack/settlements')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);
    }

    // 3: transactions, pagination and the export path ----------------------

    public function test_settlement_transactions_are_collected_across_every_page(): void
    {
        Http::fake([
            'https://api.paystack.co/transaction/export*' => Http::response(['status' => false], 500),
            'https://api.paystack.co/transaction?*' => function (ClientRequest $request) {
                $page = (int) ($this->queryParams($request)['page'] ?? 1);

                return Http::response([
                    'status' => true,
                    'data' => $page === 1
                        ? [$this->transactionPayload('PSTK-1', 12000), $this->transactionPayload('PSTK-2', 3000)]
                        : [$this->transactionPayload('PSTK-3', 5000)],
                    'meta' => ['page' => $page, 'pageCount' => 2, 'total' => 3, 'perPage' => 100],
                ]);
            },
        ]);
        Sanctum::actingAs($this->viewer());

        $response = $this->getJson('/api/v1/admin/reconciliation/paystack/settlements/1001/transactions')->assertOk();

        $response->assertJsonPath('source', 'paginated')
            ->assertJsonPath('meta.count', 3)
            ->assertJsonPath('data.0.reference', 'PSTK-1')
            ->assertJsonPath('data.0.amount.decimal', '120.00')
            ->assertJsonPath('data.2.reference', 'PSTK-3');
    }

    public function test_export_link_is_downloaded_immediately_and_never_returned(): void
    {
        Http::fake([
            'https://api.paystack.co/transaction/export*' => Http::response([
                'status' => true,
                'data' => ['path' => 'https://files.paystack.co/exports/settlement-1001.csv'],
            ]),
            'https://files.paystack.co/*' => Http::response(
                "Transaction ID,Reference,Amount,Fees,Currency,Status,Channel,Paid at\n"
                ."555,PSTK-1,120.00,1.20,GHS,success,card,2026-11-19 10:00:00\n",
                200,
                ['Content-Type' => 'text/csv'],
            ),
        ]);
        Sanctum::actingAs($this->viewer());

        $response = $this->getJson('/api/v1/admin/reconciliation/paystack/settlements/1001/transactions')->assertOk();

        $response->assertJsonPath('source', 'export')
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('data.0.id', '555')
            ->assertJsonPath('data.0.reference', 'PSTK-1')
            ->assertJsonPath('data.0.amount.minor', 12000)
            ->assertJsonPath('data.0.fees.minor', 120)
            ->assertJsonPath('data.0.currency', 'GHS');

        $this->assertStringNotContainsString('files.paystack.co', $response->getContent());
    }

    public function test_expired_export_link_requests_a_fresh_export_once(): void
    {
        $downloads = 0;
        Http::fake([
            'https://api.paystack.co/transaction/export*' => Http::response([
                'status' => true,
                'data' => ['path' => 'https://files.paystack.co/exports/fresh.csv'],
            ]),
            'https://files.paystack.co/*' => function () use (&$downloads) {
                $downloads++;

                return $downloads === 1
                    ? Http::response('', 410)
                    : Http::response("Reference,Amount,Currency,Status\nPSTK-9,700.00,GHS,success\n", 200);
            },
        ]);
        Sanctum::actingAs($this->viewer());

        $response = $this->getJson('/api/v1/admin/reconciliation/paystack/settlements/1001/transactions')->assertOk();

        $response->assertJsonPath('source', 'export')
            ->assertJsonPath('data.0.reference', 'PSTK-9')
            ->assertJsonPath('data.0.amount.minor', 70000);
        $this->assertSame(2, $downloads);
    }

    public function test_export_failure_falls_back_to_paginated_transactions(): void
    {
        Http::fake([
            'https://api.paystack.co/transaction/export*' => Http::response(['status' => false], 500),
            'https://api.paystack.co/transaction?*' => Http::response([
                'status' => true,
                'data' => [$this->transactionPayload('PSTK-FALLBACK', 4000)],
                'meta' => ['page' => 1, 'pageCount' => 1, 'total' => 1],
            ]),
        ]);
        Sanctum::actingAs($this->viewer());

        $this->getJson('/api/v1/admin/reconciliation/paystack/settlements/1001/transactions')
            ->assertOk()
            ->assertJsonPath('source', 'paginated')
            ->assertJsonPath('data.0.reference', 'PSTK-FALLBACK');
    }

    public function test_unreadable_export_csv_falls_back_to_paginated_transactions(): void
    {
        Http::fake([
            'https://api.paystack.co/transaction/export*' => Http::response([
                'status' => true,
                'data' => ['path' => 'https://files.paystack.co/exports/odd.csv'],
            ]),
            'https://files.paystack.co/*' => Http::response("Amount,Currency\n100.00,GHS\n", 200),
            'https://api.paystack.co/transaction?*' => Http::response([
                'status' => true,
                'data' => [$this->transactionPayload('PSTK-ODD', 10000)],
                'meta' => ['page' => 1, 'pageCount' => 1],
            ]),
        ]);
        Sanctum::actingAs($this->viewer());

        $this->getJson('/api/v1/admin/reconciliation/paystack/settlements/1001/transactions')
            ->assertOk()
            ->assertJsonPath('source', 'paginated')
            ->assertJsonPath('data.0.reference', 'PSTK-ODD');
    }

    // 4: provider failures -------------------------------------------------

    public function test_timeout_is_retried_then_reported_as_provider_unavailable(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            throw new ConnectionException('cURL error 28: timed out');
        });
        Sanctum::actingAs($this->viewer());

        $this->getJson('/api/v1/admin/reconciliation/paystack/settlements')
            ->assertStatus(503)
            ->assertJsonPath('state', 'provider_unavailable');

        // A connection failure is retried once before it is reported.
        $this->assertSame(2, $attempts);
    }

    public function test_rate_limiting_is_retried_then_reported(): void
    {
        Http::fake(['https://api.paystack.co/settlement?*' => Http::response(['status' => false], 429)]);
        Sanctum::actingAs($this->viewer());

        $this->getJson('/api/v1/admin/reconciliation/paystack/settlements')
            ->assertStatus(503)
            ->assertJsonPath('state', 'rate_limited');

        Http::assertSentCount(2);
    }

    public function test_server_error_is_retried_then_reported(): void
    {
        Http::fake(['https://api.paystack.co/settlement?*' => Http::response(['status' => false], 500)]);
        Sanctum::actingAs($this->viewer());

        $this->getJson('/api/v1/admin/reconciliation/paystack/settlements')
            ->assertStatus(503)
            ->assertJsonPath('state', 'provider_unavailable');

        Http::assertSentCount(2);
    }

    public function test_invalid_json_is_rejected(): void
    {
        Http::fake(['https://api.paystack.co/settlement?*' => Http::response('this is not json', 200, ['Content-Type' => 'application/json'])]);
        Sanctum::actingAs($this->viewer());

        $this->getJson('/api/v1/admin/reconciliation/paystack/settlements')
            ->assertStatus(502)
            ->assertJsonPath('state', 'invalid_response');
    }

    public function test_settlement_without_an_identifier_is_rejected(): void
    {
        Http::fake(['https://api.paystack.co/settlement/*' => Http::response([
            'status' => true,
            'data' => ['status' => 'success', 'currency' => 'GHS'],
        ])]);
        Sanctum::actingAs($this->viewer());

        $this->getJson('/api/v1/admin/reconciliation/paystack/settlements/1001')
            ->assertStatus(502)
            ->assertJsonPath('state', 'invalid_response');
    }

    public function test_secret_key_never_reaches_a_response_or_a_log_line(): void
    {
        $messages = [];
        Log::listen(function ($event) use (&$messages): void {
            $messages[] = $event->message.' '.json_encode($event->context);
        });

        Http::fake(['https://api.paystack.co/settlement?*' => Http::response(['status' => false], 500)]);
        Sanctum::actingAs($this->viewer());

        $response = $this->getJson('/api/v1/admin/reconciliation/paystack/settlements');

        $this->assertStringNotContainsString(self::SECRET, $response->getContent());
        $this->assertNotEmpty($messages);
        foreach ($messages as $message) {
            $this->assertStringNotContainsString(self::SECRET, $message);
        }
    }

    // 5: read-only reconciliation preview ---------------------------------

    public function test_preview_classifies_every_outcome_without_mutating_anything(): void
    {
        $settlement = $this->settlementPayload(['id' => 1001, 'settlement_date' => '2026-11-20']);

        Http::fake([
            'https://api.paystack.co/settlement/1001' => Http::response(['status' => true, 'data' => $settlement]),
            'https://api.paystack.co/transaction/export*' => Http::response(['status' => false], 500),
            'https://api.paystack.co/transaction?*' => Http::response([
                'status' => true,
                'data' => [
                    $this->transactionPayload('PSTK-EXACT', 12000, ['paid_at' => '2026-11-19T10:00:00.000Z']),
                    $this->transactionPayload('PSTK-AMT', 6000),
                    $this->transactionPayload('PSTK-CUR', 500, ['currency' => 'NGN']),
                    $this->transactionPayload('PSTK-ONLY', 3000, ['paid_at' => null]),
                    $this->transactionPayload('PSTK-EXACT', 12000),
                    $this->transactionPayload('PSTK-OTHER', 1000, ['settlement' => '9999']),
                    $this->transactionPayload('PSTK-REF', 2000, ['status' => 'reversed']),
                ],
                'meta' => ['page' => 1, 'pageCount' => 1],
            ]),
        ]);

        $this->receiptFixture([
            'transaction_reference' => 'LOCAL-EXACT', 'provider' => 'paystack',
            'provider_reference' => 'PSTK-EXACT', 'amount' => '120.00',
            'received_at' => Carbon::parse('2026-11-19 10:00:00'),
        ]);
        $this->receiptFixture([
            'transaction_reference' => 'LOCAL-AMT', 'provider' => 'paystack',
            'provider_reference' => 'PSTK-AMT', 'amount' => '50.00',
        ]);
        $this->receiptFixture([
            'transaction_reference' => 'LOCAL-LOCALONLY', 'provider' => 'paystack',
            'provider_reference' => 'PSTK-LOCALONLY', 'amount' => '30.00',
            'received_at' => Carbon::parse('2026-11-20 12:00:00'),
        ]);

        $before = $this->financialSnapshot();

        Sanctum::actingAs($this->viewer());
        $response = $this->getJson('/api/v1/admin/reconciliation/paystack/settlements/1001/preview')->assertOk();

        $response->assertJsonPath('data.read_only', true)
            ->assertJsonPath('data.mutations_applied', false)
            ->assertJsonPath('data.summary.classifications.exact_match', 1)
            ->assertJsonPath('data.summary.classifications.amount_mismatch', 1)
            ->assertJsonPath('data.summary.classifications.currency_mismatch', 1)
            ->assertJsonPath('data.summary.classifications.provider_only', 1)
            ->assertJsonPath('data.summary.classifications.duplicate_provider_transaction', 1)
            ->assertJsonPath('data.summary.classifications.other_settlement', 1)
            ->assertJsonPath('data.summary.classifications.refund_or_reversal', 1)
            ->assertJsonPath('data.summary.classifications.local_only', 1)
            ->assertJsonPath('data.summary.quarantined', 1)
            ->assertJsonPath('data.restrictions.D5', 'Provider fee fields are shown only as reported. No fee is posted to the ledger, derived or edited.');

        // The NGN transaction is quarantined, never converted into the GHS total,
        // and the duplicate is counted once.
        $this->assertSame(12000 + 6000 + 3000 + 2000, $response->json('data.summary.provider_total.minor'));

        $rows = collect($response->json('data.rows'));
        $mismatch = $rows->firstWhere('reference', 'PSTK-AMT');
        $this->assertSame(1000, $mismatch['difference']['minor']);
        $this->assertSame('10.00', $mismatch['difference']['decimal']);

        // Read-only: no financial record changed and no reconciliation run was created.
        $this->assertSame($before, $this->financialSnapshot());
        $this->assertTrue(AdminAuditLog::where('action', 'financials.paystack.preview_generated')->exists());
    }

    public function test_preview_matches_on_amount_and_date_only_when_no_reference_is_shared(): void
    {
        Http::fake([
            'https://api.paystack.co/settlement/1001' => Http::response([
                'status' => true, 'data' => $this->settlementPayload(['id' => 1001, 'settlement_date' => '2026-11-20']),
            ]),
            'https://api.paystack.co/transaction/export*' => Http::response(['status' => false], 500),
            'https://api.paystack.co/transaction?*' => Http::response([
                'status' => true,
                'data' => [$this->transactionPayload('PSTK-NOREF', 4500, ['paid_at' => '2026-11-20T08:00:00.000Z'])],
                'meta' => ['page' => 1, 'pageCount' => 1],
            ]),
        ]);

        $this->receiptFixture([
            'transaction_reference' => 'LOCAL-DIFFERENT-REF', 'provider' => 'paystack',
            'provider_reference' => 'PSTK-OLD-REF', 'amount' => '45.00',
            'received_at' => Carbon::parse('2026-11-20 08:00:00'),
        ]);

        Sanctum::actingAs($this->viewer());

        $this->getJson('/api/v1/admin/reconciliation/paystack/settlements/1001/preview')
            ->assertOk()
            ->assertJsonPath('data.summary.classifications.possible_match', 1)
            ->assertJsonPath('data.summary.classifications.local_only', 0);
    }

    // 6: permissions -------------------------------------------------------

    public function test_settlement_data_requires_the_settlement_viewing_permission(): void
    {
        Http::fake(['https://api.paystack.co/settlement?*' => Http::response($this->listResponse([]))]);
        Sanctum::actingAs($this->staffWithPermissions(['financials.reconciliation.view']));

        $this->getJson('/api/v1/admin/reconciliation/paystack/settlements')->assertForbidden();
        $this->getJson('/api/v1/admin/reconciliation/paystack/status')->assertForbidden();
    }

    public function test_connection_test_requires_the_reconciliation_manage_permission(): void
    {
        Http::fake(['https://api.paystack.co/settlement?*' => Http::response($this->listResponse([]))]);
        Sanctum::actingAs($this->staffWithPermissions(['financials.reports.settlements.view']));

        $this->postJson('/api/v1/admin/reconciliation/paystack/connection-test')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        Http::fake();

        $this->getJson('/api/v1/admin/reconciliation/paystack/status')->assertUnauthorized();
        Http::assertNothingSent();
    }

    // 7: helpers -----------------------------------------------------------

    private function viewer(): User
    {
        return $this->staffWithPermissions(['financials.reports.settlements.view', 'financials.reconciliation.view']);
    }

    /** @return array<string, mixed> */
    private function settlementPayload(array $overrides = []): array
    {
        return array_merge([
            'id' => 1001,
            'status' => 'success',
            'currency' => 'GHS',
            'total_amount' => 50000,
            'effective_amount' => 48500,
            'total_fees' => 1500,
            'total_processed' => 51000,
            'settlement_date' => '2026-11-20',
            'createdAt' => '2026-11-20T09:00:00.000Z',
            'updatedAt' => '2026-11-20T09:05:00.000Z',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function transactionPayload(string $reference, int $amount, array $overrides = []): array
    {
        return array_merge([
            'id' => crc32($reference),
            'reference' => $reference,
            'amount' => $amount,
            'fees' => 100,
            'currency' => 'GHS',
            'status' => 'success',
            'channel' => 'card',
            'paid_at' => '2026-11-19T10:00:00.000Z',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function listResponse(array $rows, array $meta = []): array
    {
        return [
            'status' => true,
            'message' => 'Settlements retrieved',
            'data' => $rows,
            'meta' => array_merge(['page' => 1, 'pageCount' => 1, 'total' => count($rows), 'perPage' => 50], $meta),
        ];
    }

    /** @return array<string, string> */
    private function queryParams(ClientRequest $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query;
    }

    /** @return array<string, int> */
    private function financialSnapshot(): array
    {
        $tables = [
            'payment_transactions', 'payment_allocations', 'customer_credit_ledger_entries',
            'refund_requests', 'commercial_documents', 'reconciliation_runs',
        ];

        $snapshot = [];
        foreach ($tables as $table) {
            $snapshot[$table] = DB::table($table)->count();
        }

        $receipt = PaymentTransaction::query()->orderBy('id')->first();
        $snapshot['first_receipt_status'] = $receipt?->status;
        $snapshot['first_receipt_amount'] = $receipt?->amount;
        $snapshot['first_receipt_updated_at'] = (string) $receipt?->updated_at;

        return $snapshot;
    }
}
