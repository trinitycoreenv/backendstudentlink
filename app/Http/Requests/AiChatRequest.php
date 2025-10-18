<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AiChatRequest extends FormRequest
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
            'message' => 'required|string|max:2000',
            'session_id' => 'nullable|string|uuid',
            'context' => 'nullable|string|in:general,concern,assistance',
            'related_concern_id' => 'nullable|exists:concerns,id',
        ];
    }

    /**
     * Get custom error messages for validation rules.
     */
    public function messages(): array
    {
        return [
            'message.required' => 'Message is required.',
            'message.max' => 'Message cannot exceed 2000 characters.',
            'session_id.uuid' => 'Session ID must be a valid UUID.',
            'context.in' => 'Context must be one of: general, concern, assistance.',
            'related_concern_id.exists' => 'Related concern does not exist.',
        ];
    }
}
