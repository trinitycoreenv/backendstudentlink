<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    use HasFactory;

    protected $fillable = [
        'author_id',
        'internal_title',
        'category',
        'title',
        'description',
        'action_button_text',
        'action_button_url',
        'announcement_timestamp',
        'status',
        'published_at',
        'expires_at',
        'view_count',
        'download_count',
        'share_count',
        'scheduled_at',
        'moderation_status',
        'moderated_by',
        'moderation_notes',
        'image_path',
        'image_filename',
        'image_mime_type',
        'image_size',
        'image_width',
        'image_height'
    ];

    protected $casts = [
        'announcement_timestamp' => 'datetime',
        'published_at' => 'datetime',
        'expires_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'view_count' => 'integer',
        'download_count' => 'integer',
        'share_count' => 'integer',
        'image_size' => 'integer',
        'image_width' => 'integer',
        'image_height' => 'integer'
    ];

    protected $appends = [
        'image_url',
        'image_download_url',
        'thumbnail_url',
        'compressed_image_url'
    ];

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function targetDepartments()
    {
        return $this->belongsToMany(Department::class, 'announcement_departments');
    }

    /**
     * Get available announcement categories
     */
    public static function getAvailableCategories(): array
    {
        return [
            'Academic Modules',
            'Class Schedules & Exams',
            'Enrollment & Clearance',
            'Scholarships & Financial Aid',
            'Student Activities & Events',
            'Emergency Notices',
            'Administrative Updates',
            'OJT & Career Services',
            'Campus Ministry',
            'Faculty Announcements',
            'System Maintenance',
            'Student Services'
        ];
    }

    /**
     * Get image URL
     */
    public function getImageUrlAttribute(): ?string
    {
        if (!$this->image_path) {
            return null;
        }
        
        $url = asset('storage/' . $this->image_path);
        
        // Debug: Log the image URL generation
        \Log::info('Generated image URL:', [
            'id' => $this->id,
            'image_path' => $this->image_path,
            'generated_url' => $url,
        ]);
        
        return $url;
    }

    /**
     * Get image download URL
     */
    public function getImageDownloadUrlAttribute(): ?string
    {
        if (!$this->image_path) {
            return null;
        }
        
        return url("/api/announcements/{$this->id}/image/download");
    }

    /**
     * Get thumbnail URL
     */
    public function getThumbnailUrlAttribute(): ?string
    {
        if (!$this->image_path) {
            return null;
        }
        
        $pathInfo = pathinfo($this->image_path);
        $thumbnailPath = $pathInfo['dirname'] . '/thumb_' . $pathInfo['basename'];
        
        return asset('storage/' . $thumbnailPath);
    }

    /**
     * Get compressed image URL
     */
    public function getCompressedImageUrlAttribute(): ?string
    {
        if (!$this->image_path) {
            return null;
        }
        
        $pathInfo = pathinfo($this->image_path);
        $compressedPath = $pathInfo['dirname'] . '/compressed_' . $pathInfo['basename'];
        
        return asset('storage/' . $compressedPath);
    }

    /**
     * Check if announcement is published
     */
    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    /**
     * Check if announcement is scheduled
     */
    public function isScheduled(): bool
    {
        return $this->scheduled_at && $this->scheduled_at > now();
    }

    /**
     * Check if announcement is expired
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at < now();
    }

    /**
     * Scope for published announcements
     */
    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    /**
     * Scope for scheduled announcements
     */
    public function scopeScheduled($query)
    {
        return $query->whereNotNull('scheduled_at')->where('scheduled_at', '>', now());
    }

    /**
     * Scope for expired announcements
     */
    public function scopeExpired($query)
    {
        return $query->whereNotNull('expires_at')->where('expires_at', '<', now());
    }
}
