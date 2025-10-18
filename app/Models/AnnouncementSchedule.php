<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AnnouncementSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'created_by',
        'name',
        'announcements',
        'scheduled_at',
        'status',
        'error_message',
        'results',
    ];

    protected $casts = [
        'announcements' => 'array',
        'results' => 'array',
        'scheduled_at' => 'datetime',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Scope for pending schedules
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope for ready to process
     */
    public function scopeReadyToProcess($query)
    {
        return $query->where('status', 'pending')
                    ->where('scheduled_at', '<=', now());
    }

    /**
     * Scope for specific status
     */
    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }
}
