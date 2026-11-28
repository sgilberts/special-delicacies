<?php
namespace App\Http\Requests\Admin;
use App\Models\RestaurantTable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
class UpdateRestaurantTableRequest extends FormRequest {
    public function authorize(): bool { return true; }
    public function rules(): array {
        $id = $this->route('id');
        return [
            'table_number' => ['sometimes', 'string', 'max:20', "unique:restaurant_tables,table_number,{$id}"],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'is_active' => ['sometimes', 'boolean'],
            'venue_id' => ['sometimes', 'nullable', 'integer', 'exists:venues,id'],
            'table_type' => ['sometimes', 'nullable', Rule::in(RestaurantTable::TYPES)],
            'operational_status' => ['sometimes', Rule::in(RestaurantTable::OPERATIONAL_STATUSES)],
            'reservation_eligible' => ['sometimes', 'boolean'],
            'seating_configuration' => ['sometimes', 'nullable', 'array'],
            'admin_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
    public function messages(): array {
        return [
            'table_number.unique' => 'A table with this number already exists.',
            'venue_id.exists' => 'The selected venue does not exist.',
        ];
    }
}
