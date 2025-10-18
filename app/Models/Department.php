<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'code',
        'description',
        'type',
        'is_active',
        'contact_info',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'contact_info' => 'json',
        'is_active' => 'boolean',
    ];

    /**
     * Get the users belonging to this department.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Get the concerns for this department.
     */
    public function concerns(): HasMany
    {
        return $this->hasMany(Concern::class);
    }


    /**
     * Get the active users in this department.
     */
    public function activeUsers(): HasMany
    {
        return $this->hasMany(User::class)->where('is_active', true);
    }

    /**
     * Get the faculty members in this department.
     */
    public function faculty(): HasMany
    {
        return $this->hasMany(User::class)->where('role', 'faculty');
    }

    /**
     * Get the staff members in this department.
     */
    public function staff(): HasMany
    {
        return $this->hasMany(User::class)->where('role', 'staff');
    }

    /**
     * Get all staff members (including department heads) who can handle concerns.
     */
    public function concernHandlers(): HasMany
    {
        return $this->hasMany(User::class)->whereIn('role', ['staff', 'department_head']);
    }

    /**
     * Get available staff for assignment (not overloaded).
     */
    public function getAvailableStaff(int $maxWorkload = 10): HasMany
    {
        return $this->staff()
            ->where('is_active', true)
            ->whereHas('assignedConcerns', function($query) {
                $query->whereIn('status', ['pending', 'in_progress']);
            }, '<', $maxWorkload);
    }

    /**
     * Get the department head.
     */
    public function departmentHead()
    {
        return $this->hasMany(User::class)->where('role', 'department_head')->first();
    }

    /**
     * Get the students in this department.
     */
    public function students(): HasMany
    {
        return $this->hasMany(User::class)->where('role', 'student');
    }

    /**
     * Scope: Active departments only.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: Academic departments only.
     */
    public function scopeAcademic($query)
    {
        return $query->where('type', 'academic');
    }

    /**
     * Scope: Administrative departments only.
     */
    public function scopeAdministrative($query)
    {
        return $query->where('type', 'administrative');
    }

    /**
     * Get the concerns count for this department.
     */
    public function getConcernsCountAttribute(): int
    {
        return $this->concerns()->count();
    }

    /**
     * Get the pending concerns count for this department.
     */
    public function getPendingConcernsCountAttribute(): int
    {
        return $this->concerns()->where('status', 'pending')->count();
    }

    /**
     * Get the resolved concerns count for this department.
     */
    public function getResolvedConcernsCountAttribute(): int
    {
        return $this->concerns()->where('status', 'resolved')->count();
    }
}
