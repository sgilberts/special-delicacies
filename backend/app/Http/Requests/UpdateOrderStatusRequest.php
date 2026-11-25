<?php

namespace App\Http\Requests;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;

class UpdateOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => [
                'required',
                'string',
                'in:'.implode(',', [
                    Order::STATUS_CONFIRMED,
                    Order::STATUS_PREPARING,
                    Order::STATUS_READY,
                    Order::STATUS_OUT_FOR_DELIVERY,
                    Order::STATUS_DELIVERED,
                    Order::STATUS_COMPLETED,
                    Order::STATUS_CANCELLED,
                ]),
            ],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
