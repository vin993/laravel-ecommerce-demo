<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;

class ContactRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    protected function prepareForValidation()
    {
        Log::info('ContactRequest validation starting', [
            'all_data' => $this->all()
        ]);
    }

    public function rules()
    {
        return [
            'name'    => 'required|string|max:255',
            'email'   => 'required|email|max:255',
            'contact' => 'nullable|string|max:20',
            'message' => 'required|string|min:10|max:2000',
        ];
    }

    public function messages()
    {
        return [
            'name.required' => 'Please enter your name',
            'email.required' => 'Please enter your email address',
            'email.email' => 'Please enter a valid email address',
            'message.required' => 'Please enter your message',
            'message.min' => 'Message must be at least 10 characters',
        ];
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        Log::error('ContactRequest validation failed', [
            'errors' => $validator->errors()->toArray()
        ]);

        parent::failedValidation($validator);
    }
}
