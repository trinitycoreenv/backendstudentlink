<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ConcernFeedback extends Model
{
    use HasFactory;

    protected $table = 'concern_feedback';

    protected $fillable = [
        'concern_id',
        'user_id',
        'rating',
        'response_time_rating',
        'resolution_quality_rating',
        'staff_courtesy_rating',
        'communication_rating',
        'feedback_text',
        'suggestions',
        'additional_ratings',
        'would_recommend',
        'is_anonymous',
    ];

    protected $casts = [
        'additional_ratings' => 'array',
        'would_recommend' => 'boolean',
        'is_anonymous' => 'boolean',
    ];

    // Relationships
    public function concern()
    {
        return $this->belongsTo(Concern::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Accessors
    public function getOverallRatingAttribute()
    {
        $ratings = array_filter([
            $this->rating,
            $this->response_time_rating,
            $this->resolution_quality_rating,
            $this->staff_courtesy_rating,
            $this->communication_rating,
        ]);

        return count($ratings) > 0 ? round(array_sum($ratings) / count($ratings), 1) : 0;
    }

    public function getRatingTextAttribute()
    {
        $overall = $this->overall_rating;
        
        if ($overall >= 4.5) return 'Excellent';
        if ($overall >= 3.5) return 'Good';
        if ($overall >= 2.5) return 'Average';
        if ($overall >= 1.5) return 'Poor';
        return 'Very Poor';
    }

    // Scopes
    public function scopeByRating($query, $rating)
    {
        return $query->where('rating', $rating);
    }

    public function scopeHighRating($query, $minRating = 4)
    {
        return $query->where('rating', '>=', $minRating);
    }

    public function scopeLowRating($query, $maxRating = 2)
    {
        return $query->where('rating', '<=', $maxRating);
    }

    public function scopeWithText($query)
    {
        return $query->whereNotNull('feedback_text')->where('feedback_text', '!=', '');
    }
}
