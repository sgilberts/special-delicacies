<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Partial organisation profile update. The owner, the reference, membership
 * relationships, the archive lifecycle and all financial records are
 * deliberately not editable through this request.
 */
class UpdateOrganisationRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'business_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'business_phone' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^[0-9()+.\-\s]+$/'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
