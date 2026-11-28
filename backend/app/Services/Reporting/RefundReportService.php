<?php

namespace App\Services\Reporting;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * Report G — Refund Report.
 *
 * Only `confirmed` refunds are described as refunded. Requested, approved,
 * submitting and submitted are shown as pipeline / in-flight and never added
 * to the confirmed figure.
 */
final class RefundReportService
{
    public const PIPELINE = ['requested', 'approved'];
    public const IN_FLIGHT = ['submitting', 'submitted'];

    public function register(array $filters, ReportPeriod $period): LengthAwarePaginator
    {
        return $this->filtered($filters, $period)
            ->orderByRaw('COALESCE(rr.confirmed_at, rr.submitted_at, rr.requested_at) DESC')->orderBy('rr.id')
            ->paginate(max(1, min((int) ($filters['per_page'] ?? 25), 100)))
            ->through(fn (object $row) => $this->row($row));
    }

    public function summary(array $filters, ReportPeriod $period): array
    {
        $rows = $this->filtered($filters, $period)
            ->select(DB::raw('rr.status, COUNT(*) as refunds, SUM(rr.amount) as amount'))
            ->groupBy('rr.status')->get();

        $stages = [];
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row->status] = ['count' => (int) $row->refunds, 'amount' => Money::minor($row->amount)];
        }

        $sum = function (array $statuses) use ($counts): array {
            $count = 0;
            $amount = 0;
            foreach ($statuses as $status) {
                $count += $counts[$status]['count'] ?? 0;
                $amount += $counts[$status]['amount'] ?? 0;
            }

            return ['count' => $count, 'amount' => Money::decimal($amount)];
        };

        $stages['requested'] = $sum(['requested']);
        $stages['approved'] = $sum(['approved']);
        $stages['pipeline'] = $sum(self::PIPELINE);
        $stages['in_flight'] = $sum(self::IN_FLIGHT);
        $stages['confirmed'] = $sum(['confirmed']);
        $stages['rejected'] = $sum(['rejected']);
        $stages['failed'] = $sum(['failed']);

        return ['stages' => $stages, 'by_status' => $counts];
    }

    public function exportRows(array $filters, ReportPeriod $period): LazyCollection
    {
        return $this->filtered($filters, $period)->orderBy('rr.id')->cursor()
            ->map(fn (object $row) => $this->row($row))
            ->take(50000);
    }

    private function filtered(array $filters, ReportPeriod $period): Builder
    {
        $query = DB::table('refund_requests as rr')
            ->join('payment_transactions as pt', 'pt.id', '=', 'rr.payment_transaction_id')
            ->leftJoin('customers as c', 'c.id', '=', 'rr.customer_id')
            ->leftJoin('organisations as org', 'org.id', '=', 'rr.organisation_id')
            ->leftJoin('users as requester', 'requester.id', '=', 'rr.requested_by')
            ->leftJoin('users as approver', 'approver.id', '=', 'rr.approved_by')
            ->leftJoin('users as confirmer', 'confirmer.id', '=', 'rr.confirmed_by')
            ->select([
                'rr.id', 'rr.refund_reference', 'rr.amount', 'rr.currency', 'rr.status', 'rr.reason',
                'rr.provider', 'rr.provider_refund_reference', 'rr.verification_reference',
                'rr.requested_at', 'rr.approved_at', 'rr.submitted_at', 'rr.confirmed_at',
                'rr.rejected_at', 'rr.failed_at', 'rr.customer_id', 'rr.organisation_id',
                'pt.transaction_reference', 'pt.payment_method', 'pt.provider as payment_provider',
                'c.name as customer_name', 'org.name as organisation_name',
                'requester.name as requester_name', 'approver.name as approver_name', 'confirmer.name as confirmer_name',
            ]);

        $basis = ($filters['basis'] ?? 'requested') === 'confirmed' ? 'rr.confirmed_at' : 'rr.requested_at';
        [$from, $to] = $period->bounds();
        $query->whereBetween($basis, [$from, $to]);

        if (filled($filters['currency'] ?? null)) {
            $query->where('rr.currency', $filters['currency']);
        }
        if (filled($filters['status'] ?? null)) {
            $query->where('rr.status', $filters['status']);
        }
        if (filled($filters['provider'] ?? null)) {
            $query->where('rr.provider', $filters['provider']);
        }
        if (filled($filters['search'] ?? null)) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['search']).'%';
            $query->where(fn (Builder $search) => $search
                ->where('rr.refund_reference', 'like', $term)
                ->orWhere('rr.provider_refund_reference', 'like', $term)
                ->orWhere('pt.transaction_reference', 'like', $term)
                ->orWhere('c.name', 'like', $term)
                ->orWhere('org.name', 'like', $term));
        }

        return $query;
    }

    private function row(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'refund_reference' => $row->refund_reference,
            'receipt_reference' => $row->transaction_reference,
            'payer' => $row->organisation_id !== null
                ? ['type' => 'organisation', 'id' => (int) $row->organisation_id, 'name' => $row->organisation_name]
                : ['type' => 'customer', 'id' => $row->customer_id !== null ? (int) $row->customer_id : null, 'name' => $row->customer_name],
            'amount' => Money::decimal(Money::minor($row->amount)),
            'currency' => $row->currency,
            'status' => $row->status,
            'stage' => match ($row->status) {
                'requested', 'approved' => 'pipeline',
                'submitting', 'submitted' => 'in_flight',
                'confirmed' => 'completed',
                'rejected' => 'rejected',
                'failed' => 'failed',
                default => $row->status,
            },
            'reason' => $row->reason,
            'requesting_staff' => $row->requester_name,
            'approving_staff' => $row->approver_name,
            'confirming_staff' => $row->confirmer_name,
            'provider' => $row->provider ?: $row->payment_provider,
            'provider_submission_status' => $row->submitted_at ? ($row->confirmed_at ? 'confirmed' : 'submitted') : 'not_submitted',
            'provider_confirmation_reference' => $row->verification_reference ?: $row->provider_refund_reference,
            'requested_at' => $row->requested_at,
            'approved_at' => $row->approved_at,
            'submitted_at' => $row->submitted_at,
            'completed_at' => $row->confirmed_at,
            'rejected_at' => $row->rejected_at,
            'failed_at' => $row->failed_at,
            'payment_method' => $row->payment_method,
        ];
    }
}
