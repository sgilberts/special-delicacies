<?php
namespace App\Http\Requests\Admin;
use App\Models\RestaurantTable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
class StoreRestaurantTableRequest extends FormRequest {
    public function authorize(): bool { return true; }
    public function rules(): array {
        return [
            'table_number' => ['required', 'string', 'max:20', 'unique:restaurant_tables,table_number'],
            'capacity' => ['required', 'integer', 'min:1', 'max:50'],
            'is_active' => ['boolean'],
            'venue_id' => ['nullable', 'integer', 'exists:venues,id'],
            'table_type' => ['nullable', Rule::in(RestaurantTable::TYPES)],
            'operational_status' => ['sometimes', Rule::in(RestaurantTable::OPERATIONAL_STATUSES)],
            'reservation_eligible' => ['boolean'],
            'seating_configuration' => ['nullable', 'array'],
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
    public function messages(): array {
        return [
            'table_number.unique' => 'A table with this number already exists.',
            'capacity.min' => 'Capacity must be at least 1 seat.',
            'capacity.max' => 'Capacity cannot exceed 50 seats.',
            'venue_id.exists' => 'The selected venue does not exist.',
        ];
    }
}
