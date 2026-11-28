<?php

namespace App\Services;

use App\Events\OrganisationChanged;
use App\Events\OrganisationMembershipChanged;
use App\Models\AdminAuditLog;
use App\Models\Customer;
use App\Models\CustomerAuditLog;
use App\Models\Organisation;
use App\Models\OrganisationMembership;
use App\Models\User;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Administrative organisation management.
 *
 * Reuses the Stage 2 organisation/membership foundation: no duplicate table,
 * no parallel membership rules. The service owns listing, profile writes, the
 * archive lifecycle and membership status changes; every financial figure is
 * read through the existing financial services in
 * OrganisationFinancialOverviewService.
 */
class OrganisationManagementService
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_ARCHIVED = 'archived';

    /** Profile fields an administrator may write. */
    private const EDITABLE_FIELDS = ['name', 'description', 'business_email', 'business_phone', 'address'];

    private const MEMBER_STATUSES = [
        OrganisationMembership::STATUS_ACTIVE,
        OrganisationMembership::STATUS_PENDING,
        OrganisationMembership::STATUS_SUSPENDED,
        OrganisationMembership::STATUS_REMOVED,
    ];

    public function __construct(private readonly AuditService $auditService) {}

    /** @param array<string, mixed> $filters */
    public function listing(array $filters): LengthAwarePaginator
    {
        $query = Organisation::query()
            ->with(['owner:id,name,email,phone'])
            ->withCount([
                'memberships as members_count',
                'memberships as active_members_count' => fn (Builder $members) => $members->where('status', OrganisationMembership::STATUS_ACTIVE),
                'memberships as pending_members_count' => fn (Builder $members) => $members->where('status', OrganisationMembership::STATUS_PENDING),
            ]);

        $this->applySearch($query, trim((string) ($filters['search'] ?? '')));
        $this->applyStatusFilter($query, (string) ($filters['status'] ?? ''));

        $membershipStatus = (string) ($filters['membership_status'] ?? '');
        if (in_array($membershipStatus, self::MEMBER_STATUSES, true)) {
            $query->whereHas('memberships', fn (Builder $members) => $members->where('status', $membershipStatus));
        }

        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        match ((string) ($filters['sort'] ?? 'created')) {
            'name' => $query->orderBy('name', $direction),
            'members' => $query->orderBy('members_count', $direction),
            default => $query->orderBy('created_at', $direction),
        };
        $query->orderBy('id');

        $perPage = max(1, min((int) ($filters['per_page'] ?? 15), 100));

        return $query->paginate($perPage)->through(fn (Organisation $organisation) => $this->row($organisation));
    }

    /** @return array{total: int, active: int, inactive: int, archived: int} */
    public function statusCounts(): array
    {
        return [
            'total' => Organisation::query()->count(),
            'active' => Organisation::query()->whereNull('archived_at')->where('status', Organisation::STATUS_ACTIVE)->count(),
            'inactive' => Organisation::query()->whereNull('archived_at')->where('status', '!=', Organisation::STATUS_ACTIVE)->count(),
            'archived' => Organisation::query()->whereNotNull('archived_at')->count(),
        ];
    }

    public function findOrFail(int $id): Organisation
    {
        return Organisation::query()->with('owner:id,name,email,phone')->findOrFail($id);
    }

    /**
     * Profile, membership overview and counts for the detail modal.
     *
     * @return array<string, mixed>
     */
    public function detail(Organisation $organisation): array
    {
        $organisation->loadMissing('owner:id,name,email,phone');

        $byStatus = $organisation->memberships()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')->pluck('aggregate', 'status');

        $byRole = $organisation->memberships()
            ->where('status', OrganisationMembership::STATUS_ACTIVE)
            ->selectRaw('role, COUNT(*) as aggregate')
            ->groupBy('role')->pluck('aggregate', 'role');

        return [
            'id' => $organisation->id,
            'reference' => $organisation->reference,
            'name' => $organisation->name,
            'description' => $organisation->description,
            'business_email' => $organisation->business_email,
            'business_phone' => $organisation->business_phone,
            'address' => $organisation->address,
            'status' => $this->status($organisation),
            'raw_status' => $organisation->status,
            'owner' => $organisation->owner ? [
                'id' => $organisation->owner->id,
                'name' => $organisation->owner->name,
                'email' => $organisation->owner->email,
                'phone' => $organisation->owner->phone,
            ] : null,
            'membership' => [
                'total' => (int) $byStatus->sum(),
                'active' => (int) ($byStatus[OrganisationMembership::STATUS_ACTIVE] ?? 0),
                'pending' => (int) ($byStatus[OrganisationMembership::STATUS_PENDING] ?? 0),
                'suspended' => (int) ($byStatus[OrganisationMembership::STATUS_SUSPENDED] ?? 0),
                'removed' => (int) ($byStatus[OrganisationMembership::STATUS_REMOVED] ?? 0),
                'roles' => collect($byRole)->map(fn ($count, $role) => ['role' => $role, 'count' => (int) $count])->values()->all(),
            ],
            'created_at' => $organisation->created_at,
            'updated_at' => $organisation->updated_at,
            'archived_at' => $organisation->archived_at,
        ];
    }

    /** @param array<string, mixed> $filters */
    public function members(Organisation $organisation, array $filters): LengthAwarePaginator
    {
        $query = $organisation->memberships()
            ->with(['customer:id,name,email,phone,is_active', 'inviter:id,name'])
            ->withExists(['permissions as has_financial_access' => fn (Builder $permissions) => $permissions->where('permission', 'financials.view')]);

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->whereHas('customer', fn (Builder $customer) => $customer
                ->where('name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('phone', 'like', $like));
        }

        if (in_array((string) ($filters['status'] ?? ''), self::MEMBER_STATUSES, true)) {
            $query->where('status', $filters['status']);
        }

        if (in_array((string) ($filters['role'] ?? ''), [OrganisationMembership::ROLE_OWNER, OrganisationMembership::ROLE_ADMINISTRATOR, OrganisationMembership::ROLE_MANAGER, OrganisationMembership::ROLE_MEMBER], true)) {
            $query->where('role', $filters['role']);
        }

        $perPage = max(1, min((int) ($filters['per_page'] ?? 10), 100));

        return $query->orderByRaw("CASE WHEN role = 'owner' THEN 0 WHEN role = 'administrator' THEN 1 ELSE 2 END")
            ->orderBy('id')
            ->paginate($perPage)
            ->through(fn (OrganisationMembership $membership) => [
                'id' => $membership->id,
                'customer' => $membership->customer ? [
                    'id' => $membership->customer->id,
                    'name' => $membership->customer->name,
                    'email' => $membership->customer->email,
                    'phone' => $membership->customer->phone,
                    'is_active' => (bool) $membership->customer->is_active,
                ] : null,
                'role' => $membership->role,
                'status' => $membership->status,
                'financial_access' => (bool) $membership->has_financial_access,
                'invited_by' => $membership->inviter?->name,
                'joined_at' => $membership->joined_at,
                'approved_at' => $membership->approved_at,
                'created_at' => $membership->created_at,
            ]);
    }

    /**
     * Active customers that may be chosen as an organisation's primary contact.
     * Scoped to the organisation capability so it never exposes more than the
     * picker needs.
     *
     * @return list<array<string, mixed>>
     */
    public function contactCandidates(string $search): array
    {
        $query = Customer::query()->where('is_active', true)->whereNull('archived_at');

        $search = trim($search);
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(fn (Builder $customer) => $customer
                ->where('name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('phone', 'like', $like));
        }

        return $query->orderBy('name')->limit(20)
            ->get(['id', 'name', 'email', 'phone'])
            ->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
            ])->all();
    }

    /** @param array<string, mixed> $input */
    public function create(array $input, User $actor, Request $request): Organisation
    {
        $values = $this->normalize(Arr::only($input, self::EDITABLE_FIELDS));
        $owner = $this->resolveOwner((int) ($input['owner_customer_id'] ?? 0));

        $organisation = DB::transaction(function () use ($values, $owner, $actor, $request): Organisation {
            $organisation = Organisation::create([
                'reference' => 'ORG-'.Str::upper((string) Str::ulid()),
                'owner_customer_id' => $owner->id,
                'name' => $values['name'],
                'description' => $values['description'] ?? null,
                'business_email' => $values['business_email'] ?? null,
                'business_phone' => $values['business_phone'] ?? null,
                'address' => $values['address'] ?? null,
                'status' => Organisation::STATUS_ACTIVE,
            ]);

            // The owner is a real membership row, exactly as the customer-side
            // creation flow records it — the organisation module never invents
            // a parallel ownership model.
            $organisation->memberships()->create([
                'customer_id' => $owner->id,
                'role' => OrganisationMembership::ROLE_OWNER,
                'status' => OrganisationMembership::STATUS_ACTIVE,
                'joined_at' => now(),
                'approved_at' => now(),
            ]);

            $this->record($actor, 'organisation.created', 'Created organisation record', $organisation, $request, null, $values);

            return $organisation;
        });

        $organisation = $organisation->refresh();
        $this->broadcastOrganisation($organisation, 'created');

        return $organisation;
    }

    /** @param array<string, mixed> $input */
    public function update(Organisation $organisation, array $input, User $actor, Request $request): Organisation
    {
        $values = $this->normalize(Arr::only($input, self::EDITABLE_FIELDS), $organisation);
        $before = Arr::only($organisation->getAttributes(), array_keys($values));
        $changed = array_keys(array_filter(
            $values,
            fn ($value, $field) => (string) $organisation->getAttribute($field) !== (string) $value,
            ARRAY_FILTER_USE_BOTH,
        ));

        if ($changed === []) {
            return $organisation;
        }

        DB::transaction(function () use ($organisation, $values, $changed, $before, $actor, $request): void {
            $organisation->fill($values)->save();

            $this->record(
                $actor,
                'organisation.updated',
                'Updated organisation profile',
                $organisation,
                $request,
                Arr::only($before, $changed),
                Arr::only($values, $changed),
            );
        });

        $organisation = $organisation->refresh();
        $this->broadcastOrganisation($organisation, 'updated');

        return $organisation;
    }

    public function archive(Organisation $organisation, User $actor, Request $request): Organisation
    {
        if ($organisation->isArchived()) {
            throw new DomainException('This organisation is already archived.');
        }

        DB::transaction(function () use ($organisation, $actor, $request): void {
            $organisation->forceFill(['archived_at' => now()])->save();

            $this->record(
                $actor,
                'organisation.archived',
                'Archived organisation record',
                $organisation,
                $request,
                ['status' => $this->status($organisation)],
                ['status' => self::STATUS_ARCHIVED],
            );
        });

        $organisation = $organisation->refresh();
        $this->broadcastOrganisation($organisation, 'archived');

        return $organisation;
    }

    public function restore(Organisation $organisation, User $actor, Request $request): Organisation
    {
        if (! $organisation->isArchived()) {
            throw new DomainException('This organisation is not archived.');
        }

        DB::transaction(function () use ($organisation, $actor, $request): void {
            $organisation->forceFill(['archived_at' => null])->save();

            $this->record(
                $actor,
                'organisation.restored',
                'Restored archived organisation',
                $organisation,
                $request,
                ['status' => self::STATUS_ARCHIVED],
                ['status' => $this->status($organisation)],
            );
        });

        $organisation = $organisation->refresh();
        $this->broadcastOrganisation($organisation, 'restored');

        return $organisation;
    }

    public function updateMemberStatus(
        Organisation $organisation,
        OrganisationMembership $membership,
        string $status,
        User $actor,
        Request $request,
    ): OrganisationMembership {
        if ((int) $membership->organisation_id !== (int) $organisation->id) {
            throw new DomainException('Member not found.');
        }

        if (! in_array($status, self::MEMBER_STATUSES, true)) {
            throw ValidationException::withMessages(['status' => ['Select a valid membership status.']]);
        }

        if ($membership->role === OrganisationMembership::ROLE_OWNER) {
            throw new DomainException('The owner membership cannot be suspended or removed.');
        }

        if ($membership->status === $status) {
            return $membership;
        }

        $allowed = [
            OrganisationMembership::STATUS_ACTIVE => [OrganisationMembership::STATUS_SUSPENDED, OrganisationMembership::STATUS_REMOVED],
            OrganisationMembership::STATUS_SUSPENDED => [OrganisationMembership::STATUS_ACTIVE, OrganisationMembership::STATUS_REMOVED],
            OrganisationMembership::STATUS_PENDING => [OrganisationMembership::STATUS_ACTIVE, OrganisationMembership::STATUS_REMOVED],
            OrganisationMembership::STATUS_REMOVED => [],
        ];

        if (! in_array($status, $allowed[$membership->status] ?? [], true)) {
            throw new DomainException('This membership status transition is not allowed.');
        }

        $previous = $membership->status;

        DB::transaction(function () use ($organisation, $membership, $status, $previous, $actor, $request): void {
            $locked = OrganisationMembership::query()->lockForUpdate()->findOrFail($membership->id);
            if ((int) $locked->organisation_id !== (int) $organisation->id || $locked->role === OrganisationMembership::ROLE_OWNER) {
                throw new DomainException('This membership can no longer be changed.');
            }

            $locked->status = $status;
            if ($status === OrganisationMembership::STATUS_ACTIVE && $locked->joined_at === null) {
                $locked->joined_at = now();
                $locked->approved_at = now();
            }
            $locked->save();

            $this->record(
                $actor,
                'organisation.membership.status_changed',
                'Changed an organisation membership status',
                $organisation,
                $request,
                ['membership_id' => $locked->id, 'status' => $previous],
                ['membership_id' => $locked->id, 'status' => $status],
            );
        });

        $membership = $membership->refresh();
        $this->broadcastMembership($organisation, $membership, 'status_changed');

        return $membership;
    }

    /**
     * Organisation timeline: administrator audit entries plus the customer-side
     * organisation audit trail (including membership changes). Nothing is
     * synthesised.
     *
     * @return list<array<string, mixed>>
     */
    public function activity(Organisation $organisation): array
    {
        $adminEvents = AdminAuditLog::query()
            ->with('user:id,name')
            ->where('entity_type', Organisation::class)
            ->where('entity_id', (string) $organisation->id)
            ->latest('created_at')->latest('id')->limit(50)->get()
            ->map(fn (AdminAuditLog $log) => [
                'source' => 'admin',
                'action' => $log->action,
                'fields' => array_keys($log->new_values ?? []),
                'actor' => $log->user?->name,
                'description' => $log->description,
                'occurred_at' => $log->created_at,
            ]);

        $memberEvents = CustomerAuditLog::query()
            ->where('organisation_id', $organisation->id)
            ->latest('created_at')->latest('id')->limit(50)->get()
            ->map(fn (CustomerAuditLog $log) => [
                'source' => 'organisation',
                'action' => $log->action,
                'fields' => array_values($log->changed_fields['fields'] ?? []),
                'actor' => null,
                'description' => null,
                'occurred_at' => $log->created_at,
            ]);

        return $adminEvents->concat($memberEvents)
            ->sortByDesc(fn (array $event) => optional($event['occurred_at'])->getTimestamp() ?? 0)
            ->take(50)->values()->all();
    }

    public function status(Organisation $organisation): string
    {
        if ($organisation->isArchived()) {
            return self::STATUS_ARCHIVED;
        }

        return $organisation->status === Organisation::STATUS_ACTIVE ? self::STATUS_ACTIVE : self::STATUS_INACTIVE;
    }

    /** @return array<string, mixed> */
    private function row(Organisation $organisation): array
    {
        return [
            'id' => $organisation->id,
            'reference' => $organisation->reference,
            'name' => $organisation->name,
            'contact_person' => $organisation->owner?->name,
            'email' => $organisation->business_email ?? $organisation->owner?->email,
            'phone' => $organisation->business_phone ?? $organisation->owner?->phone,
            'members_count' => (int) ($organisation->members_count ?? 0),
            'active_members_count' => (int) ($organisation->active_members_count ?? 0),
            'pending_members_count' => (int) ($organisation->pending_members_count ?? 0),
            'status' => $this->status($organisation),
            'created_at' => $organisation->created_at,
            'updated_at' => $organisation->updated_at,
            'archived_at' => $organisation->archived_at,
        ];
    }

    private function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $like = '%'.$search.'%';
        $query->where(function (Builder $scope) use ($like): void {
            $scope->where('name', 'like', $like)
                ->orWhere('reference', 'like', $like)
                ->orWhere('business_email', 'like', $like)
                ->orWhere('business_phone', 'like', $like)
                ->orWhereHas('owner', fn (Builder $owner) => $owner
                    ->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like));
        });
    }

    private function applyStatusFilter(Builder $query, string $status): void
    {
        if ($status === self::STATUS_ARCHIVED) {
            $query->whereNotNull('archived_at');
            return;
        }

        if ($status === self::STATUS_ACTIVE) {
            $query->whereNull('archived_at')->where('status', Organisation::STATUS_ACTIVE);
            return;
        }

        if ($status === self::STATUS_INACTIVE) {
            $query->whereNull('archived_at')->where('status', '!=', Organisation::STATUS_ACTIVE);
        }
    }

    private function resolveOwner(int $customerId): Customer
    {
        $owner = Customer::query()
            ->whereKey($customerId)
            ->where('is_active', true)
            ->whereNull('archived_at')
            ->first();

        if (! $owner) {
            throw ValidationException::withMessages(['owner_customer_id' => ['Select an active customer as the primary contact.']]);
        }

        return $owner;
    }

    /**
     * Normalize profile values and enforce the organisation identity rules.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function normalize(array $values, ?Organisation $existing = null): array
    {
        if (array_key_exists('name', $values)) {
            $values['name'] = trim((string) $values['name']);

            $duplicate = Organisation::query()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($values['name'])])
                ->when($existing, fn (Builder $query) => $query->whereKeyNot($existing->id))
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages(['name' => ['An organisation with this name already exists.']]);
            }
        }

        if (array_key_exists('business_email', $values)) {
            $email = $values['business_email'] === null ? null : mb_strtolower(trim((string) $values['business_email']));
            $values['business_email'] = $email === '' ? null : $email;
        }

        if (array_key_exists('business_phone', $values)) {
            $phone = $values['business_phone'] === null ? null : trim((string) $values['business_phone']);
            if ($phone === '') {
                $values['business_phone'] = null;
            } else {
                try {
                    $values['business_phone'] = GhanaPhoneNumber::normalize($phone);
                } catch (ValidationException) {
                    throw ValidationException::withMessages(['business_phone' => ['Enter a valid Ghanaian phone number.']]);
                }
            }
        }

        foreach (['description', 'address'] as $field) {
            if (array_key_exists($field, $values)) {
                $value = $values[$field] === null ? null : trim((string) $values[$field]);
                $values[$field] = $value === '' ? null : $value;
            }
        }

        return $values;
    }

    /**
     * Announce a change only after the service's database transaction has
     * committed: every caller dispatches from after DB::transaction() returned,
     * so a rolled-back transaction throws and never reaches this point.
     */
    private function broadcastOrganisation(Organisation $organisation, string $action): void
    {
        $counts = $this->membershipCounts($organisation);

        OrganisationChanged::dispatch(
            $organisation->id,
            $action,
            $this->status($organisation),
            $counts['total'],
            $counts['active'],
        );
    }

    private function broadcastMembership(Organisation $organisation, OrganisationMembership $membership, string $action): void
    {
        $counts = $this->membershipCounts($organisation);

        OrganisationMembershipChanged::dispatch(
            $organisation->id,
            $action,
            $membership->id,
            $membership->status,
            $membership->role,
            $counts['total'],
            $counts['active'],
        );
    }

    /** @return array{total: int, active: int} */
    private function membershipCounts(Organisation $organisation): array
    {
        return [
            'total' => $organisation->memberships()->count(),
            'active' => $organisation->memberships()->where('status', OrganisationMembership::STATUS_ACTIVE)->count(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private function record(User $actor, string $action, string $description, Organisation $organisation, Request $request, ?array $old, ?array $new): void
    {
        $this->auditService->log($actor, $action, $description, $organisation, $old, $new, $request);
    }
}
