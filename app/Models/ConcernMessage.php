<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ConcernMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'concern_id',
        'chat_room_id',
        'author_id',
        'message',
        'type',
        'message_type',
        'attachments',
        'metadata',
        'is_internal',
        'is_ai_generated',
        'is_typing',
        'delivered_at',
        'read_at',
        'reactions',
        'reply_to_id',
    ];

    protected $casts = [
        'attachments' => 'array',
        'metadata' => 'array',
        'is_internal' => 'boolean',
        'is_ai_generated' => 'boolean',
        'is_typing' => 'boolean',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'reactions' => 'array',
    ];

    // Relationships
    public function concern()
    {
        return $this->belongsTo(Concern::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function chatRoom()
    {
        return $this->belongsTo(ChatRoom::class);
    }

    public function replyTo()
    {
        return $this->belongsTo(ConcernMessage::class, 'reply_to_id');
    }

    public function replies()
    {
        return $this->hasMany(ConcernMessage::class, 'reply_to_id');
    }
}
