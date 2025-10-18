<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AiTranscribeRequest extends FormRequest
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
            'audio' => 'required|file|mimes:mp3,wav,m4a,webm,mp4,mpeg,mpga|max:25600', // 25MB max
            'language' => 'nullable|string|size:2|in:en,es,fr,de,it,pt,ru,ja,ko,zh',
        ];
    }

    /**
     * Get custom error messages for validation rules.
     */
    public function messages(): array
    {
        return [
            'audio.required' => 'Audio file is required.',
            'audio.file' => 'Audio must be a valid file.',
            'audio.mimes' => 'Audio must be one of the following formats: mp3, wav, m4a, webm, mp4, mpeg, mpga.',
            'audio.max' => 'Audio file cannot exceed 25MB.',
            'language.size' => 'Language code must be exactly 2 characters.',
            'language.in' => 'Unsupported language code.',
        ];
    }
}
