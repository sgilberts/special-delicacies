<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\PaymentTransaction;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinancialController extends Controller
{
    /** Daily aggregates across history, with no individual-order download. */
    public function snapshot(): JsonResponse
    {
        $daily = DB::table('payment_transactions as pt')
            ->join('orders as o', 'o.id', '=', 'pt.order_id')
            ->selectRaw('pt.reporting_date as date')
            ->selectRaw('COUNT(DISTINCT o.id) as order_count')
            ->selectRaw("COUNT(DISTINCT CASE WHEN pt.status = 'succeeded' AND o.status <> 'cancelled' THEN o.id END) as paid_orders")
            ->selectRaw("COUNT(DISTINCT CASE WHEN pt.status <> 'succeeded' AND o.status <> 'cancelled' THEN o.id END) as unpaid_orders")
            ->selectRaw("COUNT(DISTINCT CASE WHEN o.status = 'cancelled' THEN o.id END) as cancelled_orders")
            ->selectRaw("COALESCE(SUM(CASE WHEN pt.status = 'succeeded' AND o.status <> 'cancelled' THEN pt.amount ELSE 0 END), 0) as collected")
            ->selectRaw("COALESCE(SUM(CASE WHEN pt.status = 'succeeded' AND pt.payment_method = 'cash' AND o.status <> 'cancelled' THEN pt.amount ELSE 0 END), 0) as cash_collected")
            ->selectRaw("COALESCE(SUM(CASE WHEN pt.status = 'succeeded' AND pt.payment_method = 'gateway' AND o.status <> 'cancelled' THEN pt.amount ELSE 0 END), 0) as online_collected")
            ->selectRaw("COALESCE(SUM(CASE WHEN pt.status = 'pending' AND o.status <> 'cancelled' THEN pt.amount ELSE 0 END), 0) as outstanding")
            ->selectRaw("COALESCE(SUM(CASE WHEN pt.status = 'failed' THEN pt.amount ELSE 0 END), 0) as failed_amount")
            ->selectRaw("COALESCE(SUM(CASE WHEN pt.status = 'cancelled' THEN pt.amount ELSE 0 END), 0) as cancelled_amount")
            ->selectRaw("COALESCE(SUM(CASE WHEN pt.status = 'refunded' THEN pt.amount ELSE 0 END), 0) as refunded_amount")
            ->selectRaw("SUM(CASE WHEN pt.status = 'pending' THEN 1 ELSE 0 END) as pending_count")
            ->selectRaw("SUM(CASE WHEN pt.status = 'failed' THEN 1 ELSE 0 END) as failed_count")
            ->selectRaw("SUM(CASE WHEN pt.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count")
            ->selectRaw("SUM(CASE WHEN pt.status = 'refunded' THEN 1 ELSE 0 END) as refunded_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN pt.status = 'succeeded' AND o.order_type = 'dine_in' AND o.status <> 'cancelled' THEN pt.amount ELSE 0 END), 0) as dine_in_collected")
            ->selectRaw("COALESCE(SUM(CASE WHEN pt.status = 'succeeded' AND o.order_type = 'delivery' AND o.status <> 'cancelled' THEN pt.amount ELSE 0 END), 0) as delivery_collected")
            ->groupBy('pt.reporting_date')
            ->orderBy('date')
            ->get();

        $dailyChannels = DB::table('payment_transactions as pt')->join('orders as o', 'o.id', '=', 'pt.order_id')
            ->select('pt.reporting_date as date', 'pt.payment_method', 'pt.provider', 'pt.status')
            ->selectRaw('COUNT(*) as transaction_count')
            ->selectRaw("COALESCE(SUM(CASE WHEN pt.status = 'succeeded' AND o.status <> 'cancelled' THEN pt.amount ELSE 0 END), 0) as collected")
            ->selectRaw('COALESCE(SUM(pt.amount), 0) as total_amount')
            ->groupBy('pt.reporting_date', 'pt.payment_method', 'pt.provider', 'pt.status')
            ->orderBy('pt.reporting_date')
            ->get();

        $channels = DB::table('payment_transactions')
            ->where('status', 'succeeded')
            ->select('payment_method', 'provider')
            ->selectRaw('COUNT(*) as transaction_count')
            ->selectRaw('COALESCE(SUM(amount), 0) as collected')
            ->groupBy('payment_method', 'provider')
            ->get();

        $providers = PaymentGateway::query()
            ->where('is_enabled', true)
            ->get(['provider', 'display_name'])
            ->map(fn (PaymentGateway $gateway) => ['provider' => $gateway->provider, 'name' => $gateway->display_name])
            ->values();

        return response()->json(['success' => true, 'data' => [
            'timezone' => config('app.timezone', 'Africa/Accra'),
            'currency' => 'GHS',
            'currency_symbol' => 'GH₵',
            'generated_at' => now()->toISOString(),
            'coverage' => [
                'from' => $daily->first()?->date,
                'to' => $daily->last()?->date,
            ],
            'daily' => $daily->map(fn ($day) => [
                'date' => $day->date,
                'order_count' => (int) $day->order_count,
                'paid_orders' => (int) $day->paid_orders,
                'unpaid_orders' => (int) $day->unpaid_orders,
                'cancelled_orders' => (int) $day->cancelled_orders,
                'collected' => $this->money($day->collected),
                'cash_collected' => $this->money($day->cash_collected),
                'online_collected' => $this->money($day->online_collected),
                'outstanding' => $this->money($day->outstanding),
                'failed_amount' => $this->money($day->failed_amount),
                'cancelled_amount' => $this->money($day->cancelled_amount),
                'refunded_amount' => $this->money($day->refunded_amount),
                'pending_count' => (int) $day->pending_count,
                'failed_count' => (int) $day->failed_count,
                'cancelled_count' => (int) $day->cancelled_count,
                'refunded_count' => (int) $day->refunded_count,
                'dine_in_collected' => $this->money($day->dine_in_collected),
                'delivery_collected' => $this->money($day->delivery_collected),
            ])->values(),
            'daily_channels' => $dailyChannels->map(fn ($channel) => [
                'date' => $channel->date,
                'payment_method' => $channel->payment_method,
                'provider' => $channel->provider,
                'status' => $channel->status,
                'transaction_count' => (int) $channel->transaction_count,
                'collected' => $this->money($channel->collected),
                'amount' => $this->money($channel->total_amount),
            ])->values(),
            'channels' => $channels->map(fn ($channel) => [
                'payment_method' => $channel->payment_method,
                'provider' => $channel->provider,
                'transaction_count' => (int) $channel->transaction_count,
                'collected' => $this->money($channel->collected),
            ])->values(),
            'providers' => $providers,
        ]]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['nullable', 'in:pending,succeeded,failed,cancelled,refunded'],
            'method' => ['nullable', 'in:cash,gateway'],
            'provider' => ['nullable', 'string', 'max:40'],
            'order_type' => ['nullable', 'in:dine_in,delivery'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->transactionQuery($validated);
        $page = $query->orderByRaw('COALESCE(payment_transactions.received_at, payment_transactions.created_at) DESC')
            ->paginate($validated['per_page'] ?? 25);

        return response()->json(['success' => true, 'data' => $page->getCollection()->map(fn ($row) => $this->transactionData($row)), 'meta' => [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(), 'total' => $page->total(),
        ]]);
    }

    public function showTransaction(int $id): JsonResponse
    {
        $row = $this->transactionQuery()->where('payment_transactions.id', $id)->first();
        abort_unless($row, 404, 'Transaction not found.');
        return response()->json(['success' => true, 'data' => $this->transactionData($row, true)]);
    }

    public function export(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['nullable', 'in:pending,succeeded,failed,cancelled,refunded'],
            'method' => ['nullable', 'in:cash,gateway'],
            'provider' => ['nullable', 'string', 'max:40'],
            'order_type' => ['nullable', 'in:dine_in,delivery'],
        ]);
        $rows = $this->transactionQuery($validated)->orderBy('payment_transactions.id')->cursor();

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Transaction ID', 'Order number', 'Reference', 'Customer', 'Method', 'Provider', 'Amount', 'Currency', 'Status', 'Order type', 'Received at', 'Confirmed by']);
            foreach ($rows as $row) {
                fputcsv($out, [$row->id, $this->csvSafe($row->order_number), $this->csvSafe($row->transaction_reference), $this->csvSafe($row->customer_name),
                    $this->csvSafe($row->payment_method), $this->csvSafe($row->provider), $this->money($row->amount), $this->csvSafe($row->currency), $this->csvSafe($row->status),
                    $this->csvSafe($row->order_type), $this->csvSafe($row->received_at), $this->csvSafe($row->confirmer_name)]);
            }
            fclose($out);
        }, 'financial-transactions.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function transactionQuery(array $filters = []): Builder
    {
        $query = DB::table('payment_transactions')
            ->join('orders', 'orders.id', '=', 'payment_transactions.order_id')
            ->leftJoin('users as confirmer', 'confirmer.id', '=', 'payment_transactions.confirmed_by')
            ->select([
                'payment_transactions.id', 'payment_transactions.order_id', 'payment_transactions.transaction_reference',
                'payment_transactions.provider', 'payment_transactions.payment_method', 'payment_transactions.amount',
                'payment_transactions.currency', 'payment_transactions.status', 'payment_transactions.provider_reference',
                'payment_transactions.verified_at', 'payment_transactions.received_at', 'payment_transactions.created_at',
                'orders.order_number', 'orders.customer_name', 'orders.order_type', 'orders.status as order_status',
                'confirmer.name as confirmer_name',
            ]);
        if (filled($filters['from'] ?? null)) $query->where('payment_transactions.reporting_date', '>=', $filters['from']);
        if (filled($filters['to'] ?? null)) $query->where('payment_transactions.reporting_date', '<=', $filters['to']);
        if (filled($filters['status'] ?? null)) $query->where('payment_transactions.status', $filters['status']);
        if (filled($filters['method'] ?? null)) $query->where('payment_transactions.payment_method', $filters['method']);
        if (filled($filters['provider'] ?? null)) $query->where('payment_transactions.provider', $filters['provider']);
        if (filled($filters['order_type'] ?? null)) $query->where('orders.order_type', $filters['order_type']);
        if (filled($filters['search'] ?? null)) {
            $search = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $filters['search']).'%';
            $query->where(fn ($q) => $q->where('orders.order_number', 'like', $search)
                ->orWhere('payment_transactions.transaction_reference', 'like', $search)
                ->orWhere('orders.customer_name', 'like', $search));
        }
        return $query;
    }

    private function transactionData(object $row, bool $detail = false): array
    {
        $data = [
            'id' => (int) $row->id, 'order_id' => (int) $row->order_id,
            'order_number' => $row->order_number, 'transaction_reference' => $row->transaction_reference,
            'customer_name' => $row->customer_name, 'payment_method' => $row->payment_method,
            'provider' => $row->provider, 'amount' => $this->money($row->amount), 'currency' => $row->currency,
            'status' => $row->status, 'order_type' => $row->order_type,
            'payment_timestamp' => $row->received_at ?: $row->created_at,
            'verified_at' => $row->verified_at, 'confirmed_by' => $row->confirmer_name,
        ];
        if ($detail) {
            $data['provider_reference'] = $row->provider_reference;
            $data['order_status'] = $row->order_status;
        }
        return $data;
    }

    private function money(mixed $amount): string
    {
        $value = (string) $amount;
        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $value, $parts)) return '0.00';
        $fraction = str_pad(substr($parts[3] ?? '', 0, 2), 2, '0');
        $whole = ltrim($parts[2], '0') ?: '0';
        return $parts[1].$whole.'.'.$fraction;
    }

    private function csvSafe(?string $value): string
    {
        $value ??= '';
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
