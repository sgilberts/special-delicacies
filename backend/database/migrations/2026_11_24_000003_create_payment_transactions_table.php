<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('transaction_reference', 100)->unique();
            $table->string('provider', 40)->nullable()->index();
            $table->string('payment_method', 30);
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('GHS');
            $table->string('status', 30)->default('pending');
            $table->string('provider_reference', 150)->nullable()->index();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->date('reporting_date')->index();
            $table->timestamps();
            $table->index(['status', 'received_at'], 'payment_transactions_status_received_index');
            $table->index(['order_id', 'status'], 'payment_transactions_order_status_index');
        });

        // Bring the current order-level payment state into the ledger exactly once.
        // Cash references are deterministic for legacy records and do not expose customer data.
        DB::table('orders')->orderBy('id')->chunk(500, function ($orders): void {
            foreach ($orders as $order) {
                $reference = $order->payment_reference ?: 'LEGACY-CASH-'.$order->id;
                $paid = $order->payment_status === 'paid';
                DB::table('payment_transactions')->insertOrIgnore([
                    'order_id' => $order->id,
                    'transaction_reference' => $reference,
                    'provider' => null,
                    'payment_method' => $order->payment_method ?: 'cash',
                    'amount' => $order->total,
                    'currency' => 'GHS',
                    'status' => $paid ? 'succeeded' : 'pending',
                    'provider_reference' => $order->payment_reference,
                    'verified_at' => $paid ? $order->updated_at : null,
                    'received_at' => $paid ? $order->updated_at : null,
                    'reporting_date' => substr($paid ? $order->updated_at : $order->created_at, 0, 10),
                    'metadata' => json_encode(['source' => 'phase10a_backfill']),
                    'created_at' => $order->created_at,
                    'updated_at' => $order->updated_at,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
