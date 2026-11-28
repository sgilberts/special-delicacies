<?php

namespace App\Services;

use App\Events\CustomerChanged;
use App\Models\AdminAuditLog;
use App\Models\Customer;
use App\Models\CustomerAuditLog;
use App\Models\User;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Administrative customer management.
 *
 * Reuses the existing customer identity rules (normalized email, Ghanaian phone
 * normalization and the customers table's unique constraints) and the existing
 * audit conventions (CustomerAuditLog for the customer timeline plus
 * AdminAuditLog for the acting staff member).
 *
 * There is deliberately no hard delete: a customer may own orders, receipts,
 * invoices, refunds and customer-credit ledger entries, so removal is an
 * archive that preserves every relationship.
 */
class CustomerManagementService
{
    /** The only profile fields an administrator may write through the portal. */
    public const EDITABLE_FIELDS = ['name', 'email', 'phone'];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_ARCHIVED = 'archived';

    public const SORTS = ['name', 'joined'];

    public function __construct(private readonly AuditService $auditService) {}

    /**
     * Server-side listing: search, filters, sorting and pagination all run in
     * the database, never over the current page in the browser.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listing(array $filters): LengthAwarePaginator
    {
        $query = Customer::query()
            // Sub-selects keep the list at one query: no per-row lookups.
            ->withMax('orders', 'created_at')
            ->withMax('paymentTransactions', 'created_at')
            ->withExists(['organisationMemberships as has_active_organisation' => fn ($membership) => $membership->where('status', 'active')]);

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
            $phoneVariants = [];
            try {
                $phoneVariants = GhanaPhoneNumber::lookupVariants(GhanaPhoneNumber::normalize($search));
            } catch (ValidationException) {
                // The term is not a phone number; name/email/phone text search still applies.
            }
            $query->where(function ($where) use ($term, $phoneVariants): void {
                $where->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term);
                foreach ($phoneVariants as $variant) {
                    $where->orWhere('phone', 'like', '%'.$variant.'%');
                }
            });
        }

        match ((string) ($filters['status'] ?? '')) {
            self::STATUS_ACTIVE => $query->whereNull('archived_at')->where('is_active', true),
            self::STATUS_INACTIVE => $query->whereNull('archived_at')->where('is_active', false),
            self::STATUS_ARCHIVED => $query->whereNotNull('archived_at'),
            default => null,
        };

        match ((string) ($filters['type'] ?? '')) {
            'organisation' => $query->whereHas('organisationMemberships', fn ($membership) => $membership->where('status', 'active')),
            'individual' => $query->whereDoesntHave('organisationMemberships', fn ($membership) => $membership->where('status', 'active')),
            default => null,
        };

        $sort = in_array($filters['sort'] ?? null, self::SORTS, true) ? $filters['sort'] : 'joined';
        $direction = strtolower((string) ($filters['direction'] ?? '')) === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort === 'name' ? 'name' : 'created_at', $direction)->orderByDesc('id');

        $perPage = max(1, min((int) ($filters['per_page'] ?? 15), 100));

        return $query->paginate($perPage)->through(fn (Customer $customer) => $this->summary($customer));
    }

    /** @return array<string, int> */
    public function statusCounts(): array
    {
        return [
            'total' => Customer::query()->count(),
            'active' => Customer::query()->whereNull('archived_at')->where('is_active', true)->count(),
            'inactive' => Customer::query()->whereNull('archived_at')->where('is_active', false)->count(),
            'archived' => Customer::query()->whereNotNull('archived_at')->count(),
        ];
    }

    public function findOrFail(int $id): Customer
    {
        return Customer::query()->findOrFail($id);
    }

    /** @return array<string, mixed> */
    public function summary(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'reference' => $this->reference($customer),
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'status' => $this->status($customer),
            'customer_type' => ($customer->has_active_organisation ?? false) ? 'organisation' : 'individual',
            'email_verified' => $customer->email_verified_at !== null,
            'phone_verified' => $customer->phone_verified_at !== null,
            'joined_at' => $customer->created_at,
            'last_activity_at' => $this->lastActivityAt($customer),
            'archived_at' => $customer->archived_at,
        ];
    }

    /** @return array<string, mixed> */
    public function detail(Customer $customer): array
    {
        $customer->loadMissing(['organisationMemberships' => fn ($membership) => $membership->with('organisation:id,name')->orderBy('id')]);

        return $this->summary($customer) + [
            'memberships' => $customer->organisationMemberships->map(fn ($membership) => [
                'organisation' => $membership->organisation?->name,
                'role' => $membership->role,
                'status' => $membership->status,
                'joined_at' => $membership->joined_at,
            ])->values()->all(),
            'updated_at' => $customer->updated_at,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input, User $actor, Request $request): Customer
    {
        $values = $this->normalize(Arr::only($input, self::EDITABLE_FIELDS), null);

        try {
            $customer = DB::transaction(function () use ($values, $actor, $request): Customer {
                $customer = new Customer();
                $customer->fill($values + [
                    'is_active' => true,
                    // The customer sets their own password through the existing
                    // password-recovery flow; an administrator never chooses it.
                    'password' => Str::random(48),
                ]);
                $customer->archived_at = null;
                $customer->save();

                $this->record($customer, $actor, 'customer.created', 'Created customer record', $request, null, $values);

                return $customer;
            });
        } catch (QueryException) {
            throw ValidationException::withMessages(['email' => ['An account with those contact details already exists.']]);
        }

        $this->broadcast($customer, 'created');

        return $customer;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(Customer $customer, array $input, User $actor, Request $request): Customer
    {
        $values = $this->normalize(Arr::only($input, self::EDITABLE_FIELDS), $customer);
        $before = Arr::only($customer->getAttributes(), array_keys($values));
        $changed = array_keys(array_filter($values, fn ($value, $field) => (string) $customer->getAttribute($field) !== (string) $value, ARRAY_FILTER_USE_BOTH));

        if ($changed === []) {
            return $customer;
        }

        try {
            DB::transaction(function () use ($customer, $values, $changed, $before, $actor, $request): void {
                // Changing a contact detail invalidates its verification, exactly
                // as the customer's own profile update does.
                if (in_array('email', $changed, true)) {
                    $customer->email_verified_at = null;
                }
                if (in_array('phone', $changed, true) && ! GhanaPhoneNumber::sameNumber($values['phone'] ?? null, (string) $customer->phone)) {
                    $customer->phone_verified_at = null;
                }

                $customer->fill($values)->save();

                $this->record(
                    $customer,
                    $actor,
                    'customer.updated',
                    'Updated customer profile',
                    $request,
                    Arr::only($before, $changed),
                    Arr::only($values, $changed)
                );
            });
        } catch (QueryException) {
            throw ValidationException::withMessages(['email' => ['An account with those contact details already exists.']]);
        }

        $customer = $customer->refresh();
        $this->broadcast($customer, 'updated');

        return $customer;
    }

    public function archive(Customer $customer, User $actor, Request $request): Customer
    {
        if ($customer->isArchived()) {
            throw new DomainException('This customer is already archived.');
        }

        DB::transaction(function () use ($customer, $actor, $request): void {
            $customer->forceFill(['archived_at' => now(), 'is_active' => false])->save();
            $this->record($customer, $actor, 'customer.archived', 'Archived customer record', $request, ['status' => self::STATUS_ACTIVE], ['status' => self::STATUS_ARCHIVED]);
        });

        $customer = $customer->refresh();
        $this->broadcast($customer, 'archived');

        return $customer;
    }

    public function restore(Customer $customer, User $actor, Request $request): Customer
    {
        if (! $customer->isArchived()) {
            throw new DomainException('This customer is not archived.');
        }

        DB::transaction(function () use ($customer, $actor, $request): void {
            $customer->forceFill(['archived_at' => null, 'is_active' => true])->save();
            $this->record($customer, $actor, 'customer.restored', 'Restored archived customer', $request, ['status' => self::STATUS_ARCHIVED], ['status' => self::STATUS_ACTIVE]);
        });

        $customer = $customer->refresh();
        $this->broadcast($customer, 'restored');

        return $customer;
    }

    /**
     * Customer timeline: the customer's own audit entries plus the staff audit
     * trail for the same record, newest first. Nothing is synthesised.
     *
     * @return list<array<string, mixed>>
     */
    public function activity(Customer $customer): array
    {
        $customerEvents = CustomerAuditLog::query()
            ->where('customer_id', $customer->id)
            ->latest('created_at')->latest('id')->limit(50)->get()
            ->map(fn (CustomerAuditLog $log) => [
                'source' => 'customer',
                'action' => $log->action,
                'fields' => array_values($log->changed_fields ?? []),
                'actor' => null,
                'description' => null,
                'occurred_at' => $log->created_at,
            ]);

        $adminEvents = AdminAuditLog::query()
            ->with('user:id,name')
            ->where('entity_type', Customer::class)
            ->where('entity_id', (string) $customer->id)
            ->latest('created_at')->latest('id')->limit(50)->get()
            ->map(fn (AdminAuditLog $log) => [
                'source' => 'admin',
                'action' => $log->action,
                'fields' => array_keys($log->new_values ?? []),
                'actor' => $log->user?->name,
                'description' => $log->description,
                'occurred_at' => $log->created_at,
            ]);

        return $customerEvents->concat($adminEvents)
            ->sortByDesc(fn (array $event) => optional($event['occurred_at'])->getTimestamp() ?? 0)
            ->take(50)->values()->all();
    }

    public function status(Customer $customer): string
    {
        if ($customer->isArchived()) {
            return self::STATUS_ARCHIVED;
        }

        return $customer->is_active ? self::STATUS_ACTIVE : self::STATUS_INACTIVE;
    }

    public function reference(Customer $customer): string
    {
        return 'CUS-'.str_pad((string) $customer->id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Normalize and de-duplicate the editable identity fields using the same
     * rules as customer registration.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function normalize(array $values, ?Customer $existing): array
    {
        if (array_key_exists('name', $values)) {
            $values['name'] = trim((string) $values['name']);
        }

        if (array_key_exists('email', $values)) {
            $email = $values['email'] === null ? null : mb_strtolower(trim((string) $values['email']));
            $values['email'] = $email === '' ? null : $email;

            if ($values['email'] !== null) {
                $duplicate = Customer::query()
                    ->whereRaw('LOWER(email) = ?', [$values['email']])
                    ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))
                    ->exists();
                if ($duplicate) {
                    throw ValidationException::withMessages(['email' => ['Another customer already uses this email address.']]);
                }
            }
        }

        if (array_key_exists('phone', $values)) {
            $phone = GhanaPhoneNumber::normalize((string) $values['phone']);

            $duplicate = Customer::query()
                ->whereIn('phone', GhanaPhoneNumber::lookupVariants($phone))
                ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))
                ->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['phone' => ['Another customer already uses this phone number.']]);
            }

            $values['phone'] = $phone;
        }

        return $values;
    }

    private function lastActivityAt(Customer $customer): mixed
    {
        $candidates = array_filter([
            $customer->orders_max_created_at ?? null,
            $customer->payment_transactions_max_created_at ?? null,
            $customer->updated_at,
        ]);

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn ($first, $second) => (string) $first <=> (string) $second);

        return end($candidates);
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private function record(Customer $customer, User $actor, string $action, string $description, Request $request, ?array $old, ?array $new): void
    {
        CustomerAuditLog::create([
            'customer_id' => $customer->id,
            'action' => Str::after($action, 'customer.'),
            'entity_type' => Customer::class,
            'entity_id' => (string) $customer->id,
            'changed_fields' => array_keys($new ?? []),
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);

        $this->auditService->log($actor, $action, $description, $customer, $old, $new, $request);
    }

    /**
     * Announce a committed customer change on the private `customers` channel.
     * Only identity and lifecycle status travel — financial figures stay behind
     * `customers.view_financials` and are refetched by authorised clients.
     */
    private function broadcast(Customer $customer, string $action): void
    {
        CustomerChanged::dispatch($customer->id, $action, $this->status($customer));
    }
}
