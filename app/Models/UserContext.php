<?php

namespace App\Models;

use App\Traits\UUID;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Permission\Models\Role;

/**
 * User Context Model
 * 
 * Manages user role contexts for multi-role scenarios
 * e.g., A staff member can also rent cars as a customer
 */
class UserContext extends Model
{
    use UUID, SoftDeletes;

    protected $fillable = [
        'user_id',
        'context_type', // 'customer', 'vehicle_owner', 'staff', 'agent'
        'context_id',   // ID of the related model (customer_id, vehicle_owner_id, etc.)
        'is_active',
        'created_user_id',
        'updated_user_id'
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function context()
    {
        return $this->morphTo();
    }

    /**
     * Roles assigned to this context (via admin or automatic mapping)
     */
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_context_roles', 'user_context_id', 'role_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForContext($query, $contextType)
    {
        return $query->where('context_type', $contextType);
    }
}
