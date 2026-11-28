<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Organisation profile fields an administrator may write. The reference, the
 * archive lifecycle, membership roles and every financial record are never
 * editable through this request; Ghanaian phone formatting, duplicate
 * prevention and the owner rules live in OrganisationManagementService.
 */
class StoreOrganisationRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'owner_customer_id' => ['required', 'integer', 'exists:customers,id'],
            'description' => ['nullable', 'string', 'max:2000'],
            'business_email' => ['nullable', 'email', 'max:255'],
            'business_phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+().\-\s]+$/'],
            'address' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
