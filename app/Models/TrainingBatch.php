<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainingBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'batch_id',
        'filename',
        'type',
        'total_items',
        'successful_items',
        'failed_items',
        'status',
        'errors',
        'created_by',
        'processed_at',
    ];

    protected $casts = [
        'errors' => 'array',
        'processed_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function trainingData(): HasMany
    {
        return $this->hasMany(TrainingData::class, 'batch_id', 'batch_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', 'processing');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function getSuccessRateAttribute(): float
    {
        if ($this->total_items === 0) {
            return 0;
        }
        return round(($this->successful_items / $this->total_items) * 100, 2);
    }
}
