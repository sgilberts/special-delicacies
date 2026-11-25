<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMenuItemRequest extends FormRequest
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
        $itemId = $this->route('id') ?? $this->route('item');

        return [
            'category_id' => ['sometimes', 'required', 'integer', 'exists:menu_categories,id'],
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:150', Rule::unique('menu_items', 'slug')->ignore($itemId)],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0.01', 'max:99999.99', 'decimal:0,2'],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,webp,jpg', 'max:5120'],
            'is_available' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
