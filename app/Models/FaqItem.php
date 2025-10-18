<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FaqItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'question',
        'answer',
        'category',
        'intent',
        'confidence',
        'active',
        'tags',
        'context',
        'priority',
        'created_by',
    ];

    protected $casts = [
        'tags' => 'array',
        'active' => 'boolean',
        'confidence' => 'decimal:2',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    public function scopeByContext($query, $context)
    {
        return $query->where('context', $context);
    }

    public function scopeHighPriority($query)
    {
        return $query->where('priority', '>=', 3);
    }
}
