<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\OtpDeliveryUnavailable;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use App\Services\AccountSecurityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AccountVerificationController extends Controller
{
    public function __construct(private readonly AccountSecurityService $security) {}

    public function requestCustomer(Request $request, string $channel): JsonResponse
    {
        abort_unless(in_array($channel, ['phone', 'email'], true), 404);
        try {
            $id = $this->security->requestVerification($request->user(), $channel, $request);
            return response()->json(['success' => true, 'message' => 'Verification code sent.', 'data' => ['challenge_id' => $id]], 202);
        } catch (OtpDeliveryUnavailable) {
            return response()->json(['success' => false, 'message' => 'Verification delivery is currently unavailable. Please try again later.'], 503);
        }
    }

    public function verifyCustomer(Request $request, string $channel): JsonResponse
    {
        abort_unless(in_array($channel, ['phone', 'email'], true), 404);
        $values = $request->validate(['challenge_id' => ['required', 'string', 'size:48'], 'code' => ['required', 'digits:6']]);
        $this->security->verifyContact($request->user(), $channel, $values['challenge_id'], $values['code'], $request);
        return response()->json(['success' => true, 'message' => ucfirst($channel).' verified.']);
    }

    public function requestStaffEmail(Request $request): JsonResponse
    {
        try {
            $id = $this->security->requestVerification($request->user(), 'email', $request);
            return response()->json(['success' => true, 'message' => 'Verification code sent.', 'data' => ['challenge_id' => $id]], 202);
        } catch (OtpDeliveryUnavailable) {
            return response()->json(['success' => false, 'message' => 'Verification delivery is currently unavailable. Please try again later.'], 503);
        }
    }

    public function requestStaffPhone(Request $request): JsonResponse
    {
        try {
            $id = $this->security->requestVerification($request->user(), 'phone', $request);
            return response()->json(['success' => true, 'message' => 'Verification code sent.', 'data' => ['challenge_id' => $id]], 202);
        } catch (OtpDeliveryUnavailable) {
            return response()->json(['success' => false, 'message' => 'Verification delivery is currently unavailable. Please try again later.'], 503);
        }
    }

    public function verifyStaffPhone(Request $request): JsonResponse
    {
        $values = $request->validate(['challenge_id' => ['required', 'string', 'size:48'], 'code' => ['required', 'digits:6']]);
        $this->security->verifyContact($request->user(), 'phone', $values['challenge_id'], $values['code'], $request);
        return response()->json(['success' => true, 'message' => 'Phone verified.']);
    }

    public function verifyStaffEmail(Request $request): JsonResponse
    {
        $values = $request->validate(['challenge_id' => ['required', 'string', 'size:48'], 'code' => ['required', 'digits:6']]);
        $this->security->verifyContact($request->user(), 'email', $values['challenge_id'], $values['code'], $request);
        return response()->json(['success' => true, 'message' => 'Email verified.']);
    }
}
