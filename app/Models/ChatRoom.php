<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatRoom extends Model
{
    use HasFactory;

    protected $fillable = [
        'concern_id',
        'room_name',
        'status',
        'last_activity_at',
        'last_message_id',
        'participants',
        'settings',
        'closed_at',
        'closed_by',
    ];

    protected $casts = [
        'participants' => 'array',
        'settings' => 'array',
        'last_activity_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    /**
     * Get the concern that owns the chat room
     */
    public function concern(): BelongsTo
    {
        return $this->belongsTo(Concern::class);
    }

    /**
     * Get the last message in the chat room
     */
    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'last_message_id');
    }

    /**
     * Get all messages in the chat room
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    /**
     * Get the user who closed the chat room
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * Check if chat room is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if chat room is closed
     */
    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    /**
     * Check if chat room is archived
     */
    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }

    /**
     * Check if user is a participant in the chat room
     */
    public function hasParticipant(int $userId): bool
    {
        if (!$this->participants) {
            return false;
        }

        foreach ($this->participants as $participant) {
            if (isset($participant['user_id']) && $participant['user_id'] == $userId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add participant to chat room
     */
    public function addParticipant(int $userId, string $role = 'participant'): void
    {
        $participants = $this->participants ?? [];
        
        $participants[$userId] = [
            'user_id' => $userId,
            'role' => $role,
            'joined_at' => now()->toISOString(),
        ];

        $this->update(['participants' => $participants]);
    }

    /**
     * Remove participant from chat room
     */
    public function removeParticipant(int $userId): void
    {
        $participants = $this->participants ?? [];
        
        if (isset($participants[$userId])) {
            unset($participants[$userId]);
            $this->update(['participants' => $participants]);
        }
    }

    /**
     * Get participant count
     */
    public function getParticipantCount(): int
    {
        return count($this->participants ?? []);
    }

    /**
     * Update last activity
     */
    public function updateLastActivity(?int $messageId = null): void
    {
        $this->update([
            'last_activity_at' => now(),
            'last_message_id' => $messageId,
        ]);
    }

    /**
     * Close chat room
     */
    public function close(int $closedBy): void
    {
        $this->update([
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => $closedBy,
        ]);
    }

    /**
     * Reopen chat room
     */
    public function reopen(): void
    {
        $this->update([
            'status' => 'active',
            'closed_at' => null,
            'closed_by' => null,
        ]);
    }

    /**
     * Get unread message count for a user
     */
    public function getUnreadCountForUser(int $userId): int
    {
        return $this->messages()
            ->where('author_id', '!=', $userId)
            ->whereNull('read_at')
            ->count();
    }

    /**
     * Scope to get active chat rooms
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope to get closed chat rooms
     */
    public function scopeClosed($query)
    {
        return $query->where('status', 'closed');
    }

    /**
     * Scope to get chat rooms for a user
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->whereJsonContains('participants', ['user_id' => $userId]);
    }
}