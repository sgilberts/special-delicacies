<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AccountSecurityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PasswordRecoveryController extends Controller
{
    public function __construct(private readonly AccountSecurityService $security) {}

    public function requestCustomerEmail(Request $request): JsonResponse
    {
        $values = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $this->security->requestEmailPasswordReset('customer', $values['email'], $request);
        return $this->genericResponse();
    }

    public function requestStaffEmail(Request $request): JsonResponse
    {
        $values = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $this->security->requestEmailPasswordReset('staff', $values['email'], $request);
        return $this->genericResponse();
    }

    public function requestCustomerPhone(Request $request): JsonResponse
    {
        $values = $request->validate(['phone' => ['required', 'string', 'max:30']]);
        $this->security->requestPhonePasswordReset('customer', $values['phone'], $request);
        return $this->genericResponse();
    }

    public function requestStaffPhone(Request $request): JsonResponse
    {
        $values = $request->validate(['phone' => ['required', 'string', 'max:30']]);
        $this->security->requestPhonePasswordReset('staff', $values['phone'], $request);
        return $this->genericResponse();
    }

    public function resetCustomerEmail(Request $request): JsonResponse
    {
        $values = $request->validate([
            'challenge_id' => ['required', 'string', 'size:48'], 'token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ]);
        $this->security->resetWithEmailToken('customer', $values['challenge_id'], $values['token'], $values['password'], $request);
        return response()->json(['success' => true, 'message' => 'Password reset successfully. Please sign in again.']);
    }

    public function resetStaffEmail(Request $request): JsonResponse
    {
        $values = $request->validate([
            'challenge_id' => ['required', 'string', 'size:48'], 'token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ]);
        $this->security->resetWithEmailToken('staff', $values['challenge_id'], $values['token'], $values['password'], $request);
        return response()->json(['success' => true, 'message' => 'Password reset successfully. Please sign in again.']);
    }

    public function resetCustomerPhone(Request $request): JsonResponse
    {
        $values = $request->validate([
            'challenge_id' => ['required', 'string', 'size:48'], 'code' => ['required', 'digits:6'],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ]);
        $this->security->resetCustomerWithPhoneOtp($values['challenge_id'], $values['code'], $values['password'], $request);
        return response()->json(['success' => true, 'message' => 'Password reset successfully. Please sign in again.']);
    }

    public function resetStaffPhone(Request $request): JsonResponse
    {
        $values = $request->validate([
            'challenge_id' => ['required', 'string', 'size:48'], 'code' => ['required', 'digits:6'],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ]);
        $this->security->resetStaffWithPhoneOtp($values['challenge_id'], $values['code'], $values['password'], $request);
        return response()->json(['success' => true, 'message' => 'Password reset successfully. Please sign in again.']);
    }

    private function genericResponse(): JsonResponse
    {
        return response()->json(['success' => true, 'message' => 'If the account can use this recovery method, instructions will be sent.'], 202);
    }
}
