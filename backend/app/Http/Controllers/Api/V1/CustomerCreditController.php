<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\OrganisationMembership;
use App\Services\CustomerCreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerCreditController extends Controller
{
    public function __construct(private readonly CustomerCreditService $credit) {}

    public function index(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $organisationIds = OrganisationMembership::query()
            ->where('customer_id', $customer->id)
            ->where('status', OrganisationMembership::STATUS_ACTIVE)
            ->whereHas('permissions', fn ($query) => $query->where('permission', 'financials.view'))
            ->pluck('organisation_id')->all();

        return response()->json(['success' => true, 'data' => $this->credit->customerCreditView($customer, $organisationIds)]);
    }
}
