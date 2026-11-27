<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Only the customer profile fields an administrator may write are accepted.
 * Identity, financial, verification and audit fields are never editable here;
 * normalized uniqueness and phone formatting are enforced by
 * CustomerManagementService, which owns the customer identity rules.
 */
class StoreCustomerRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['required', 'string', 'min:5', 'max:30', 'regex:/^[0-9+().\-\s]+$/'],
        ];
    }
}
