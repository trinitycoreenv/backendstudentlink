<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'title',
        'message',
        'data',
        'related_type',
        'related_id',
        'priority',
        'read_at',
        'push_sent',
        'email_sent',
    ];

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
        'push_sent' => 'boolean',
        'email_sent' => 'boolean',
    ];

    /**
     * Get the user that owns the notification
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope to get unread notifications
     */
    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    /**
     * Scope to get read notifications
     */
    public function scopeRead($query)
    {
        return $query->whereNotNull('read_at');
    }

    /**
     * Scope to filter by type
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to filter by priority
     */
    public function scopeOfPriority($query, string $priority)
    {
        return $query->where('priority', $priority);
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(): bool
    {
        return $this->update(['read_at' => now()]);
    }

    /**
     * Mark notification as clicked (using read_at for now)
     */
    public function markAsClicked(): bool
    {
        return $this->update(['read_at' => now()]);
    }

    /**
     * Check if notification is read
     */
    public function isRead(): bool
    {
        return !is_null($this->read_at);
    }

    /**
     * Check if notification is unread
     */
    public function isUnread(): bool
    {
        return is_null($this->read_at);
    }

    /**
     * Check if notification has been clicked (using read_at for now)
     */
    public function isClicked(): bool
    {
        return !is_null($this->read_at);
    }

    /**
     * Get notification type label
     */
    public function getTypeLabelAttribute(): string
    {
        return match ($this->type) {
            'announcement' => 'Announcement',
            'concern_update' => 'Concern Update',
            'concern_assignment' => 'Assignment',
            'chat_message' => 'Message',
            'emergency' => 'Emergency',
            'test' => 'Test',
            default => 'General',
        };
    }

    /**
     * Get priority color
     */
    public function getPriorityColorAttribute(): string
    {
        return match ($this->priority) {
            'urgent' => '#DC2626',
            'high' => '#EA580C',
            'medium' => '#D97706',
            'low' => '#16A34A',
            default => '#6B7280',
        };
    }

    /**
     * Get formatted time ago
     */
    public function getTimeAgoAttribute(): string
    {
        return $this->created_at->diffForHumans();
    }
}