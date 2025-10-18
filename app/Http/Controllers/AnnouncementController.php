<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAnnouncementRequest;
use App\Http\Requests\UpdateAnnouncementRequest;
use App\Models\Announcement;
use App\Models\AnnouncementBookmark;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\AnnouncementImageService;
use App\Services\EnhancedAnnouncementService;
use App\Services\FirebaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AnnouncementController extends Controller
{
    protected AuditLogService $auditLogService;
    protected AnnouncementImageService $imageService;
    protected EnhancedAnnouncementService $enhancedService;
    protected FirebaseService $firebaseService;

    public function __construct(
        AuditLogService $auditLogService, 
        AnnouncementImageService $imageService,
        EnhancedAnnouncementService $enhancedService,
        FirebaseService $firebaseService
    ) {
        $this->auditLogService = $auditLogService;
        $this->imageService = $imageService;
        $this->enhancedService = $enhancedService;
        $this->firebaseService = $firebaseService;
    }

    /**
     * Get announcements list
     */
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();

        try {
            $query = Announcement::with([
                'author:id,name,role,department_id',
                'author.department:id,name',
                'targetDepartments:id,name'
            ]);

            // Filter by status (default to published for students)
            $status = $request->input('status', $user->role === 'student' ? 'published' : 'all');
            if ($status !== 'all') {
                $query->where('status', $status);
            }

            // All announcements are now image-only, so no type filtering needed
            // if ($request->filled('type')) {
            //     $query->where('type', $request->input('type'));
            // }

            // Filter by priority
            if ($request->filled('priority')) {
                $query->where('priority', $request->input('priority'));
            }

            // Only show published announcements for students
            if ($user->role === 'student') {
                $query->where('status', 'published')
                    ->where('published_at', '<=', now())
                    ->where(function ($q) {
                        $q->whereNull('expires_at')
                            ->orWhere('expires_at', '>', now());
                    });
            }

            // Filter by target departments if applicable
            if ($user->role === 'student' && $user->department_id) {
                $query->where(function ($q) use ($user) {
                    $q->whereHas('targetDepartments', function ($dq) use ($user) {
                        $dq->where('department_id', $user->department_id);
                    })->orWhereDoesntHave('targetDepartments'); // Global announcements
                });
            }

            $announcements = $query
                ->orderBy('published_at', 'desc')
                ->paginate($request->input('per_page', 20));

            // Add bookmark status for each announcement
            $announcementIds = $announcements->pluck('id');
            $bookmarkedIds = AnnouncementBookmark::where('user_id', $user->id)
                ->whereIn('announcement_id', $announcementIds)
                ->pluck('announcement_id')
                ->toArray();

            $announcements->getCollection()->transform(function ($announcement) use ($bookmarkedIds) {
                $announcement->is_bookmarked = in_array($announcement->id, $bookmarkedIds);
                
                // Add department information
                $departmentName = 'General';
                if ($announcement->author && $announcement->author->department) {
                    $departmentName = $announcement->author->department->name;
                } elseif ($announcement->targetDepartments && $announcement->targetDepartments->isNotEmpty()) {
                    $departmentName = $announcement->targetDepartments->first()->name;
                }
                $announcement->department = $departmentName;
                
                return $announcement;
            });

            return response()->json([
                'success' => true,
                'data' => $announcements->items(),
                'pagination' => [
                    'current_page' => $announcements->currentPage(),
                    'last_page' => $announcements->lastPage(),
                    'per_page' => $announcements->perPage(),
                    'total' => $announcements->total(),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch announcements',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Create new image-only announcement
     */
    public function store(StoreAnnouncementRequest $request): JsonResponse
    {
        $user = auth()->user();

        try {
            $data = $request->validated();
            $data['author_id'] = $user->id;

            // Image is required for all announcements
            if (!$request->hasFile('image')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Image is required for announcements',
                ], 422);
            }

            // Handle image upload
            $imageData = $this->imageService->uploadImage($request->file('image'));
            $data = array_merge($data, [
                'image_path' => $imageData['path'],
                'image_filename' => $imageData['filename'],
                'image_mime_type' => $imageData['mime_type'],
                'image_size' => $imageData['size'],
                'image_width' => $imageData['width'],
                'image_height' => $imageData['height'],
            ]);

            // Set default status if not provided
            if (!isset($data['status'])) {
                $data['status'] = 'draft';
            }

            // Set published_at if status is published and not already set
            if ($data['status'] === 'published' && !isset($data['published_at'])) {
                $data['published_at'] = now();
            }

            // Set announcement_timestamp if not provided
            if (!isset($data['announcement_timestamp'])) {
                $data['announcement_timestamp'] = now();
            }

            // Only set expiration date if explicitly provided
            // No default expiration date - announcements should only expire if admin sets it

            $announcement = Announcement::create($data);

            // Attach target departments if specified
            if (isset($data['target_departments']) && is_array($data['target_departments'])) {
                $announcement->targetDepartments()->sync($data['target_departments']);
            }

            // Log the creation
            $this->auditLogService->logCrud('create', 'announcement', $announcement->id, null, [
                'image_filename' => $announcement->image_filename,
                'status' => $announcement->status,
            ]);

            // Send push notifications if announcement is published
            if ($announcement->status === 'published') {
                $this->sendAnnouncementNotifications($announcement);
                
                // Log successful publication
                \Log::info('Announcement published successfully', [
                    'announcement_id' => $announcement->id,
                    'title' => $announcement->title,
                    'status' => $announcement->status,
                    'author_id' => $announcement->author_id,
                ]);
            }

            $announcement = $announcement->load(['author:id,name,role', 'targetDepartments']);
            
            return response()->json([
                'success' => true,
                'message' => $announcement->status === 'published' 
                    ? 'Announcement published successfully and notifications sent'
                    : 'Announcement created successfully',
                'data' => $announcement,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create announcement',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get specific announcement
     */
    public function show(Announcement $announcement): JsonResponse
    {
        $user = auth()->user();

        try {
            // Check if user can view this announcement
            if ($user->role === 'student' && $announcement->status !== 'published') {
                return response()->json([
                    'success' => false,
                    'message' => 'Announcement not found',
                ], 404);
            }

            $announcement->load(['author', 'targetDepartments']);
            
            // Check if bookmarked
            $announcement->is_bookmarked = AnnouncementBookmark::where('user_id', $user->id)
                ->where('announcement_id', $announcement->id)
                ->exists();

            // Increment view count
            $announcement->increment('view_count');
            
            // Transform author to only include necessary fields
            if ($announcement->author) {
                $announcement->author = [
                    'id' => $announcement->author->id,
                    'name' => $announcement->author->name,
                    'role' => $announcement->author->role,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $announcement,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch announcement',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Update announcement
     */
    public function update(UpdateAnnouncementRequest $request, Announcement $announcement): JsonResponse
    {
        $user = auth()->user();

        try {
            $data = $request->validated();
            
            // Debug: Log the request data
            \Log::info('Update announcement request:', [
                'id' => $announcement->id,
                'has_file' => $request->hasFile('image'),
                'file_name' => $request->hasFile('image') ? $request->file('image')->getClientOriginalName() : null,
                'data' => $data,
            ]);

            // Handle image upload if present
            if ($request->hasFile('image')) {
                // Delete old image if exists
                if ($announcement->image_path) {
                    $this->imageService->deleteImage($announcement->image_path);
                }

                $imageData = $this->imageService->uploadImage($request->file('image'));
                $data = array_merge($data, [
                    'image_path' => $imageData['path'],
                    'image_filename' => $imageData['filename'],
                    'image_mime_type' => $imageData['mime_type'],
                    'image_size' => $imageData['size'],
                    'image_width' => $imageData['width'],
                    'image_height' => $imageData['height'],
                ]);
            } elseif (isset($data['remove_image']) && $data['remove_image']) {
                // Remove image (but keep as image announcement - just remove the image)
                if ($announcement->image_path) {
                    $this->imageService->deleteImage($announcement->image_path);
                }
                $data = array_merge($data, [
                    'image_path' => null,
                    'image_filename' => null,
                    'image_mime_type' => null,
                    'image_size' => null,
                    'image_width' => null,
                    'image_height' => null,
                ]);
            }

            // Set published_at if status is being changed to published
            if ($data['status'] === 'published' && $announcement->status !== 'published' && !isset($data['published_at'])) {
                $data['published_at'] = now();
            }

            $oldData = $announcement->toArray();
            $announcement->update($data);

            // Update target departments if specified
            if (isset($data['target_departments']) && is_array($data['target_departments'])) {
                $announcement->targetDepartments()->sync($data['target_departments']);
            }

            // Log the update
            $this->auditLogService->logCrud('update', 'announcement', $announcement->id, $oldData, [
                'internal_title' => $announcement->internal_title,
                'image_filename' => $announcement->image_filename,
                'changes' => array_keys($data),
            ]);

            $announcement = $announcement->load(['author:id,name,role', 'targetDepartments']);
            
            // Debug: Log the updated announcement data
            \Log::info('Updated announcement data:', [
                'id' => $announcement->id,
                'image_path' => $announcement->image_path,
                'image_url' => $announcement->image_url,
                'image_filename' => $announcement->image_filename,
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Announcement updated successfully',
                'data' => $announcement,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update announcement',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Delete announcement
     */
    public function destroy(Announcement $announcement): JsonResponse
    {
        $user = auth()->user();

        // Check authorization
        if ($user->role === 'admin') {
            // Admin can delete any announcement
        } elseif ($user->role === 'department_head' && $announcement->author_id === $user->id) {
            // Department head can only delete their own announcements
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to delete this announcement',
            ], 403);
        }

        try {
            $announcementData = $announcement->toArray();
            
            // Delete associated image if exists
            if ($announcement->image_path) {
                $this->imageService->deleteImage($announcement->image_path);
            }
            
            $announcement->delete();

            // Log the deletion
            $this->auditLogService->logCrud('delete', 'announcement', $announcement->id, $announcementData, null, [
                'internal_title' => $announcementData['internal_title'],
                'image_filename' => $announcementData['image_filename'],
                'id' => $announcementData['id'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Announcement deleted successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete announcement',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Bookmark announcement
     */
    public function bookmark(Announcement $announcement): JsonResponse
    {
        $user = auth()->user();

        try {
            $bookmark = AnnouncementBookmark::firstOrCreate([
                'user_id' => $user->id,
                'announcement_id' => $announcement->id,
            ]);

            if ($bookmark->wasRecentlyCreated) {
                $announcement->increment('bookmark_count');
            }

            return response()->json([
                'success' => true,
                'message' => 'Announcement bookmarked successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to bookmark announcement',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Remove bookmark
     */
    public function removeBookmark(Announcement $announcement): JsonResponse
    {
        $user = auth()->user();

        try {
            $deleted = AnnouncementBookmark::where('user_id', $user->id)
                ->where('announcement_id', $announcement->id)
                ->delete();

            if ($deleted) {
                $announcement->decrement('bookmark_count');
            }

            return response()->json([
                'success' => true,
                'message' => 'Bookmark removed successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove bookmark',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get user's bookmarked announcements
     */
    public function getBookmarks(Request $request): JsonResponse
    {
        $user = auth()->user();

        try {
            $bookmarks = AnnouncementBookmark::where('user_id', $user->id)
                ->with(['announcement.author'])
                ->orderBy('created_at', 'desc')
                ->paginate($request->input('per_page', 20));

            $announcements = $bookmarks->getCollection()->map(function ($bookmark) {
                $announcement = $bookmark->announcement;
                $announcement->is_bookmarked = true;
                $announcement->bookmarked_at = $bookmark->created_at;
                return $announcement;
            });

            return response()->json([
                'success' => true,
                'data' => $announcements,
                'pagination' => [
                    'current_page' => $bookmarks->currentPage(),
                    'last_page' => $bookmarks->lastPage(),
                    'per_page' => $bookmarks->perPage(),
                    'total' => $bookmarks->total(),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch bookmarked announcements',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Download announcement image
     */
    public function downloadImage(Announcement $announcement): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        if (!$announcement->isImageAnnouncement() || !$announcement->image_path) {
            abort(404, 'Image not found');
        }

        // Track download analytics
        $this->enhancedService->trackAnalytics(
            $announcement->id,
            'download',
            auth()->id(),
            [
                'device_type' => $this->getDeviceType(),
                'user_agent' => request()->userAgent(),
                'ip_address' => request()->ip(),
            ]
        );

        return $this->imageService->downloadImage(
            $announcement->image_path,
            $announcement->image_filename
        );
    }

    /**
     * Track announcement view
     */
    public function trackView(Announcement $announcement): JsonResponse
    {
        $this->enhancedService->trackAnalytics(
            $announcement->id,
            'view',
            auth()->id(),
            [
                'device_type' => $this->getDeviceType(),
                'user_agent' => request()->userAgent(),
                'ip_address' => request()->ip(),
            ]
        );

        return response()->json(['success' => true]);
    }

    /**
     * Track announcement share
     */
    public function trackShare(Announcement $announcement): JsonResponse
    {
        $this->enhancedService->trackAnalytics(
            $announcement->id,
            'share',
            auth()->id(),
            [
                'device_type' => $this->getDeviceType(),
                'user_agent' => request()->userAgent(),
                'ip_address' => request()->ip(),
            ]
        );

        return response()->json(['success' => true]);
    }

    /**
     * Get announcement analytics
     */
    public function getAnalytics(Announcement $announcement, Request $request): JsonResponse
    {
        $user = auth()->user();
        
        // Check if user has permission to view analytics
        if (!in_array($user->role, ['admin', 'department_head'])) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to view analytics'
            ], 403);
        }

        $period = $request->input('period', '30d');
        $analytics = $this->enhancedService->getAnalytics($announcement->id, $period);

        return response()->json([
            'success' => true,
            'data' => $analytics
        ]);
    }

    /**
     * Create image-only announcement
     */
    public function createImageAnnouncement(Request $request): JsonResponse
    {
        $user = auth()->user();
        
        // Check if user has permission to create announcements
        if (!in_array($user->role, ['admin', 'department_head'])) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to create announcements'
            ], 403);
        }

        $request->validate([
            'title' => 'required|string|max:255',
            'type' => 'required|string|in:general,academic,emergency,event',
            'priority' => 'required|string|in:low,medium,high,urgent',
            'status' => 'required|string|in:draft,published,scheduled',
            'image' => 'required|image|mimes:jpeg,png,webp|max:10240', // 10MB max
            'target_departments' => 'sometimes|array',
            'target_departments.*' => 'exists:departments,id',
            'scheduled_at' => 'sometimes|date|after:now',
            'expires_at' => 'sometimes|date|after:now',
        ]);

        $result = $this->enhancedService->createImageAnnouncement(
            $request->all(),
            $request->file('image'),
            $user->id
        );

        return response()->json($result, $result['success'] ? 201 : 400);
    }

    /**
     * Bulk upload announcements
     */
    public function bulkUpload(Request $request): JsonResponse
    {
        $user = auth()->user();
        
        // Check if user has permission
        if (!in_array($user->role, ['admin', 'department_head'])) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to bulk upload announcements'
            ], 403);
        }

        $request->validate([
            'announcements' => 'required|array|min:1|max:50',
            'announcements.*.title' => 'required|string|max:255',
            'announcements.*.type' => 'required|string|in:general,academic,emergency,event',
            'announcements.*.priority' => 'required|string|in:low,medium,high,urgent',
            'announcements.*.image' => 'required|image|mimes:jpeg,png,webp|max:10240',
            'scheduled_at' => 'required|date|after:now',
        ]);

        $result = $this->enhancedService->scheduleBulkAnnouncements(
            $request->input('announcements'),
            new \DateTime($request->input('scheduled_at')),
            $user->id
        );

        return response()->json($result, $result['success'] ? 201 : 400);
    }

    /**
     * Moderate announcement
     */
    public function moderate(Announcement $announcement, Request $request): JsonResponse
    {
        $user = auth()->user();
        
        // Only admins can moderate
        if ($user->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Only administrators can moderate announcements'
            ], 403);
        }

        $request->validate([
            'status' => 'required|string|in:approved,rejected',
            'notes' => 'sometimes|string|max:1000',
        ]);

        $result = $this->enhancedService->moderateAnnouncement(
            $announcement->id,
            $request->input('status'),
            $request->input('notes'),
            $user->id
        );

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Get device type from user agent
     */
    private function getDeviceType(): string
    {
        $userAgent = request()->userAgent();
        
        if (preg_match('/Mobile|Android|iPhone|iPad/', $userAgent)) {
            return 'mobile';
        } elseif (preg_match('/Tablet|iPad/', $userAgent)) {
            return 'tablet';
        } else {
            return 'web';
        }
    }

    /**
     * Get available announcement categories
     */
    public function getCategories(): JsonResponse
    {
        try {
            $categories = Announcement::getAvailableCategories();
            
            return response()->json([
                'success' => true,
                'data' => $categories,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch categories',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Send push notifications for new announcement
     */
    private function sendAnnouncementNotifications(Announcement $announcement): void
    {
        try {
            // Get target user IDs based on announcement scope
            $userIds = $this->getTargetUserIds($announcement);
            
            if (empty($userIds)) {
                \Log::info('No target users found for announcement notification', [
                    'announcement_id' => $announcement->id,
                ]);
                return;
            }

            // Prepare notification data
            $notificationTitle = 'New Announcement';
            $notificationBody = $announcement->title ?: 'A new announcement has been published';
            
            // Create database notifications for each target user
            $databaseNotifications = [];
            foreach ($userIds as $userId) {
                $databaseNotifications[] = [
                    'user_id' => $userId,
                    'type' => 'announcement',
                    'title' => $notificationTitle,
                    'message' => $notificationBody,
                    'data' => [
                        'announcement_id' => $announcement->id,
                        'announcement_title' => $announcement->title,
                        'author_name' => $announcement->author->name ?? 'System',
                        'department' => $announcement->author->department->name ?? 'General',
                        'priority' => $announcement->priority,
                        'type' => $announcement->type,
                    ],
                    'priority' => $announcement->priority,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            // Bulk insert database notifications
            \App\Models\Notification::insert($databaseNotifications);
            
            // Send push notifications
            $result = $this->firebaseService->sendAnnouncementNotification(
                $userIds,
                $announcement->id,
                $notificationTitle,
                $notificationBody
            );

            \Log::info('Announcement notifications sent', [
                'announcement_id' => $announcement->id,
                'title' => $announcement->title,
                'target_users' => count($userIds),
                'database_notifications' => count($databaseNotifications),
                'notification_title' => $notificationTitle,
                'notification_body' => $notificationBody,
                'result' => $result,
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to send announcement notifications', [
                'announcement_id' => $announcement->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // Don't let notification failure prevent announcement creation
            // Just log the error and continue
        }
    }

    /**
     * Get target user IDs for announcement notifications
     */
    private function getTargetUserIds(Announcement $announcement): array
    {
        $query = User::where('is_active', true);

        // If announcement has target departments, filter by those
        if ($announcement->targetDepartments()->exists()) {
            $departmentIds = $announcement->targetDepartments()->pluck('departments.id')->toArray();
            $query->whereIn('department_id', $departmentIds);
        }

        // Exclude the author from notifications
        $query->where('id', '!=', $announcement->author_id);

        return $query->pluck('id')->toArray();
    }
}
