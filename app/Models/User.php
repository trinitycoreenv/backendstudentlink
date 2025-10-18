<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasApiTokens, HasFactory, Notifiable;
    
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'users';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'student_id',
        'employee_id',
        'name',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'email',
        'personal_email',
        'password',
        'role',
        'department_id',
        'course',
        'year_level',
        'phone',
        'birthday',
        'civil_status',
        'avatar',
        'preferences',
        'is_active',
        'can_handle_cross_department',
        'title',
        'last_login_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'preferences' => 'json',
        'is_active' => 'boolean',
        'can_handle_cross_department' => 'boolean',
        'last_login_at' => 'datetime',
        'birthday' => 'date',
    ];

    /**
     * Get the identifier that will be stored in the subject claim of the JWT.
     *
     * @return mixed
     */
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * Return a key value array, containing any custom claims to be added to the JWT.
     *
     * @return array
     */
    public function getJWTCustomClaims()
    {
        return [
            'role' => $this->role,
            'department_id' => $this->department_id,
        ];
    }

    /**
     * Get the department that the user belongs to.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }


    /**
     * Get the concerns submitted by the user (students only).
     */
    public function concerns(): HasMany
    {
        return $this->hasMany(Concern::class, 'student_id');
    }

    /**
     * Get the concerns assigned to the user (department head).
     */
    public function assignedConcerns(): HasMany
    {
        return $this->hasMany(Concern::class, 'assigned_to');
    }

    /**
     * Get the messages authored by the user.
     */
    public function concernMessages(): HasMany
    {
        return $this->hasMany(ConcernMessage::class, 'author_id');
    }

    /**
     * Get the announcements created by the user.
     */
    public function announcements(): HasMany
    {
        return $this->hasMany(Announcement::class, 'author_id');
    }

    /**
     * Get the announcement bookmarks for the user.
     */
    public function announcementBookmarks(): HasMany
    {
        return $this->hasMany(AnnouncementBookmark::class);
    }

    /**
     * Get the notifications for the user.
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    /**
     * Get the FCM tokens for the user.
     */
    public function fcmTokens(): HasMany
    {
        return $this->hasMany(FcmToken::class);
    }

    /**
     * Get the AI chat sessions for the user.
     */
    public function aiChatSessions(): HasMany
    {
        return $this->hasMany(AiChatSession::class);
    }

    /**
     * Get the audit logs for the user.
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    /**
     * Check if the user has a specific role.
     */
    public function hasRole(string $role): bool
    {
        return $this->role === $role;
    }

    /**
     * Check if the user has any of the specified roles.
     */
    public function hasAnyRole(array $roles): bool
    {
        return in_array($this->role, $roles);
    }

    /**
     * Check if the user is a student.
     */
    public function isStudent(): bool
    {
        return $this->role === 'student';
    }


    /**
     * Check if the user is a staff member.
     */
    public function isStaff(): bool
    {
        return $this->role === 'staff';
    }

    /**
     * Check if the user is a department head.
     */
    public function isDepartmentHead(): bool
    {
        return $this->role === 'department_head';
    }

    /**
     * Check if the user is an admin.
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Check if the user can handle concerns (staff, department head, or admin).
     */
    public function canHandleConcerns(): bool
    {
        return in_array($this->role, ['staff', 'department_head', 'admin']);
    }

    /**
     * Check if the user can manage concerns for a specific department.
     */
    public function canManageDepartmentConcerns(int $departmentId): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        if ($this->isDepartmentHead() && $this->department_id === $departmentId) {
            return true;
        }

        if ($this->isStaff() && $this->department_id === $departmentId) {
            return true;
        }

        return false;
    }

    /**
     * Get staff workload metrics.
     */
    public function getWorkloadMetrics(): array
    {
        if (!$this->canHandleConcerns()) {
            return [];
        }

        $assignedConcerns = $this->assignedConcerns()->get();
        $activeConcerns = $assignedConcerns->whereNull('archived_at');
        $archivedConcerns = $assignedConcerns->whereNotNull('archived_at');
        
        // Newly assigned concerns (assigned within last 24 hours)
        $newlyAssigned = $activeConcerns->where('created_at', '>=', now()->subDay())->count();
        
        // Total resolved (both active and archived)
        $totalResolved = $assignedConcerns->whereIn('status', ['resolved', 'closed'])->count();
        
        return [
            'total_assigned' => $activeConcerns->count(),
            'newly_assigned' => $newlyAssigned,
            'pending' => $activeConcerns->where('status', 'pending')->count(),
            'in_progress' => $activeConcerns->where('status', 'in_progress')->count(),
            'resolved' => $activeConcerns->where('status', 'resolved')->count(),
            'total_resolved' => $totalResolved,
            'overdue' => $activeConcerns->where('created_at', '<', now()->subDays(7))->count(),
            'archived' => $archivedConcerns->count(),
            'total_all_time' => $assignedConcerns->count(),
        ];
    }

    /**
     * Get the user's display identifier (student_id or employee_id).
     */
    public function getDisplayIdAttribute(): string
    {
        return $this->student_id ?? $this->employee_id ?? 'N/A';
    }

    /**
     * Get unread notifications count.
     */
    public function getUnreadNotificationsCountAttribute(): int
    {
        return $this->notifications()->whereNull('read_at')->count();
    }

    /**
     * Scope: Active users only.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: Users by role.
     */
    public function scopeByRole($query, string $role)
    {
        return $query->where('role', $role);
    }

    /**
     * Scope: Users by department.
     */
    public function scopeByDepartment($query, int $departmentId)
    {
        return $query->where('department_id', $departmentId);
    }
}
