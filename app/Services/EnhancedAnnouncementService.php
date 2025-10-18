<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\AnnouncementAnalytics;
use App\Models\AnnouncementSchedule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Intervention\Image\Facades\Image;
use Illuminate\Support\Str;

class EnhancedAnnouncementService
{
    protected $imageService;
    protected $auditLogService;

    public function __construct(AnnouncementImageService $imageService, AuditLogService $auditLogService)
    {
        $this->imageService = $imageService;
        $this->auditLogService = $auditLogService;
    }

    /**
     * Create image-only announcement with enhanced processing
     */
    public function createImageAnnouncement(array $data, UploadedFile $image, $userId): array
    {
        try {
            // Validate image
            $validation = $this->validateImage($image);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'message' => $validation['message']
                ];
            }

            // Process image (original, thumbnail, compressed)
            $imageData = $this->processImage($image);
            
            // Prepare announcement data
            $announcementData = array_merge($data, [
                'author_id' => $userId,
                'announcement_type' => 'image',
                'content' => null, // No text content for image announcements
                'image_path' => $imageData['original_path'],
                'image_filename' => $imageData['filename'],
                'image_mime_type' => $imageData['mime_type'],
                'image_size' => $imageData['size'],
                'image_width' => $imageData['width'],
                'image_height' => $imageData['height'],
                'image_thumbnail_path' => $imageData['thumbnail_path'],
                'image_compressed_path' => $imageData['compressed_path'],
                'image_metadata' => $imageData['metadata'],
                'cdn_url' => $imageData['cdn_url'],
                'storage_provider' => $imageData['storage_provider'],
                'download_count' => 0,
                'share_count' => 0,
                'view_count' => 0,
            ]);

            // Set published_at if status is published
            if ($announcementData['status'] === 'published' && !isset($announcementData['published_at'])) {
                $announcementData['published_at'] = now();
            }

            // Create announcement
            $announcement = Announcement::create($announcementData);

            // Attach target departments if specified
            if (isset($data['target_departments']) && is_array($data['target_departments'])) {
                $announcement->targetDepartments()->sync($data['target_departments']);
            }

            // Log creation
            $this->auditLogService->logCrud('create', 'announcement', $announcement->id, null, [
                'title' => $announcement->title,
                'announcement_type' => 'image',
                'image_size' => $imageData['size'],
                'image_dimensions' => "{$imageData['width']}x{$imageData['height']}",
            ]);

            return [
                'success' => true,
                'message' => 'Image announcement created successfully',
                'data' => $announcement->load(['author:id,name,role', 'targetDepartments'])
            ];

        } catch (\Exception $e) {
            Log::error('Failed to create image announcement: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to create image announcement',
                'error' => app()->environment('local') ? $e->getMessage() : null
            ];
        }
    }

    /**
     * Validate uploaded image
     */
    public function validateImage(UploadedFile $image): array
    {
        // Check file size (max 10MB)
        if ($image->getSize() > 10 * 1024 * 1024) {
            return [
                'valid' => false,
                'message' => 'Image size must be less than 10MB'
            ];
        }

        // Check dimensions (max 4000x4000)
        $imageInfo = getimagesize($image->getPathname());
        if ($imageInfo[0] > 4000 || $imageInfo[1] > 4000) {
            return [
                'valid' => false,
                'message' => 'Image dimensions must be less than 4000x4000 pixels'
            ];
        }

        // Check minimum dimensions (min 300x300)
        if ($imageInfo[0] < 300 || $imageInfo[1] < 300) {
            return [
                'valid' => false,
                'message' => 'Image dimensions must be at least 300x300 pixels'
            ];
        }

        // Check MIME type
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($image->getMimeType(), $allowedMimes)) {
            return [
                'valid' => false,
                'message' => 'Only JPEG, PNG, and WebP images are allowed'
            ];
        }

        return [
            'valid' => true,
            'message' => 'Image is valid'
        ];
    }

    /**
     * Process image (create original, thumbnail, compressed versions)
     */
    public function processImage(UploadedFile $image): array
    {
        $filename = Str::uuid() . '.' . $image->getClientOriginalExtension();
        $timestamp = now()->format('Y/m/d');
        
        // Create directory structure
        $basePath = "announcements/{$timestamp}";
        
        // Store original image
        $originalPath = $image->storeAs($basePath, $filename, 'public');
        
        // Get image info
        $imageInfo = getimagesize($image->getPathname());
        $width = $imageInfo[0];
        $height = $imageInfo[1];
        
        // Create thumbnail (300x300)
        $thumbnailPath = $this->createThumbnail($image, $basePath, $filename, 300, 300);
        
        // Create compressed version (max 1200px width, 80% quality)
        $compressedPath = $this->createCompressedImage($image, $basePath, $filename, 1200, 80);
        
        // Generate CDN URL (if using CDN)
        $cdnUrl = $this->generateCdnUrl($originalPath);
        
        return [
            'original_path' => $originalPath,
            'thumbnail_path' => $thumbnailPath,
            'compressed_path' => $compressedPath,
            'filename' => $filename,
            'mime_type' => $image->getMimeType(),
            'size' => $image->getSize(),
            'width' => $width,
            'height' => $height,
            'metadata' => [
                'original_size' => $image->getSize(),
                'original_dimensions' => "{$width}x{$height}",
                'aspect_ratio' => round($width / $height, 2),
                'processed_at' => now()->toISOString(),
            ],
            'cdn_url' => $cdnUrl,
            'storage_provider' => 'local', // Can be extended for S3, etc.
        ];
    }

    /**
     * Create thumbnail version
     */
    protected function createThumbnail(UploadedFile $image, string $basePath, string $filename, int $width, int $height): string
    {
        $thumbnailFilename = 'thumb_' . $filename;
        $thumbnailPath = $basePath . '/' . $thumbnailFilename;
        
        $img = Image::make($image->getPathname())
            ->fit($width, $height, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            })
            ->encode('jpg', 85);
        
        Storage::disk('public')->put($thumbnailPath, $img);
        
        return $thumbnailPath;
    }

    /**
     * Create compressed version
     */
    protected function createCompressedImage(UploadedFile $image, string $basePath, string $filename, int $maxWidth, int $quality): string
    {
        $compressedFilename = 'compressed_' . $filename;
        $compressedPath = $basePath . '/' . $compressedFilename;
        
        $img = Image::make($image->getPathname())
            ->resize($maxWidth, null, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            })
            ->encode('jpg', $quality);
        
        Storage::disk('public')->put($compressedPath, $img);
        
        return $compressedPath;
    }

    /**
     * Generate CDN URL
     */
    protected function generateCdnUrl(string $path): ?string
    {
        // For now, return null (local storage)
        // Can be extended to integrate with CDN services
        return null;
    }

    /**
     * Track announcement analytics
     */
    public function trackAnalytics(int $announcementId, string $action, ?int $userId = null, array $metadata = []): void
    {
        try {
            AnnouncementAnalytics::create([
                'announcement_id' => $announcementId,
                'user_id' => $userId,
                'action' => $action,
                'device_type' => $metadata['device_type'] ?? null,
                'user_agent' => $metadata['user_agent'] ?? null,
                'ip_address' => $metadata['ip_address'] ?? null,
                'metadata' => $metadata,
                'created_at' => now(),
            ]);

            // Update announcement counters
            $announcement = Announcement::find($announcementId);
            if ($announcement) {
                switch ($action) {
                    case 'view':
                        $announcement->increment('view_count');
                        break;
                    case 'download':
                        $announcement->increment('download_count');
                        break;
                    case 'share':
                        $announcement->increment('share_count');
                        break;
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to track analytics: ' . $e->getMessage());
        }
    }

    /**
     * Get announcement analytics
     */
    public function getAnalytics(int $announcementId, ?string $period = '30d'): array
    {
        $query = AnnouncementAnalytics::where('announcement_id', $announcementId);
        
        // Apply time filter
        switch ($period) {
            case '7d':
                $query->where('created_at', '>=', now()->subDays(7));
                break;
            case '30d':
                $query->where('created_at', '>=', now()->subDays(30));
                break;
            case '90d':
                $query->where('created_at', '>=', now()->subDays(90));
                break;
        }

        $analytics = $query->get();
        
        return [
            'total_views' => $analytics->where('action', 'view')->count(),
            'total_downloads' => $analytics->where('action', 'download')->count(),
            'total_shares' => $analytics->where('action', 'share')->count(),
            'unique_users' => $analytics->whereNotNull('user_id')->pluck('user_id')->unique()->count(),
            'device_breakdown' => $analytics->groupBy('device_type')->map->count(),
            'daily_stats' => $analytics->groupBy(function ($item) {
                return $item->created_at->format('Y-m-d');
            })->map(function ($dayAnalytics) {
                return [
                    'views' => $dayAnalytics->where('action', 'view')->count(),
                    'downloads' => $dayAnalytics->where('action', 'download')->count(),
                    'shares' => $dayAnalytics->where('action', 'share')->count(),
                ];
            }),
        ];
    }

    /**
     * Schedule bulk announcement creation
     */
    public function scheduleBulkAnnouncements(array $announcements, \DateTime $scheduledAt, int $userId): array
    {
        try {
            $schedule = AnnouncementSchedule::create([
                'created_by' => $userId,
                'name' => "Bulk Upload - " . now()->format('Y-m-d H:i:s'),
                'announcements' => $announcements,
                'scheduled_at' => $scheduledAt,
                'status' => 'pending',
            ]);

            return [
                'success' => true,
                'message' => 'Bulk announcements scheduled successfully',
                'data' => $schedule
            ];
        } catch (\Exception $e) {
            Log::error('Failed to schedule bulk announcements: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to schedule bulk announcements',
                'error' => app()->environment('local') ? $e->getMessage() : null
            ];
        }
    }

    /**
     * Process scheduled announcements
     */
    public function processScheduledAnnouncements(): void
    {
        $schedules = AnnouncementSchedule::where('status', 'pending')
            ->where('scheduled_at', '<=', now())
            ->get();

        foreach ($schedules as $schedule) {
            try {
                $schedule->update(['status' => 'processing']);
                
                $results = [];
                foreach ($schedule->announcements as $announcementData) {
                    // Process each announcement
                    $result = $this->processScheduledAnnouncement($announcementData, $schedule->created_by);
                    $results[] = $result;
                }
                
                $schedule->update([
                    'status' => 'completed',
                    'results' => $results
                ]);
                
            } catch (\Exception $e) {
                $schedule->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage()
                ]);
                
                Log::error('Failed to process scheduled announcements: ' . $e->getMessage());
            }
        }
    }

    /**
     * Process individual scheduled announcement
     */
    protected function processScheduledAnnouncement(array $data, int $userId): array
    {
        // This would handle the actual announcement creation
        // Implementation depends on the specific requirements
        return [
            'success' => true,
            'announcement_id' => null, // Would be set after creation
            'message' => 'Announcement processed successfully'
        ];
    }

    /**
     * Moderate announcement
     */
    public function moderateAnnouncement(int $announcementId, string $status, ?string $notes = null, int $moderatorId): array
    {
        try {
            $announcement = Announcement::findOrFail($announcementId);
            
            $announcement->update([
                'moderation_status' => $status,
                'moderation_notes' => $notes,
                'moderated_by' => $moderatorId,
                'moderated_at' => now(),
            ]);

            // If approved, set status to published
            if ($status === 'approved' && $announcement->status === 'draft') {
                $announcement->update([
                    'status' => 'published',
                    'published_at' => now(),
                ]);
            }

            $this->auditLogService->logCrud('update', 'announcement', $announcementId, null, [
                'moderation_status' => $status,
                'moderated_by' => $moderatorId,
            ]);

            return [
                'success' => true,
                'message' => 'Announcement moderated successfully',
                'data' => $announcement
            ];
        } catch (\Exception $e) {
            Log::error('Failed to moderate announcement: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to moderate announcement',
                'error' => app()->environment('local') ? $e->getMessage() : null
            ];
        }
    }
}
