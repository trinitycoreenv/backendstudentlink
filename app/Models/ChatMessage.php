<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    use HasFactory;

    protected $table = 'chat_messages';

    protected $fillable = [
        'concern_id',
        'chat_room_id',
        'author_id',
        'message',
        'message_type',
        'is_internal',
        'is_typing',
        'attachments',
        'metadata',
        'delivered_at',
        'read_at',
        'reactions',
        'reply_to_id',
    ];

    protected $casts = [
        'attachments' => 'array',
        'metadata' => 'array',
        'reactions' => 'array',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'is_internal' => 'boolean',
        'is_typing' => 'boolean',
    ];

    /**
     * Get the concern that owns the chat message
     */
    public function concern(): BelongsTo
    {
        return $this->belongsTo(Concern::class);
    }

    /**
     * Get the chat room that owns the chat message
     */
    public function chatRoom(): BelongsTo
    {
        return $this->belongsTo(ChatRoom::class);
    }

    /**
     * Get the author of the chat message
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Get the message this is replying to
     */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'reply_to_id');
    }

    /**
     * Scope to get messages for a specific chat room
     */
    public function scopeForChatRoom($query, $chatRoomId)
    {
        return $query->where('chat_room_id', $chatRoomId);
    }

    /**
     * Scope to get messages for a specific concern
     */
    public function scopeForConcern($query, $concernId)
    {
        return $query->where('concern_id', $concernId);
    }

    /**
     * Scope to get unread messages for a user
     */
    public function scopeUnreadForUser($query, $userId)
    {
        return $query->where('author_id', '!=', $userId)
                    ->whereNull('read_at');
    }

    /**
     * Mark message as read
     */
    public function markAsRead(): void
    {
        $this->update(['read_at' => now()]);
    }

    /**
     * Mark message as delivered
     */
    public function markAsDelivered(): void
    {
        $this->update(['delivered_at' => now()]);
    }

    /**
     * Check if message is read
     */
    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    /**
     * Check if message is delivered
     */
    public function isDelivered(): bool
    {
        return $this->delivered_at !== null;
    }

    /**
     * Check if message is from system
     */
    public function isSystem(): bool
    {
        return in_array($this->message_type, ['system', 'status_change', 'resolution_confirmation', 'resolution_dispute', 'chat_closure', 'chat_reopened']);
    }

    /**
     * Get formatted message type
     */
    public function getFormattedMessageTypeAttribute(): string
    {
        return match($this->message_type) {
            'text' => 'Text',
            'image' => 'Image',
            'file' => 'File',
            'system' => 'System',
            'status_change' => 'Status Change',
            'resolution_confirmation' => 'Resolution Confirmation',
            'resolution_dispute' => 'Resolution Dispute',
            'chat_closure' => 'Chat Closure',
            'chat_reopened' => 'Chat Reopened',
            default => ucfirst($this->message_type),
        };
    }
}
