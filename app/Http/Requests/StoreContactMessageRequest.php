<?php

namespace App\Http\Requests;

use App\Enums\ContactMessageType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreContactMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50', 'regex:/^[\d\s\+\-\(\)]+$/'],
            'type' => ['required', 'string', 'in:'.implode(',', ContactMessageType::values())],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:1024'],
        ];
    }
}
