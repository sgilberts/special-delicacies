<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminPaymentGatewayController extends Controller
{
    private const PROVIDERS = [
        'paystack' => ['display_name' => 'Paystack', 'fields' => ['public_key', 'secret_key', 'webhook_secret']],
        'hubtel' => ['display_name' => 'Hubtel', 'fields' => ['api_id', 'api_key', 'collection_account_number']],
        'korapay' => ['display_name' => 'Kora', 'fields' => ['secret_key']],
    ];

    public function __construct(private AuditService $auditService) {}

    public function index(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => [
            'supported_providers' => collect(self::PROVIDERS)->map(fn ($provider, $slug) => [
                'provider' => $slug,
                'display_name' => $provider['display_name'],
                'fields' => $provider['fields'],
            ])->values(),
            'gateways' => PaymentGateway::orderByDesc('is_default')->orderBy('display_name')->get()
                ->map(fn (PaymentGateway $gateway) => $this->safeGateway($gateway)),
        ]]);
    }

    /** Legacy Paystack settings endpoint retained for compatibility. */
    public function show(): JsonResponse
    {
        $gateway = PaymentGateway::where('provider', 'paystack')->orderByDesc('is_default')->first();
        return response()->json(['success' => true, 'data' => $gateway
            ? $this->safeGateway($gateway)
            : ['provider' => 'paystack', 'display_name' => 'Paystack', 'public_key' => null,
                'secret_key_configured' => false, 'webhook_secret_configured' => false,
                'configuration' => [], 'is_enabled' => false, 'is_default' => false],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateGateway($request);
        $gateway = DB::transaction(function () use ($validated, $request) {
            $gateway = new PaymentGateway();
            $this->applyGatewayValues($gateway, $validated);
            $gateway->save();
            if ($gateway->is_default) PaymentGateway::where('id', '!=', $gateway->id)->update(['is_default' => false]);
            $this->auditService->log($request->user(), 'payment_gateway.create', "Added {$gateway->display_name} online payment gateway.", $gateway,
                null, $this->auditValues($gateway), $request);
            return $gateway;
        });

        return response()->json(['success' => true, 'message' => 'Payment gateway added.', 'data' => $this->safeGateway($gateway)], 201);
    }

    /** Legacy Paystack settings endpoint retained for compatibility. */
    public function update(Request $request): JsonResponse
    {
        $gateway = PaymentGateway::where('provider', 'paystack')->orderByDesc('is_default')->first();
        if (! $gateway) {
            $request->merge(['provider' => 'paystack', 'display_name' => 'Paystack']);
            $this->store($request);
            return $this->show();
        }

        $request->merge(['provider' => $gateway->provider, 'display_name' => $request->input('display_name', $gateway->display_name)]);
        $validated = $this->validateGateway($request, $gateway);
        $this->persistUpdate($gateway, $validated, $request);
        return $this->show();
    }

    public function updateRecord(int $id, Request $request): JsonResponse
    {
        $gateway = PaymentGateway::find($id);
        if (! $gateway) return response()->json(['success' => false, 'message' => 'Payment gateway not found.'], 404);

        $request->merge(['provider' => $gateway->provider]);
        $validated = $this->validateGateway($request, $gateway);
        $this->persistUpdate($gateway, $validated, $request);
        return response()->json(['success' => true, 'message' => 'Payment gateway updated.', 'data' => $this->safeGateway($gateway->fresh())]);
    }

    public function setDefault(int $id, Request $request): JsonResponse
    {
        $gateway = PaymentGateway::find($id);
        if (! $gateway) return response()->json(['success' => false, 'message' => 'Payment gateway not found.'], 404);
        if (! $gateway->is_enabled || ! $this->hasRequiredCredentials($gateway)) {
            return response()->json(['success' => false, 'message' => 'Enable this gateway and complete its required credentials before making it default.'], 422);
        }

        DB::transaction(function () use ($gateway, $request) {
            $before = ['is_default' => $gateway->is_default];
            PaymentGateway::where('id', '!=', $gateway->id)->update(['is_default' => false]);
            $gateway->forceFill(['is_default' => true])->save();
            $this->auditService->log($request->user(), 'payment_gateway.set_default', "Set {$gateway->display_name} as the default online gateway.", $gateway,
                $before, ['is_default' => true], $request);
        });

        return response()->json(['success' => true, 'message' => 'Default online payment gateway updated.', 'data' => $this->safeGateway($gateway->fresh())]);
    }

    public function destroy(int $id, Request $request): JsonResponse
    {
        $gateway = PaymentGateway::find($id);
        if (! $gateway) return response()->json(['success' => false, 'message' => 'Payment gateway not found.'], 404);
        if (Order::where('payment_gateway_id', $gateway->id)->where('payment_method', Order::PAYMENT_METHOD_GATEWAY)
            ->where('payment_status', Order::PAYMENT_STATUS_PENDING)->exists()) {
            return response()->json(['success' => false, 'message' => 'This gateway is attached to a pending payment. Verify or cancel that order before deleting the gateway.'], 422);
        }

        DB::transaction(function () use ($gateway, $request) {
            $before = $this->auditValues($gateway);
            $gateway->delete();
            $this->auditService->log($request->user(), 'payment_gateway.delete', "Deleted {$gateway->display_name} payment gateway.", $gateway,
                $before, null, $request);
        });

        return response()->json(['success' => true, 'message' => 'Payment gateway deleted.']);
    }

    private function validateGateway(Request $request, ?PaymentGateway $gateway = null): array
    {
        $rules = [
            'provider' => ['required', 'string', 'in:'.implode(',', array_keys(self::PROVIDERS))],
            'display_name' => ['required', 'string', 'max:100'],
            'public_key' => ['nullable', 'string', 'max:255'],
            'secret_key' => ['nullable', 'string', 'max:2000'],
            'webhook_secret' => ['nullable', 'string', 'max:2000'],
            'is_enabled' => ['required', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'configuration' => ['nullable', 'array:collection_account_number'],
            'configuration.collection_account_number' => ['nullable', 'string', 'max:100'],
        ];
        $validated = $request->validate($rules);
        $provider = $validated['provider'];
        $candidate = clone ($gateway ?? new PaymentGateway());
        $candidate->provider = $provider;
        $candidate->public_key = $validated['public_key'] ?? $candidate->public_key;
        if (filled($validated['secret_key'] ?? null)) $candidate->secret_key = $validated['secret_key'];
        if (filled($validated['webhook_secret'] ?? null)) $candidate->webhook_secret = $validated['webhook_secret'];
        $candidate->configuration = array_merge($candidate->configuration ?? [], $validated['configuration'] ?? []);
        $candidate->is_enabled = (bool) $validated['is_enabled'];
        $candidate->is_default = (bool) ($validated['is_default'] ?? $candidate->is_default);

        if ($candidate->is_default && ! $candidate->is_enabled) {
            throw ValidationException::withMessages(['is_default' => 'A disabled gateway cannot be the default.']);
        }
        if ($candidate->is_enabled && ! $this->hasRequiredCredentials($candidate)) {
            $requirements = match ($provider) {
                'paystack' => 'Paystack public and secret keys',
                'hubtel' => 'Hubtel API ID, API key, and collection account number',
                'korapay' => 'Kora secret key',
            };
            throw ValidationException::withMessages(['is_enabled' => "Add {$requirements} before enabling this gateway."]);
        }

        $validated['_candidate'] = $candidate;
        return $validated;
    }

    private function persistUpdate(PaymentGateway $gateway, array $validated, Request $request): void
    {
        DB::transaction(function () use ($gateway, $validated, $request) {
            $before = $this->auditValues($gateway);
            $this->applyGatewayValues($gateway, $validated);
            if ($gateway->is_default) PaymentGateway::where('id', '!=', $gateway->id)->update(['is_default' => false]);
            $gateway->save();
            $this->auditService->log($request->user(), 'payment_gateway.update', "Updated {$gateway->display_name} gateway configuration.", $gateway,
                $before, $this->auditValues($gateway), $request);
        });
    }

    private function applyGatewayValues(PaymentGateway $gateway, array $validated): void
    {
        $gateway->provider = $validated['provider'];
        $gateway->display_name = $validated['display_name'];
        if (array_key_exists('public_key', $validated)) $gateway->public_key = $validated['public_key'];
        if (filled($validated['secret_key'] ?? null)) $gateway->secret_key = $validated['secret_key'];
        if (filled($validated['webhook_secret'] ?? null)) $gateway->webhook_secret = $validated['webhook_secret'];
        $gateway->configuration = array_merge($gateway->configuration ?? [], $validated['configuration'] ?? []);
        $gateway->is_enabled = (bool) $validated['is_enabled'];
        $gateway->is_default = $gateway->is_enabled && (bool) ($validated['is_default'] ?? false);
    }

    private function hasRequiredCredentials(PaymentGateway $gateway): bool
    {
        return match ($gateway->provider) {
            'paystack' => filled($gateway->public_key) && filled($gateway->secret_key),
            'hubtel' => filled($gateway->public_key) && filled($gateway->secret_key)
                && filled(data_get($gateway->configuration, 'collection_account_number')),
            'korapay' => filled($gateway->secret_key),
            default => false,
        };
    }

    private function safeGateway(PaymentGateway $gateway): array
    {
        return [
            'id' => $gateway->id,
            'provider' => $gateway->provider,
            'display_name' => $gateway->display_name,
            'public_key' => $gateway->public_key,
            'secret_key_configured' => filled($gateway->secret_key),
            'webhook_secret_configured' => filled($gateway->webhook_secret),
            'configuration' => $gateway->configuration ?? [],
            'is_enabled' => (bool) $gateway->is_enabled,
            'is_default' => (bool) $gateway->is_default,
            'created_at' => $gateway->created_at,
            'updated_at' => $gateway->updated_at,
        ];
    }

    private function auditValues(PaymentGateway $gateway): array
    {
        return ['provider' => $gateway->provider, 'display_name' => $gateway->display_name,
            'is_enabled' => (bool) $gateway->is_enabled, 'is_default' => (bool) $gateway->is_default,
            'secret_key_configured' => filled($gateway->secret_key),
            'webhook_secret_configured' => filled($gateway->webhook_secret)];
    }
}
