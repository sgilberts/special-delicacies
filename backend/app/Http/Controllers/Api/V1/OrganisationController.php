<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerAuditLog;
use App\Models\Organisation;
use App\Models\OrganisationMembership;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class OrganisationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $memberships = $customer->organisationMemberships()
            ->where('status', OrganisationMembership::STATUS_ACTIVE)
            ->with('organisation')
            ->get();

        return response()->json(['success' => true, 'data' => $memberships->map(fn ($membership) => [
            'id' => $membership->organisation->id,
            'reference' => $membership->organisation->reference,
            'name' => $membership->organisation->name,
            'status' => $membership->organisation->status,
            'role' => $membership->role,
            'membership_status' => $membership->status,
        ])->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'business_email' => ['nullable', 'email', 'max:255'],
            'business_phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+().\-\s]+$/'],
            'address' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'The given data was invalid.', 'errors' => $validator->errors()], 422);
        }

        $values = $validator->validated();
        try {
            $organisation = DB::transaction(function () use ($request, $customer, $values): Organisation {
                $organisation = Organisation::create([
                    'reference' => 'ORG-'.Str::upper((string) Str::ulid()),
                    'owner_customer_id' => $customer->id,
                    'name' => trim($values['name']),
                    'description' => $values['description'] ?? null,
                    'business_email' => isset($values['business_email']) ? mb_strtolower(trim($values['business_email'])) : null,
                    'business_phone' => isset($values['business_phone']) ? trim($values['business_phone']) : null,
                    'address' => isset($values['address']) ? trim($values['address']) : null,
                    'status' => Organisation::STATUS_ACTIVE,
                ]);

                $organisation->memberships()->create([
                    'customer_id' => $customer->id,
                    'role' => OrganisationMembership::ROLE_OWNER,
                    'status' => OrganisationMembership::STATUS_ACTIVE,
                    'joined_at' => now(),
                    'approved_at' => now(),
                ]);
                $this->audit($request, $customer, $organisation, 'organisation.created', $organisation, ['name', 'description', 'business_email', 'business_phone', 'address']);
                return $organisation;
            });
        } catch (QueryException) {
            return response()->json(['success' => false, 'message' => 'The organisation could not be created. Please retry.'], 409);
        }

        return response()->json(['success' => true, 'message' => 'Organisation created.', 'data' => $this->organisationData($organisation, OrganisationMembership::ROLE_OWNER)], 201);
    }

    public function show(Request $request, Organisation $organisation): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $role = $customer->organisationMemberships()->where('organisation_id', $organisation->id)->where('status', OrganisationMembership::STATUS_ACTIVE)->value('role');
        return response()->json(['success' => true, 'data' => $this->organisationData($organisation, $role)]);
    }

    public function update(Request $request, Organisation $organisation): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'business_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'business_phone' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^[0-9+().\-\s]+$/'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'The given data was invalid.', 'errors' => $validator->errors()], 422);
        }

        $values = $validator->validated();
        DB::transaction(function () use ($request, $customer, $organisation, $values): void {
            $organisation = $this->assertLockedPermission($customer, $organisation, 'profile.update');
            foreach ($values as $field => $value) {
                $organisation->{$field} = is_string($value) ? trim($value) : $value;
            }
            if (array_key_exists('business_email', $values) && $values['business_email'] !== null) {
                $organisation->business_email = mb_strtolower(trim($values['business_email']));
            }
            $organisation->save();
            $this->audit($request, $customer, $organisation, 'organisation.profile_updated', $organisation, array_keys($values));
        });

        $role = $customer->organisationMemberships()->where('organisation_id', $organisation->id)->where('status', OrganisationMembership::STATUS_ACTIVE)->value('role');
        return response()->json(['success' => true, 'message' => 'Organisation profile updated.', 'data' => $this->organisationData($organisation->fresh(), $role)]);
    }

    public function updateStatus(Request $request, Organisation $organisation): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $validator = Validator::make($request->all(), ['status' => ['required', 'in:active,suspended']]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'The given data was invalid.', 'errors' => $validator->errors()], 422);
        }

        $oldStatus = $organisation->status;
        $newStatus = $validator->validated()['status'];
        if ($oldStatus !== $newStatus) {
            DB::transaction(function () use ($request, $customer, $organisation, $oldStatus, $newStatus): void {
                $locked = $this->assertLockedPermission($customer, $organisation, 'status.manage');
                if ($locked->owner_customer_id !== $customer->id || $locked->status !== $oldStatus) {
                    abort(403);
                }
                $locked->status = $newStatus;
                $locked->save();
                $this->audit($request, $customer, $locked, 'organisation.status_changed', $locked, ['status'], ['status' => $oldStatus], ['status' => $newStatus]);
            });
        }

        return response()->json(['success' => true, 'data' => $this->organisationData($organisation->fresh(), OrganisationMembership::ROLE_OWNER)]);
    }

    public function members(Request $request, Organisation $organisation): JsonResponse
    {
        $memberships = $organisation->memberships()->with('customer:id,name,email,phone')->orderBy('created_at')->get();
        return response()->json(['success' => true, 'data' => $memberships->map(fn ($membership) => $this->membershipData($membership))]);
    }

    public function updateMemberRole(Request $request, Organisation $organisation, OrganisationMembership $membership): JsonResponse
    {
        /** @var Customer $actor */
        $actor = $request->user();
        if ((int) $membership->organisation_id !== (int) $organisation->id) {
            return response()->json(['success' => false, 'message' => 'Member not found.'], 404);
        }
        $validator = Validator::make($request->all(), ['role' => ['required', 'in:administrator,manager,member']]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'The given data was invalid.', 'errors' => $validator->errors()], 422);
        }
        if ($membership->role === OrganisationMembership::ROLE_OWNER) {
            return response()->json(['success' => false, 'message' => 'The owner role cannot be changed.'], 403);
        }
        if ($membership->status === OrganisationMembership::STATUS_REMOVED) {
            return response()->json(['success' => false, 'message' => 'A removed membership cannot be changed.'], 409);
        }

        $actorRole = $actor->organisationMemberships()->where('organisation_id', $organisation->id)->where('status', OrganisationMembership::STATUS_ACTIVE)->value('role');
        $newRole = $validator->validated()['role'];
        if ($actorRole === OrganisationMembership::ROLE_ADMINISTRATOR
            && ($membership->role === OrganisationMembership::ROLE_ADMINISTRATOR || $newRole === OrganisationMembership::ROLE_ADMINISTRATOR)) {
            return response()->json(['success' => false, 'message' => 'Only the owner may manage administrator roles.'], 403);
        }

        $oldRole = $membership->role;
        DB::transaction(function () use ($request, $actor, $organisation, $membership, $oldRole, $newRole): void {
            $this->assertLockedPermission($actor, $organisation, 'members.roles.manage');
            $locked = OrganisationMembership::query()->lockForUpdate()->findOrFail($membership->id);
            if ((int) $locked->organisation_id !== (int) $organisation->id || $locked->role === OrganisationMembership::ROLE_OWNER || $locked->role !== $oldRole) {
                abort(403);
            }
            $actorRole = $actor->organisationMemberships()->where('organisation_id', $organisation->id)->where('status', OrganisationMembership::STATUS_ACTIVE)->value('role');
            if ($actorRole === OrganisationMembership::ROLE_ADMINISTRATOR
                && ($locked->role === OrganisationMembership::ROLE_ADMINISTRATOR || $newRole === OrganisationMembership::ROLE_ADMINISTRATOR)) {
                abort(403);
            }
            $locked->role = $newRole;
            $locked->save();
            $this->audit($request, $actor, $organisation, 'membership.role_changed', $locked, ['role'], ['role' => $oldRole], ['role' => $newRole]);
        });

        return response()->json(['success' => true, 'data' => $this->membershipData($membership->fresh('customer'))]);
    }

    public function updateMemberFinancialAccess(Request $request, Organisation $organisation, OrganisationMembership $membership): JsonResponse
    {
        /** @var Customer $actor */
        $actor = $request->user();
        $values = $request->validate(['enabled' => ['required', 'boolean']]);
        if ((int) $membership->organisation_id !== (int) $organisation->id) {
            return response()->json(['success' => false, 'message' => 'Member not found.'], 404);
        }

        DB::transaction(function () use ($request, $actor, $organisation, $membership, $values): void {
            $this->assertLockedPermission($actor, $organisation, 'members.roles.manage');
            $locked = OrganisationMembership::query()->lockForUpdate()->findOrFail($membership->id);
            if ((int) $locked->organisation_id !== (int) $organisation->id || $locked->status !== OrganisationMembership::STATUS_ACTIVE) {
                abort(409, 'Financial access can only be changed for an active member of this organisation.');
            }

            $actorRole = $actor->organisationMemberships()->where('organisation_id', $organisation->id)->where('status', OrganisationMembership::STATUS_ACTIVE)->value('role');
            if ($actorRole === OrganisationMembership::ROLE_ADMINISTRATOR
                && in_array($locked->role, [OrganisationMembership::ROLE_OWNER, OrganisationMembership::ROLE_ADMINISTRATOR], true)) {
                abort(403);
            }

            $grant = $locked->permissions()->where('permission', 'financials.view')->first();
            $before = $grant !== null;
            if ($values['enabled'] && ! $before) {
                $locked->permissions()->create([
                    'permission' => 'financials.view',
                    'granted_by_customer_id' => $actor->id,
                    'granted_at' => now(),
                ]);
            } elseif (! $values['enabled'] && $before) {
                $grant->delete();
            }
            if ($before !== (bool) $values['enabled']) {
                $this->audit($request, $actor, $organisation, 'membership.financial_access_changed', $locked, ['financials.view'], ['financials.view' => $before], ['financials.view' => (bool) $values['enabled']]);
            }
        });

        return response()->json(['success' => true, 'data' => [
            'membership_id' => $membership->id,
            'permission' => 'financials.view',
            'enabled' => (bool) $values['enabled'],
        ]]);
    }

    public function updateMemberStatus(Request $request, Organisation $organisation, OrganisationMembership $membership): JsonResponse
    {
        /** @var Customer $actor */
        $actor = $request->user();
        if ((int) $membership->organisation_id !== (int) $organisation->id) {
            return response()->json(['success' => false, 'message' => 'Member not found.'], 404);
        }
        $validator = Validator::make($request->all(), ['status' => ['required', 'in:active,suspended,removed']]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'The given data was invalid.', 'errors' => $validator->errors()], 422);
        }
        if ($membership->role === OrganisationMembership::ROLE_OWNER) {
            return response()->json(['success' => false, 'message' => 'The owner membership cannot be suspended or removed.'], 403);
        }

        $actorRole = $actor->organisationMemberships()->where('organisation_id', $organisation->id)->where('status', OrganisationMembership::STATUS_ACTIVE)->value('role');
        if ($actorRole === OrganisationMembership::ROLE_ADMINISTRATOR && $membership->role === OrganisationMembership::ROLE_ADMINISTRATOR) {
            return response()->json(['success' => false, 'message' => 'Only the owner may manage administrator memberships.'], 403);
        }

        $oldStatus = $membership->status;
        $newStatus = $validator->validated()['status'];
        $allowedTransitions = [
            OrganisationMembership::STATUS_ACTIVE => [OrganisationMembership::STATUS_SUSPENDED, OrganisationMembership::STATUS_REMOVED],
            OrganisationMembership::STATUS_SUSPENDED => [OrganisationMembership::STATUS_ACTIVE, OrganisationMembership::STATUS_REMOVED],
            OrganisationMembership::STATUS_PENDING => [],
            OrganisationMembership::STATUS_REMOVED => [],
        ];
        if ($oldStatus === $newStatus) {
            return response()->json(['success' => true, 'data' => $this->membershipData($membership->load('customer'))]);
        }
        if (! in_array($newStatus, $allowedTransitions[$oldStatus] ?? [], true)) {
            return response()->json(['success' => false, 'message' => 'This membership status transition is not allowed.'], 422);
        }

        DB::transaction(function () use ($request, $actor, $organisation, $membership, $oldStatus, $newStatus): void {
            $this->assertLockedPermission($actor, $organisation, 'members.manage');
            $locked = OrganisationMembership::query()->lockForUpdate()->findOrFail($membership->id);
            if ((int) $locked->organisation_id !== (int) $organisation->id || $locked->role === OrganisationMembership::ROLE_OWNER || $locked->status !== $oldStatus) {
                abort(403);
            }
            $actorRole = $actor->organisationMemberships()->where('organisation_id', $organisation->id)->where('status', OrganisationMembership::STATUS_ACTIVE)->value('role');
            if ($actorRole === OrganisationMembership::ROLE_ADMINISTRATOR && $locked->role === OrganisationMembership::ROLE_ADMINISTRATOR) {
                abort(403);
            }
            $locked->status = $newStatus;
            $locked->save();
            $this->audit($request, $actor, $organisation, 'membership.status_changed', $locked, ['status'], ['status' => $oldStatus], ['status' => $newStatus]);
        });

        return response()->json(['success' => true, 'data' => $this->membershipData($membership->fresh('customer'))]);
    }

    private function organisationData(Organisation $organisation, ?string $role): array
    {
        return [
            'id' => $organisation->id,
            'reference' => $organisation->reference,
            'name' => $organisation->name,
            'description' => $organisation->description,
            'business_email' => $organisation->business_email,
            'business_phone' => $organisation->business_phone,
            'address' => $organisation->address,
            'status' => $organisation->status,
            'role' => $role,
            'created_at' => $organisation->created_at,
            'updated_at' => $organisation->updated_at,
        ];
    }

    private function membershipData(OrganisationMembership $membership): array
    {
        return [
            'id' => $membership->id,
            'customer' => [
                'id' => $membership->customer->id,
                'name' => $membership->customer->name,
                'email' => $membership->customer->email,
                'phone' => $membership->customer->phone,
            ],
            'role' => $membership->role,
            'permissions' => $membership->permissions()->pluck('permission')->values(),
            'status' => $membership->status,
            'joined_at' => $membership->joined_at,
            'approved_at' => $membership->approved_at,
        ];
    }

    private function assertLockedPermission(Customer $customer, Organisation $organisation, string $permission): Organisation
    {
        $lockedOrganisation = Organisation::query()->lockForUpdate()->findOrFail($organisation->id);
        OrganisationMembership::query()
            ->where('organisation_id', $lockedOrganisation->id)
            ->where('customer_id', $customer->id)
            ->where('status', OrganisationMembership::STATUS_ACTIVE)
            ->lockForUpdate()
            ->first();

        if (! $customer->is_active || ! app(\App\Services\OrganisationAuthorization::class)->allows($customer, $lockedOrganisation, $permission)) {
            abort(403);
        }

        return $lockedOrganisation;
    }

    private function audit(Request $request, Customer $actor, Organisation $organisation, string $action, $entity, array $changedFields, ?array $oldValues = null, ?array $newValues = null): void
    {
        CustomerAuditLog::create([
            'customer_id' => $actor->id,
            'organisation_id' => $organisation->id,
            'action' => $action,
            'entity_type' => get_class($entity),
            'entity_id' => (string) $entity->getKey(),
            'changed_fields' => ['fields' => array_values($changedFields), 'old' => $oldValues, 'new' => $newValues],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
