<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InvoiceFiltersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, string[]>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'foodics_ref' => ['nullable', 'string', 'max:100'],
            'daftra_no' => ['nullable', 'string', 'max:50'],
            'amount_from' => ['nullable', 'numeric', 'min:0'],
            'amount_to' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'string', 'in:pending,failed,synced'],
            'type' => ['nullable', 'string', 'in:invoice,credit_note'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'sort_by' => ['nullable', 'string', 'in:foodics_reference,daftra_no,total_price,status,created_at'],
            'sort_dir' => ['nullable', 'string', 'in:asc,desc'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount_to.min' => __('Amount must be greater than 0.'),
            'date_to.after_or_equal' => __('End date must be after start date.'),
        ];
    }
}
