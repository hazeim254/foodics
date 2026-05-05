<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBranchMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mappings' => ['required', 'array'],
            'mappings.*.foodics_id' => ['required', 'string', 'max:255'],
            'mappings.*.daftra_id' => ['nullable', 'string', 'max:255'],
        ];
    }
}