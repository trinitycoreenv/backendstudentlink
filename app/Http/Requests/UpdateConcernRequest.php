<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateConcernRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization is handled by middleware
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'subject' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|required|string|max:5000',
            'department_id' => 'sometimes|required|integer|exists:departments,id',
            'facility_id' => 'nullable|integer|exists:facilities,id',
            'type' => 'sometimes|required|string|in:academic,financial,facility,student_services,technical,disciplinary,general',
            'priority' => 'sometimes|required|string|in:low,medium,high,urgent',
            'status' => 'sometimes|required|string|in:pending,in_progress,resolved,closed',
            'is_anonymous' => 'sometimes|boolean',
            'assigned_to' => 'nullable|integer|exists:users,id',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'subject.required' => 'The subject field is required.',
            'subject.max' => 'The subject may not be greater than 255 characters.',
            'description.required' => 'The description field is required.',
            'description.max' => 'The description may not be greater than 5000 characters.',
            'department_id.required' => 'Please select a department.',
            'department_id.exists' => 'The selected department is invalid.',
            'facility_id.exists' => 'The selected facility is invalid.',
            'type.required' => 'Please select a concern type.',
            'type.in' => 'The selected concern type is invalid.',
            'priority.required' => 'Please select a priority level.',
            'priority.in' => 'The selected priority level is invalid.',
            'status.required' => 'Please select a status.',
            'status.in' => 'The selected status is invalid.',
            'is_anonymous.boolean' => 'The anonymous field must be true or false.',
            'assigned_to.exists' => 'The selected user is invalid.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'subject' => 'subject',
            'description' => 'description',
            'department_id' => 'department',
            'facility_id' => 'facility',
            'type' => 'concern type',
            'priority' => 'priority',
            'status' => 'status',
            'is_anonymous' => 'anonymous',
            'assigned_to' => 'assigned user',
        ];
    }
}
