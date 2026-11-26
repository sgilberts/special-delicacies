<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Organisation;
use App\Models\OrganisationMembership;

class OrganisationAuthorization
{
    private const PERMISSIONS = [
        OrganisationMembership::ROLE_OWNER => ['view', 'profile.update', 'status.manage', 'members.view', 'members.manage', 'members.roles.manage', 'documents.view', 'documents.manage', 'documents.accept'],
        OrganisationMembership::ROLE_ADMINISTRATOR => ['view', 'profile.update', 'members.view', 'members.manage', 'members.roles.manage', 'documents.view', 'documents.manage', 'documents.accept'],
        OrganisationMembership::ROLE_MANAGER => ['view', 'members.view', 'documents.view'],
        OrganisationMembership::ROLE_MEMBER => ['view', 'members.view', 'documents.view'],
    ];

    public function membership(Customer $customer, Organisation $organisation): ?OrganisationMembership
    {
        if (! $customer->is_active) {
            return null;
        }

        return OrganisationMembership::query()
            ->where('organisation_id', $organisation->id)
            ->where('customer_id', $customer->id)
            ->where('status', OrganisationMembership::STATUS_ACTIVE)
            ->first();
    }

    public function allows(Customer $customer, Organisation $organisation, string $permission): bool
    {
        $membership = $this->membership($customer, $organisation);
        if (! $membership) {
            return false;
        }

        // Suspended organisations are visible to members; only their owner can manage status.
        if ($organisation->status === Organisation::STATUS_SUSPENDED) {
            $isOwner = $membership->role === OrganisationMembership::ROLE_OWNER
                && (int) $organisation->owner_customer_id === (int) $customer->id;
            return $isOwner && in_array($permission, ['view', 'status.manage'], true);
        }

        if ($permission === 'status.manage') {
            return $membership->role === OrganisationMembership::ROLE_OWNER
                && (int) $organisation->owner_customer_id === (int) $customer->id;
        }

        if ($permission === 'financials.view') {
            return $membership->permissions()->where('permission', 'financials.view')->exists();
        }

        return in_array($permission, self::PERMISSIONS[$membership->role] ?? [], true);
    }
}
