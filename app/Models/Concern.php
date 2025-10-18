<?php

namespace App\Models;

use App\Services\N8nWorkflowService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class Concern extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'subject',
        'description',
        'department_id',
        'facility_id',
        'type',
        'priority',
        'status',
        'reference_number',
        'is_anonymous',
        'attachments',
        'assigned_to',
        'resolved_at',
        'resolution_notes',
        'rejection_reason',
        'approved_at',
        'rejected_at',
        'approved_by',
        'rejected_by',
        'student_resolved_at',
        'student_resolution_notes',
        'dispute_reason',
        'disputed_at',
        'archived_at',
        'rating',
        // Workflow automation fields
        'auto_approved',
        'escalated_at',
        'escalated_by',
        'escalation_reason',
        'closed_at',
        'closed_by',
        'auto_closed',
    ];

    protected $casts = [
        'is_anonymous' => 'boolean',
        'attachments' => 'array',
        'metadata' => 'array',
        'resolved_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'student_resolved_at' => 'datetime',
        'disputed_at' => 'datetime',
        'archived_at' => 'datetime',
        // Workflow automation casts
        'auto_approved' => 'boolean',
        'escalated_at' => 'datetime',
        'closed_at' => 'datetime',
        'auto_closed' => 'boolean',
    ];

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::created(function (Concern $concern) {
            // Trigger n8n workflows when a new concern is created
            try {
                $n8nService = app(N8nWorkflowService::class);
                
                // Trigger concern classification
                $n8nService->triggerConcernClassification($concern);
                
                // Trigger auto-reply FAQ check
                $n8nService->triggerAutoReplyFAQ($concern);
                
                Log::info('N8N workflows triggered for new concern', [
                    'concern_id' => $concern->id,
                    'reference_number' => $concern->reference_number,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to trigger N8N workflows for new concern', [
                    'concern_id' => $concern->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        static::updated(function (Concern $concern) {
            // Trigger assignment reminder if concern is assigned
            if ($concern->wasChanged('assigned_to') && $concern->assigned_to) {
                try {
                    $n8nService = app(N8nWorkflowService::class);
                    $n8nService->triggerAssignmentReminder($concern, 'deadline_approaching');
                    
                    Log::info('N8N assignment reminder triggered', [
                        'concern_id' => $concern->id,
                        'assigned_to' => $concern->assigned_to,
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to trigger N8N assignment reminder', [
                        'concern_id' => $concern->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });
    }

    // Relationships
    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function facility()
    {
        return $this->belongsTo(Facility::class);
    }

    public function assignedTo()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function messages()
    {
        return $this->hasMany(ConcernMessage::class);
    }

    public function statusHistory()
    {
        return $this->hasMany(ConcernStatusHistory::class);
    }

    public function feedback()
    {
        return $this->hasMany(ConcernFeedback::class);
    }

    public function latestFeedback()
    {
        return $this->hasOne(ConcernFeedback::class)->latest();
    }

    public function chatRoom()
    {
        return $this->hasOne(ChatRoom::class);
    }

    // Check if concern can receive feedback
    public function canReceiveFeedback()
    {
        return in_array($this->status, ['student_confirmed', 'closed']) && $this->student_resolved_at;
    }

    // Check if concern can be confirmed by student
    public function canBeConfirmedByStudent()
    {
        return in_array($this->status, ['resolved', 'staff_resolved']) && $this->student_id === auth()->id();
    }

    // Check if concern can be disputed by student
    public function canBeDisputedByStudent()
    {
        return in_array($this->status, ['resolved', 'staff_resolved']) && $this->student_id === auth()->id();
    }

    // Check if concern is truly resolved (student confirmed)
    public function isTrulyResolved()
    {
        return $this->status === 'student_confirmed';
    }

    // Check if concern is disputed
    public function isDisputed()
    {
        return $this->status === 'disputed';
    }

    // Get average rating
    public function getAverageRating()
    {
        return $this->feedback()->avg('rating') ?? 0;
    }

    // Get feedback count
    public function getFeedbackCount()
    {
        return $this->feedback()->count();
    }

    // Check if concern is archived
    public function isArchived()
    {
        return $this->archived_at !== null;
    }

    // Archive the concern
    public function archive()
    {
        $this->update(['archived_at' => now()]);
    }

    // Unarchive the concern
    public function unarchive()
    {
        $this->update(['archived_at' => null]);
    }

    // Scope for non-archived concerns
    public function scopeNotArchived($query)
    {
        return $query->whereNull('archived_at');
    }

    // Scope for archived concerns
    public function scopeArchived($query)
    {
        return $query->whereNotNull('archived_at');
    }

    // Get rating display (stars)
    public function getRatingDisplayAttribute(): string
    {
        if (!$this->rating) {
            return 'No rating';
        }
        
        $stars = str_repeat('★', $this->rating) . str_repeat('☆', 5 - $this->rating);
        return $stars . " ({$this->rating}/5)";
    }

    // Check if concern has rating
    public function hasRating(): bool
    {
        return $this->rating !== null && $this->rating > 0;
    }
}
