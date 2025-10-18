<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Facility extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'location',
        'type',
        'capacity',
        'is_active',
        'contact_info',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'contact_info' => 'array',
    ];

    // Relationships
    public function concerns()
    {
        return $this->hasMany(Concern::class);
    }
}
