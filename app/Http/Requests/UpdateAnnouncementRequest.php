<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAnnouncementRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = auth()->user();
        $announcement = $this->route('announcement');
        
        // Admin can update any announcement
        if ($user->role === 'admin') {
            return true;
        }
        
        // Department head can only update their own announcements
        if ($user->role === 'department_head') {
            return $announcement->author_id === $user->id;
        }
        
        return false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'internal_title' => 'nullable|string|max:255',
            'category' => 'sometimes|string|in:Academic Modules,Class Schedules & Exams,Enrollment & Clearance,Scholarships & Financial Aid,Student Activities & Events,Emergency Notices,Administrative Updates,OJT & Career Services,Campus Ministry,Faculty Announcements,System Maintenance,Student Services',
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:1000',
            'action_button_text' => 'nullable|string|max:100',
            'action_button_url' => 'nullable|string|max:500',
            'announcement_timestamp' => 'nullable|date',
            'status' => 'sometimes|string|in:draft,published,archived',
            'published_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after:published_at',
            'scheduled_at' => 'nullable|date|after:now',
            'target_departments' => 'nullable|array',
            'target_departments.*' => 'integer|exists:departments,id',
            'image' => 'sometimes|image|mimes:jpeg,png,jpg,webp|max:10240', // 10MB max
            'remove_image' => 'sometimes|boolean',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'internal_title.max' => 'The internal title may not be greater than 255 characters.',
            'status.in' => 'The announcement status must be one of: draft, published, archived.',
            'published_at.date' => 'The published date must be a valid date.',
            'expires_at.date' => 'The expiration date must be a valid date.',
            'expires_at.after' => 'The expiration date must be after the published date.',
            'scheduled_at.after' => 'The scheduled date must be in the future.',
            'target_departments.array' => 'The target departments must be an array.',
            'target_departments.*.integer' => 'Each target department must be an integer.',
            'target_departments.*.exists' => 'One or more target departments do not exist.',
            'image.image' => 'The uploaded file must be an image.',
            'image.mimes' => 'The image must be a file of type: jpeg, png, jpg, webp.',
            'image.max' => 'The image may not be greater than 10MB.',
            'remove_image.boolean' => 'The remove image field must be true or false.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'internal_title' => 'internal title',
            'status' => 'announcement status',
            'published_at' => 'publication date',
            'expires_at' => 'expiration date',
            'scheduled_at' => 'scheduled date',
            'target_departments' => 'target departments',
            'image' => 'announcement image',
        ];
    }
}

