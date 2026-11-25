<?php
namespace App\Http\Requests\Admin;
use Illuminate\Foundation\Http\FormRequest;
class UpdateRestaurantTableRequest extends FormRequest {
    public function authorize(): bool { return true; }
    public function rules(): array {
        $id = $this->route('id');
        return [
            'table_number' => ['sometimes', 'string', 'max:20', "unique:restaurant_tables,table_number,{$id}"],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
