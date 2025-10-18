<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingData extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'question',
        'answer',
        'user_message',
        'assistant_response',
        'department',
        'topic',
        'information',
        'category',
        'context',
        'tags',
        'priority',
        'active',
        'source',
        'batch_id',
        'created_by',
    ];

    protected $casts = [
        'tags' => 'array',
        'active' => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    public function scopeByContext($query, $context)
    {
        return $query->where('context', $context);
    }

    public function scopeByBatch($query, $batchId)
    {
        return $query->where('batch_id', $batchId);
    }
}
