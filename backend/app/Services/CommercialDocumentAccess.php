<?php

namespace App\Services;

use App\Models\CommercialDocument;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;

class CommercialDocumentAccess
{
    public function __construct(private readonly OrganisationAuthorization $organisationAuthorization) {}

    public function allows(Customer $customer, CommercialDocument $document, string $permission = 'documents.view'): bool
    {
        if (! $customer->is_active) return false;
        if ($document->organisation_id) {
            $organisation = $document->organisation;
            return $organisation !== null && $this->organisationAuthorization->allows($customer, $organisation, $permission);
        }

        return $document->customer_id !== null && (int) $document->customer_id === (int) $customer->id;
    }

    public function scopeForCustomer(Builder $query, Customer $customer): Builder
    {
        if (! $customer->is_active) return $query->whereRaw('1 = 0');

        $organisationIds = $customer->organisationMemberships()
            ->where('status', 'active')
            ->with('organisation')
            ->get()
            ->filter(fn ($membership) => $membership->organisation
                && $this->organisationAuthorization->allows($customer, $membership->organisation, 'documents.view'))
            ->pluck('organisation_id');

        return $query->where(function (Builder $scope) use ($customer, $organisationIds): void {
            $scope->where(function (Builder $individual) use ($customer): void {
                $individual->whereNull('organisation_id')->where('customer_id', $customer->id);
            });
            if ($organisationIds->isNotEmpty()) $scope->orWhereIn('organisation_id', $organisationIds);
        });
    }
}
