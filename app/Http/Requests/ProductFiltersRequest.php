<?php

namespace App\Http\Requests;

use App\Enums\ProductSyncStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductFiltersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed[]>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(ProductSyncStatus::values())],
            'price_from' => ['nullable', 'numeric', 'min:0'],
            'price_to' => [
                'nullable',
                'numeric',
                'min:0',
                Rule::when($this->filled('price_from'), ['gte:price_from']),
            ],
            'date_from' => ['nullable', 'date'],
            'date_to' => [
                'nullable',
                'date',
                Rule::when($this->filled('date_from'), ['after_or_equal:date_from']),
            ],
            'sort_by' => ['nullable', Rule::in(['foodics_name', 'foodics_sku', 'price', 'daftra_id', 'status', 'created_at'])],
            'sort_dir' => ['nullable', Rule::in(['asc', 'desc'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'price_to.gte' => __('The max price must be greater than or equal to the min price.'),
            'date_to.after_or_equal' => __('The end date must be after or equal to the start date.'),
        ];
    }
}
