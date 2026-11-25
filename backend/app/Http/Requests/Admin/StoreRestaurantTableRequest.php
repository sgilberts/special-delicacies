<?php
namespace App\Http\Requests\Admin;
use Illuminate\Foundation\Http\FormRequest;
class StoreRestaurantTableRequest extends FormRequest {
    public function authorize(): bool { return true; }
    public function rules(): array {
        return [
            'table_number' => ['required', 'string', 'max:20', 'unique:restaurant_tables,table_number'],
            'capacity' => ['required', 'integer', 'min:1', 'max:50'],
            'is_active' => ['boolean'],
        ];
    }
    public function messages(): array {
        return [
            'table_number.unique' => 'A table with this number already exists.',
            'capacity.min' => 'Capacity must be at least 1 seat.',
            'capacity.max' => 'Capacity cannot exceed 50 seats.',
        ];
    }
}
