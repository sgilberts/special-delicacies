<?php

namespace App\Http\Requests;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;

class CreateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare data for validation.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('idempotency_key') && $this->header('X-Idempotency-Key')) {
            $this->merge(['idempotency_key' => $this->header('X-Idempotency-Key')]);
        }

        if ($this->has('qr_token') && ! empty($this->input('qr_token'))) {
            $table = app(\App\Services\QrCodeService::class)->resolveTableFromToken($this->input('qr_token'));
            if ($table && $table->is_active) {
                $this->merge(['restaurant_table_id' => $table->id]);
            } else {
                $this->merge(['invalid_qr_token' => true]);
            }

            if ($this->input('order_type') === Order::TYPE_DINE_IN) {
                if (! $this->has('customer_name') || trim((string) $this->input('customer_name')) === '') {
                    $defaultName = $table ? "Table {$table->table_number} Guest" : "Dine-in Guest";
                    $this->merge(['customer_name' => $defaultName]);
                }
                if (! $this->has('customer_phone') || trim((string) $this->input('customer_phone')) === '') {
                    $this->merge(['customer_phone' => 'N/A']);
                }
            }
        }
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['nullable', 'string', 'max:64'],
            'qr_token' => ['nullable', 'string', 'size:64'],
            'invalid_qr_token' => ['prohibited'],
            'order_type' => ['required', 'string', 'in:'.Order::TYPE_DINE_IN.','.Order::TYPE_DELIVERY.','.Order::TYPE_PICKUP],
            'customer_name' => ['required', 'string', 'max:100'],
            'customer_phone' => ['required', 'string', 'max:30'],
            'customer_email' => ['required_if:payment_method,gateway', 'nullable', 'email', 'max:255'],
            'payment_method' => ['sometimes', 'in:cash,gateway'],
            'restaurant_table_id' => ['required_if:order_type,'.Order::TYPE_DINE_IN, 'nullable', 'integer', 'exists:restaurant_tables,id'],
            'delivery_zone_id' => ['required_if:order_type,'.Order::TYPE_DELIVERY, 'nullable', 'integer', 'exists:delivery_zones,id'],
            'include_delivery_fee' => ['sometimes', 'boolean'],
            'delivery_fee_payment_method' => ['sometimes', 'required_if:order_type,'.Order::TYPE_DELIVERY, 'in:online,cash_on_delivery'],
            'delivery_address' => ['required_if:order_type,'.Order::TYPE_DELIVERY, 'nullable', 'string', 'max:500'],
            'customer_notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.menu_item_id' => ['required', 'integer', 'exists:menu_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.special_instructions' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->input('order_type') === Order::TYPE_DELIVERY && $this->input('payment_method') !== Order::PAYMENT_METHOD_GATEWAY) {
                $validator->errors()->add('payment_method', 'Home delivery requires online payment for the food order.');
            }
        });
    }

    /**
     * Get custom validation messages.
     */
    public function messages(): array
    {
        return [
            'invalid_qr_token.prohibited' => 'The provided QR code token is invalid, revoked, or the table is inactive.',
            'restaurant_table_id.required_if' => 'Dine-in orders require a valid restaurant table or QR token.',
        ];
    }
}
