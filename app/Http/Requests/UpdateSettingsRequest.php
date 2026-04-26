<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'daftra_default_client_id' => ['nullable', 'string', 'max:255'],
            'daftra_default_branch_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
