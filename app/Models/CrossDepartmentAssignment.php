<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrossDepartmentAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'staff_id',
        'requesting_department_id',
        'concern_id',
        'assignment_type',
        'estimated_duration_hours',
        'actual_duration_hours',
        'status',
        'assigned_at',
        'completed_at',
        'expired_at',
        'assigned_by',
        'completion_notes'
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'completed_at' => 'datetime',
        'expired_at' => 'datetime'
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function requestingDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'requesting_department_id');
    }

    public function concern(): BelongsTo
    {
        return $this->belongsTo(Concern::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired';
    }

    public function getDurationAttribute(): ?int
    {
        if ($this->actual_duration_hours) {
            return $this->actual_duration_hours;
        }
        
        if ($this->completed_at && $this->assigned_at) {
            return $this->assigned_at->diffInHours($this->completed_at);
        }
        
        return null;
    }
}
