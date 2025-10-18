<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AiSuggestionsRequest extends FormRequest
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
            'context' => 'required|string|max:500',
            'type' => 'required|string|in:concern_reply,announcement,message_completion,general',
            'existing_text' => 'nullable|string|max:1000',
            'tone' => 'nullable|string|in:professional,friendly,formal,casual',
        ];
    }

    /**
     * Get custom error messages for validation rules.
     */
    public function messages(): array
    {
        return [
            'context.required' => 'Context is required.',
            'context.max' => 'Context cannot exceed 500 characters.',
            'type.required' => 'Suggestion type is required.',
            'type.in' => 'Type must be one of: concern_reply, announcement, message_completion, general.',
            'existing_text.max' => 'Existing text cannot exceed 1000 characters.',
            'tone.in' => 'Tone must be one of: professional, friendly, formal, casual.',
        ];
    }
}
