<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
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
        $userId = auth()->id();
        
        return [
            'name' => 'sometimes|string|max:255',
            'course' => 'sometimes|nullable|string|max:255',
            'year_level' => 'sometimes|nullable|string|max:50',
            'personal_email' => 'sometimes|nullable|email|max:255|unique:app_users,personal_email,' . $userId,
            'phone' => 'sometimes|nullable|string|max:20',
            'password' => 'sometimes|string|min:8|confirmed',
            'avatar' => 'sometimes|nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.string' => 'Name must be a valid string.',
            'name.max' => 'Name cannot exceed 255 characters.',
            'course.string' => 'Course must be a valid string.',
            'course.max' => 'Course cannot exceed 255 characters.',
            'year_level.string' => 'Year level must be a valid string.',
            'year_level.max' => 'Year level cannot exceed 50 characters.',
            'personal_email.email' => 'Personal email must be a valid email address.',
            'personal_email.unique' => 'This personal email is already in use.',
            'phone.string' => 'Phone number must be a valid string.',
            'phone.max' => 'Phone number cannot exceed 20 characters.',
            'password.string' => 'Password must be a valid string.',
            'password.min' => 'Password must be at least 8 characters.',
            'password.confirmed' => 'Password confirmation does not match.',
            'avatar.image' => 'Avatar must be an image file.',
            'avatar.mimes' => 'Avatar must be a JPEG, PNG, JPG, or GIF file.',
            'avatar.max' => 'Avatar file size cannot exceed 2MB.',
        ];
    }
}
