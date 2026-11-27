<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerAuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Services\ApiTokenSession;
use Symfony\Component\HttpFoundation\Response;

class CustomerController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'min:5', 'max:30', 'regex:/^[0-9+().\-\s]+$/', 'unique:customers,phone'],
            'email' => ['nullable', 'email', 'max:255', 'unique:customers,email'],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'The given data was invalid.', 'errors' => $validator->errors()], 422);
        }

        try {
            [$customer, $token] = DB::transaction(function () use ($validator, $request) {
                $customer = Customer::create([
                    'name' => trim($validator->validated()['name']),
                    'phone' => trim($validator->validated()['phone']),
                    'email' => isset($validator->validated()['email']) ? mb_strtolower(trim($validator->validated()['email'])) : null,
                    'password' => $validator->validated()['password'],
                    'is_active' => true,
                ]);

                $this->audit($request, $customer, 'registered', ['name', 'phone', 'email']);
                return [$customer, app(ApiTokenSession::class)->issue($customer, 'customer-session', false)['token']];
            });
        } catch (QueryException) {
            return response()->json(['success' => false, 'message' => 'An account with those contact details already exists.'], 409);
        }

        return response()->json(['success' => true, 'message' => 'Customer account created.', 'data' => [
            'token' => $token,
            'customer' => $this->customerData($customer),
        ]], Response::HTTP_CREATED);
    }

    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => ['required', 'string', 'max:30'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'The given data was invalid.', 'errors' => $validator->errors()], 422);
        }

        $customer = Customer::where('phone', trim($validator->validated()['phone']))->first();
        if (! $customer || ! Hash::check($validator->validated()['password'], $customer->password)) {
            return response()->json(['success' => false, 'message' => 'Invalid phone number or password.'], 422);
        }
        if (! $customer->is_active) {
            return response()->json(['success' => false, 'message' => 'This customer account is deactivated.'], 403);
        }

        $remember = $request->boolean('remember');
        $session = app(ApiTokenSession::class)->issue($customer, 'customer-session', $remember);
        $response = response()->json(['success' => true, 'message' => 'Authenticated successfully.', 'data' => [
            'token' => $remember ? null : $session['token'],
            'customer' => $this->customerData($customer),
        ]]);
        return $remember
            ? app(ApiTokenSession::class)->attachRememberCookie($response, ApiTokenSession::CUSTOMER_COOKIE, $session['token'], $session['minutes'], $request)
            : app(ApiTokenSession::class)->clearRememberCookie($response, ApiTokenSession::CUSTOMER_COOKIE, $request);
    }

    public function profile(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->customerData($request->user())]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'phone' => ['sometimes', 'required', 'string', 'min:5', 'max:30', 'regex:/^[0-9+().\-\s]+$/', 'unique:customers,phone,'.$customer->id],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', 'unique:customers,email,'.$customer->id],
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'The given data was invalid.', 'errors' => $validator->errors()], 422);
        }

        $values = $validator->validated();
        if (array_key_exists('email', $values) && mb_strtolower(trim((string) $values['email'])) !== mb_strtolower((string) $customer->email)) {
            $customer->email_verified_at = null;
        }
        if (array_key_exists('phone', $values) && trim((string) $values['phone']) !== trim((string) $customer->phone)) {
            $customer->phone_verified_at = null;
        }
        foreach (['name', 'phone', 'email'] as $field) {
            if (array_key_exists($field, $values)) {
                $customer->{$field} = $field === 'email' && $values[$field] !== null
                    ? mb_strtolower(trim($values[$field]))
                    : (is_string($values[$field]) ? trim($values[$field]) : $values[$field]);
            }
        }
        try {
            DB::transaction(function () use ($request, $customer, $values): void {
                $customer->save();
                $this->audit($request, $customer, 'profile_updated', array_keys($values));
            });
        } catch (QueryException) {
            return response()->json(['success' => false, 'message' => 'An account with those contact details already exists.'], 409);
        }

        return response()->json(['success' => true, 'message' => 'Profile updated.', 'data' => $this->customerData($customer)]);
    }

    public function orders(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $orders = $customer->orders()->with(['items', 'statusHistories'])->latest()->paginate(min(max($request->integer('per_page', 15), 1), 50));

        return response()->json(['success' => true, 'data' => $orders->getCollection()->map(fn ($order) => [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'order_type' => $order->order_type,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'subtotal' => $order->subtotal,
            'delivery_fee' => $order->delivery_fee,
            'total' => $order->total,
            'delivery_address' => $order->delivery_address,
            'created_at' => $order->created_at,
            'items' => $order->items->map(fn ($item) => [
                'item_name' => $item->item_name,
                'unit_price' => $item->unit_price,
                'quantity' => $item->quantity,
                'line_total' => $item->line_total,
                'special_instructions' => $item->special_instructions,
            ]),
            'status_history' => $order->statusHistories->map(fn ($history) => [
                'status' => $history->new_status,
                'created_at' => $history->created_at,
            ]),
        ]), 'meta' => [
            'current_page' => $orders->currentPage(),
            'last_page' => $orders->lastPage(),
            'per_page' => $orders->perPage(),
            'total' => $orders->total(),
        ]]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        // Stateful Sanctum requests are authenticated by the web session; end it
        // as well so logout cannot be bypassed by a still-valid session cookie.
        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return app(ApiTokenSession::class)->clearRememberCookie(response()->json(['success' => true, 'message' => 'Logged out successfully.']), ApiTokenSession::CUSTOMER_COOKIE, $request);
    }

    private function customerData(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'email_verified_at' => $customer->email_verified_at,
            'phone_verified_at' => $customer->phone_verified_at,
            'is_active' => $customer->is_active,
            'created_at' => $customer->created_at,
            'updated_at' => $customer->updated_at,
        ];
    }

    private function audit(Request $request, Customer $customer, string $action, array $changedFields): void
    {
        CustomerAuditLog::create([
            'customer_id' => $customer->id,
            'action' => $action,
            'entity_type' => Customer::class,
            'entity_id' => (string) $customer->id,
            'changed_fields' => array_values($changedFields),
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
