<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreFcmTokenRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'token' => 'required|string|max:255',
            'device_type' => 'required|string|in:android,ios,web',
            'device_id' => 'nullable|string|max:100',
        ];
    }

    /**
     * Get custom error messages for validation rules.
     */
    public function messages(): array
    {
        return [
            'token.required' => 'FCM token is required.',
            'token.max' => 'FCM token cannot exceed 255 characters.',
            'device_type.required' => 'Device type is required.',
            'device_type.in' => 'Device type must be one of: android, ios, web.',
            'device_id.max' => 'Device ID cannot exceed 100 characters.',
        ];
    }
}
