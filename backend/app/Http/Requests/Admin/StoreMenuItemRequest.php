<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreMenuItemRequest extends FormRequest
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
            'category_id' => ['required', 'integer', 'exists:menu_categories,id'],
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:150', 'unique:menu_items,slug'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['required', 'numeric', 'min:0.01', 'max:99999.99', 'decimal:0,2'],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,webp,jpg', 'max:5120'],
            'is_available' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
