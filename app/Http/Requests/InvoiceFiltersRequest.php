<?php

namespace App\Http\Requests;

use App\Enums\InvoiceSyncStatus;
use App\Enums\InvoiceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InvoiceFiltersRequest extends FormRequest
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
            'foodics_ref' => ['nullable', 'string', 'max:100'],
            'daftra_no' => ['nullable', 'string', 'max:50'],
            'amount_from' => ['nullable', 'numeric', 'min:0'],
            'amount_to' => [
                'nullable',
                'numeric',
                'min:0',
                Rule::when($this->filled('amount_from'), ['gte:amount_from']),
            ],
            'status' => ['nullable', Rule::in(InvoiceSyncStatus::values())],
            'type' => ['nullable', Rule::in(InvoiceType::values())],
            'date_from' => ['nullable', 'date'],
            'date_to' => [
                'nullable',
                'date',
                Rule::when($this->filled('date_from'), ['after_or_equal:date_from']),
            ],
            'sort_by' => ['nullable', Rule::in(['foodics_reference', 'daftra_no', 'total_price', 'status', 'type', 'created_at'])],
            'sort_dir' => ['nullable', Rule::in(['asc', 'desc'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount_to.gte' => __('The max amount must be greater than or equal to the min amount.'),
            'date_to.after_or_equal' => __('The end date must be after or equal to the start date.'),
        ];
    }
}
