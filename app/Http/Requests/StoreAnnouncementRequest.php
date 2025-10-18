<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAnnouncementRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->check() && in_array(auth()->user()->role, ['admin', 'department_head']);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'image' => 'required|image|mimes:jpeg,png,jpg,webp|max:10240', // 10MB max, required
            'internal_title' => 'nullable|string|max:255',
            'category' => 'required|string|in:Academic Modules,Class Schedules & Exams,Enrollment & Clearance,Scholarships & Financial Aid,Student Activities & Events,Emergency Notices,Administrative Updates,OJT & Career Services,Campus Ministry,Faculty Announcements,System Maintenance,Student Services',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'action_button_text' => 'nullable|string|max:100',
            'action_button_url' => 'nullable|string|max:500',
            'announcement_timestamp' => 'nullable|date',
            'status' => 'nullable|string|in:draft,published,archived',
            'published_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after:published_at',
            'scheduled_at' => 'nullable|date|after:now',
            'target_departments' => 'nullable|array',
            'target_departments.*' => 'integer|exists:departments,id',
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
            'category.required' => 'The announcement category is required.',
            'category.in' => 'The announcement category must be one of the predefined categories.',
            'title.required' => 'The announcement title is required.',
            'title.max' => 'The announcement title may not be greater than 255 characters.',
            'description.max' => 'The announcement description may not be greater than 1000 characters.',
            'action_button_text.max' => 'The action button text may not be greater than 100 characters.',
            'action_button_url.url' => 'The action button URL must be a valid URL.',
            'action_button_url.max' => 'The action button URL may not be greater than 500 characters.',
            'announcement_timestamp.date' => 'The announcement timestamp must be a valid date.',
            'status.in' => 'The announcement status must be one of: draft, published, archived.',
            'expires_at.after' => 'The expiration date must be after the publication date.',
            'scheduled_at.after' => 'The scheduled date must be in the future.',
            'target_departments.*.exists' => 'One or more selected departments do not exist.',
            'image.required' => 'An image is required for announcements.',
            'image.image' => 'The uploaded file must be an image.',
            'image.mimes' => 'The image must be a file of type: jpeg, png, jpg, webp.',
            'image.max' => 'The image may not be greater than 10MB.',
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
            'category' => 'announcement category',
            'title' => 'announcement title',
            'description' => 'announcement description',
            'action_button_text' => 'action button text',
            'action_button_url' => 'action button URL',
            'announcement_timestamp' => 'announcement timestamp',
            'status' => 'announcement status',
            'published_at' => 'publication date',
            'expires_at' => 'expiration date',
            'scheduled_at' => 'scheduled date',
            'target_departments' => 'target departments',
            'image' => 'announcement image',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Set published_at to now if status is published and published_at is not set
        if ($this->status === 'published' && !$this->published_at) {
            $this->merge([
                'published_at' => now()->toISOString(),
            ]);
        }

        // No default expiration date - only set if explicitly provided by admin
    }
}
