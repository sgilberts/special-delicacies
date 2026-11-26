<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerAuditLog;
use App\Models\Organisation;
use App\Models\OrganisationInvitation;
use App\Models\OrganisationMembership;
use App\Services\OrganisationAuthorization;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class OrganisationInvitationController extends Controller
{
    private const EXPIRY_DAYS = 7;

    public function organisationIndex(Request $request, Organisation $organisation): JsonResponse
    {
        /** @var Customer $actor */
        $actor = $request->user();
        $this->expireForOrganisation($request, $actor, $organisation);
        $invitations = $organisation->invitations()->with('customer:id,name')->orderByDesc('created_at')->get();

        return response()->json(['success' => true, 'data' => $invitations->map(fn ($invitation) => [
            'id' => $invitation->id,
            'customer' => ['id' => $invitation->customer->id, 'name' => $invitation->customer->name],
            'status' => $invitation->status,
            'expires_at' => $invitation->expires_at,
            'accepted_at' => $invitation->accepted_at,
            'cancelled_at' => $invitation->cancelled_at,
        ])]);
    }

    public function invite(Request $request, Organisation $organisation): JsonResponse
    {
        /** @var Customer $actor */
        $actor = $request->user();
        $validator = Validator::make($request->all(), ['customer_id' => ['required', 'integer', 'exists:customers,id']]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'The given data was invalid.', 'errors' => $validator->errors()], 422);
        }
        $customerId = (int) $validator->validated()['customer_id'];
        if ($customerId === (int) $actor->id) {
            return response()->json(['success' => false, 'message' => 'You already belong to this organisation.'], 409);
        }

        try {
            $invitation = DB::transaction(function () use ($request, $actor, $organisation, $customerId): OrganisationInvitation {
                $lockedOrganisation = Organisation::query()->lockForUpdate()->findOrFail($organisation->id);
                if (! app(OrganisationAuthorization::class)->allows($actor, $lockedOrganisation, 'members.manage')) {
                    abort(403);
                }
                if (OrganisationMembership::query()->where('organisation_id', $lockedOrganisation->id)->where('customer_id', $customerId)->exists()) {
                    throw new DomainException('An organisation membership already exists for this customer.');
                }

                $invitation = OrganisationInvitation::query()
                    ->where('organisation_id', $lockedOrganisation->id)
                    ->where('customer_id', $customerId)
                    ->lockForUpdate()
                    ->first();
                if ($invitation && $invitation->status === OrganisationInvitation::STATUS_PENDING && $invitation->expires_at->isFuture()) {
                    throw new DomainException('A pending invitation already exists.');
                }

                $oldStatus = $invitation?->status;
                if ($invitation && $oldStatus === OrganisationInvitation::STATUS_PENDING && $invitation->expires_at->isPast()) {
                    $invitation->status = OrganisationInvitation::STATUS_EXPIRED;
                    $invitation->save();
                    $this->audit($request, $actor, $lockedOrganisation, 'invitation.expired', $invitation, ['status']);
                }
                $invitation ??= new OrganisationInvitation();
                $invitation->fill([
                    'organisation_id' => $lockedOrganisation->id,
                    'customer_id' => $customerId,
                    'invited_by' => $actor->id,
                    'status' => OrganisationInvitation::STATUS_PENDING,
                    'expires_at' => now()->addDays(self::EXPIRY_DAYS),
                    'accepted_at' => null,
                    'cancelled_at' => null,
                ])->save();

                $this->audit($request, $actor, $lockedOrganisation, 'invitation.created', $invitation, ['customer_id', 'expires_at'], $oldStatus ? ['status' => $oldStatus] : null, ['status' => OrganisationInvitation::STATUS_PENDING]);
                return $invitation;
            });
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 409);
        } catch (QueryException) {
            return response()->json(['success' => false, 'message' => 'A pending invitation already exists.'], 409);
        }

        return response()->json(['success' => true, 'message' => 'Invitation added to the customer account inbox.', 'data' => [
            'id' => $invitation->id,
            'status' => $invitation->status,
            'expires_at' => $invitation->expires_at,
        ]], 201);
    }

    public function cancel(Request $request, Organisation $organisation, OrganisationInvitation $invitation): JsonResponse
    {
        /** @var Customer $actor */
        $actor = $request->user();
        if ((int) $invitation->organisation_id !== (int) $organisation->id) {
            return response()->json(['success' => false, 'message' => 'Invitation not found.'], 404);
        }

        $result = DB::transaction(function () use ($request, $actor, $organisation, $invitation): string {
            $lockedOrganisation = Organisation::query()->lockForUpdate()->findOrFail($organisation->id);
            if (! app(OrganisationAuthorization::class)->allows($actor, $lockedOrganisation, 'members.manage')) {
                abort(403);
            }
            $locked = OrganisationInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
            if ((int) $locked->organisation_id !== (int) $lockedOrganisation->id || $locked->status !== OrganisationInvitation::STATUS_PENDING) {
                return 'not_pending';
            }
            if ($locked->expires_at->isPast()) {
                $locked->status = OrganisationInvitation::STATUS_EXPIRED;
                $locked->save();
                $this->audit($request, $actor, $lockedOrganisation, 'invitation.expired', $locked, ['status']);
                return 'expired';
            }

            $locked->status = OrganisationInvitation::STATUS_CANCELLED;
            $locked->cancelled_at = now();
            $locked->save();
            $this->audit($request, $actor, $lockedOrganisation, 'invitation.cancelled', $locked, ['status', 'cancelled_at']);
            return 'cancelled';
        });

        if ($result === 'cancelled') {
            return response()->json(['success' => true, 'message' => 'Invitation cancelled.']);
        }
        return response()->json(['success' => false, 'message' => $result === 'expired' ? 'Invitation expired.' : 'Invitation is no longer pending.'], 409);
    }

    public function inbox(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $invitations = $customer->organisationInvitations()
            ->where('status', OrganisationInvitation::STATUS_PENDING)
            ->where('expires_at', '>', now())
            ->whereHas('organisation', fn ($query) => $query->where('status', Organisation::STATUS_ACTIVE))
            ->with(['organisation:id,reference,name', 'inviter:id,name'])
            ->orderBy('expires_at')
            ->get();

        return response()->json(['success' => true, 'data' => $invitations->map(fn ($invitation) => [
            'id' => $invitation->id,
            'organisation' => ['id' => $invitation->organisation->id, 'reference' => $invitation->organisation->reference, 'name' => $invitation->organisation->name],
            'invited_by' => ['name' => $invitation->inviter->name],
            'expires_at' => $invitation->expires_at,
        ])]);
    }

    public function accept(Request $request, int $invitationId): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        [$result, $organisationId] = DB::transaction(function () use ($request, $customer, $invitationId): array {
            $candidate = OrganisationInvitation::query()
                ->whereKey($invitationId)
                ->where('customer_id', $customer->id)
                ->first();
            if (! $candidate) {
                return ['not_found', null];
            }
            // Match cancellation and invitation creation lock order: organisation, then invitation.
            $organisation = Organisation::query()->lockForUpdate()->find($candidate->organisation_id);
            $invitation = OrganisationInvitation::query()->whereKey($candidate->id)
                ->where('customer_id', $customer->id)->lockForUpdate()->first();
            if (! $invitation) {
                return ['not_found', null];
            }
            if ($invitation->status !== OrganisationInvitation::STATUS_PENDING) {
                return ['not_pending', $invitation->organisation_id];
            }

            if (! $organisation || $organisation->status !== Organisation::STATUS_ACTIVE) {
                return ['unavailable', null];
            }
            if ($invitation->expires_at->isPast()) {
                $invitation->status = OrganisationInvitation::STATUS_EXPIRED;
                $invitation->save();
                $this->audit($request, $customer, $organisation, 'invitation.expired', $invitation, ['status']);
                return ['expired', $organisation->id];
            }
            if (! $customer->is_active) {
                return ['inactive', null];
            }
            if (OrganisationMembership::query()->where('organisation_id', $organisation->id)->where('customer_id', $customer->id)->lockForUpdate()->exists()) {
                return ['membership_exists', $organisation->id];
            }

            OrganisationMembership::create([
                'organisation_id' => $organisation->id,
                'customer_id' => $customer->id,
                'role' => OrganisationMembership::ROLE_MEMBER,
                'status' => OrganisationMembership::STATUS_ACTIVE,
                'invited_by' => $invitation->invited_by,
                'joined_at' => now(),
                'approved_at' => now(),
            ]);
            $invitation->status = OrganisationInvitation::STATUS_ACCEPTED;
            $invitation->accepted_at = now();
            $invitation->save();
            $this->audit($request, $customer, $organisation, 'invitation.accepted', $invitation, ['status', 'accepted_at']);
            $this->audit($request, $customer, $organisation, 'membership.activated', $invitation, ['customer_id', 'status']);
            return ['accepted', $organisation->id];
        });

        if ($result === 'accepted') {
            return response()->json(['success' => true, 'message' => 'Invitation accepted.', 'data' => ['organisation_id' => $organisationId]]);
        }
        [$status, $message] = match ($result) {
            'not_found' => [404, 'Invitation not found.'],
            'expired' => [410, 'Invitation expired.'],
            'inactive' => [403, 'Customer account is deactivated.'],
            'unavailable' => [409, 'Invitation is no longer available.'],
            'membership_exists' => [409, 'A membership already exists for this organisation.'],
            default => [409, 'Invitation is no longer pending.'],
        };
        return response()->json(['success' => false, 'message' => $message], $status);
    }

    private function expireForOrganisation(Request $request, Customer $actor, Organisation $organisation): void
    {
        DB::transaction(function () use ($request, $actor, $organisation): void {
            Organisation::query()->lockForUpdate()->findOrFail($organisation->id);
            $expired = OrganisationInvitation::query()->where('organisation_id', $organisation->id)
                ->where('status', OrganisationInvitation::STATUS_PENDING)->where('expires_at', '<=', now())->lockForUpdate()->get();
            foreach ($expired as $invitation) {
                $invitation->status = OrganisationInvitation::STATUS_EXPIRED;
                $invitation->save();
                $this->audit($request, $actor, $organisation, 'invitation.expired', $invitation, ['status']);
            }
        });
    }

    private function audit(Request $request, Customer $actor, Organisation $organisation, string $action, OrganisationInvitation $invitation, array $fields, ?array $old = null, ?array $new = null): void
    {
        CustomerAuditLog::create([
            'customer_id' => $actor->id,
            'organisation_id' => $organisation->id,
            'action' => $action,
            'entity_type' => OrganisationInvitation::class,
            'entity_id' => (string) $invitation->id,
            'changed_fields' => ['fields' => $fields, 'old' => $old, 'new' => $new],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
