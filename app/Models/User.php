<?php

namespace App\Models;

use App\Traits\UUID;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\HasApiTokens;
use QCod\Gamify\Gamify;
use Ramsey\Uuid\DeprecatedUuidMethodsTrait;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

/**
 * App\Models\User
 *
 * @property string $id Primary key (UUID)
 * @property string $email User's email address (unique)
 * @property string $password User's encrypted password
 * @property string $first_name User's first name
 * @property string|null $last_name User's last name (optional)
 * @property string|null $phone User's phone number (optional)
 * @property string|null $profile_image User's profile image path (optional)
 * @property string $role_id Foreign key to roles table
 * @property string|null $agent_id Foreign key to agents table (optional)
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 *
 * @property-read \Spatie\Permission\Models\Role|null $role User's role
 * @property-read \App\Models\Agent\Agent|null $agent Associated agent (if applicable)
 * @property-read \App\Models\User|null $createdBy User who created this record
 * @property-read \App\Models\User|null $updatedBy User who last updated this record
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Booking\Booking[] $bookings User's bookings
 * @property-read \Illuminate\Database\Eloquent\Collection|\Spatie\Permission\Models\Permission[] $permissions
 * @property-read \Illuminate\Database\Eloquent\Collection|\Spatie\Permission\Models\Role[] $roles
 */
class User extends Authenticatable
{
    use HasApiTokens,
        SoftDeletes,
        HasFactory,
        HasRoles,
        Notifiable,
        Gamify,
        DeprecatedUuidMethodsTrait,
        UUID,
        LogsActivity;

    /**
     * The guard name to use for permissions and roles
     */
    protected $guard_name = 'api';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()->useLogName(class_basename($this));
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'email',
        'password',
        'first_name',
        'last_name',
        'phone',
        'profile_image',
        'role_id',
        'agent_id',
        'created_user_id',
        'updated_user_id',
        'email_verified_at',
        'phone_verified_at',
        'is_active',
        'last_login_at',
        'password_changed_at',
        'two_factor_enabled',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'device_token',
        'timezone',
        'language',
        'status',
        'login_attempts',
        'locked_until'
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'device_token'
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'phone_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'password_changed_at' => 'datetime',
        'locked_until' => 'datetime',
        'is_active' => 'boolean',
        'two_factor_enabled' => 'boolean',
        'two_factor_recovery_codes' => 'array',
        'login_attempts' => 'integer',
    ];

    /**
     * Get the user's role.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function role()
    {
        return $this->belongsTo(\Spatie\Permission\Models\Role::class);
    }

    /**
     * Get the agent associated with this user.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function agent()
    {
        return $this->belongsTo(\App\Models\Agent\Agent::class);
    }

    /**
     * Get the user who created this record.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_user_id');
    }

    /**
     * Get the user who last updated this record.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_user_id');
    }

    /**
     * Get all bookings made by this user.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function bookings()
    {
        return $this->hasMany(\App\Models\Booking\Booking::class);
    }

    // Authentication Methods

    /**
     * Check if user account is active
     *
     * @return bool
     */
    public function isActive()
    {
        return $this->is_active;
    }

    /**
     * Check if user account is locked
     *
     * @return bool
     */
    public function isLocked()
    {
        return $this->locked_until && $this->locked_until->isFuture();
    }

    /**
     * Check if user has verified email
     *
     * @return bool
     */
    public function hasVerifiedEmail()
    {
        return !is_null($this->email_verified_at);
    }

    /**
     * Check if user has verified phone
     *
     * @return bool
     */
    public function hasVerifiedPhone()
    {
        return !is_null($this->phone_verified_at);
    }

    /**
     * Check if two-factor authentication is enabled
     *
     * @return bool
     */
    public function hasTwoFactorEnabled()
    {
        return $this->two_factor_enabled;
    }

    /**
     * Increment login attempts
     *
     * @return void
     */
    public function incrementLoginAttempts()
    {
        $this->increment('login_attempts');
    }

    /**
     * Reset login attempts
     *
     * @return void
     */
    public function resetLoginAttempts()
    {
        $this->update(['login_attempts' => 0]);
    }

    /**
     * Lock user account
     *
     * @param int $minutes
     * @return void
     */
    public function lockAccount($minutes = 30)
    {
        $this->update(['locked_until' => now()->addMinutes($minutes)]);
    }

    /**
     * Update last login timestamp
     *
     * @return void
     */
    public function updateLastLogin()
    {
        $this->update(['last_login_at' => now()]);
    }

    /**
     * Get the user's full name
     *
     * @return string
     */
    public function getFullNameAttribute()
    {
        return $this->first_name . ' ' . $this->last_name;
    }

    /**
     * Get user's avatar URL
     *
     * @return string
     */
    public function getAvatarUrlAttribute()
    {
        if ($this->profile_image) {
            return url('storage/' . $this->profile_image);
        }
        return 'https://ui-avatars.com/api/?name=' . urlencode($this->full_name) . '&background=random';
    }

    /**
     * Check if user has specific permission
     *
     * @param string $permission
     * @return bool
     */
    public function hasPermission($permission)
    {
        return $this->can($permission);
    }

    /**
     * Check if user has any of the given permissions
     *
     * @param array $permissions
     * @return bool
     */
    public function hasAnyPermission(array $permissions)
    {
        return $this->hasAnyDirectPermission($permissions) || $this->hasAnyRolePermission($permissions);
    }

    /**
     * Check if user has any role permission
     *
     * @param array $permissions
     * @return bool
     */
    protected function hasAnyRolePermission(array $permissions)
    {
        return $this->roles()->whereHas('permissions', function ($query) use ($permissions) {
            $query->whereIn('name', $permissions);
        })->exists();
    }

    /**
     * Get user's permissions array
     *
     * @return array
     */
    public function getPermissionsArray()
    {
        $directPermissions = DB::table('model_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'model_has_permissions.permission_id')
            ->where('model_has_permissions.model_type', self::class)
            ->where('model_has_permissions.model_id', $this->id)
            ->pluck('permissions.name');

        $rolePermissions = DB::table('model_has_roles')
            ->join('role_has_permissions', 'role_has_permissions.role_id', '=', 'model_has_roles.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('model_has_roles.model_type', self::class)
            ->where('model_has_roles.model_id', $this->id)
            ->pluck('permissions.name');

        return $directPermissions->merge($rolePermissions)->unique()->values()->toArray();
    }

    /**
     * Get user's roles array
     *
     * @return array
     */
    public function getRolesArray()
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', self::class)
            ->where('model_has_roles.model_id', $this->id)
            ->pluck('roles.name')
            ->unique()
            ->values()
            ->toArray();
    }

    /**
     * Revoke all tokens for this user
     *
     * @return void
     */
    public function revokeAllTokens()
    {
        $this->tokens()->delete();
    }

    /**
     * Generate two-factor recovery codes
     *
     * @return array
     */
    public function generateTwoFactorRecoveryCodes()
    {
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = strtoupper(Str::random(8));
        }
        return $codes;
    }

    /**
     * Enable two-factor authentication
     *
     * @param string $secret
     * @return void
     */
    public function enableTwoFactor($secret)
    {
        $this->update([
            'two_factor_enabled' => true,
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => $this->generateTwoFactorRecoveryCodes()
        ]);
    }

    /**
     * Disable two-factor authentication
     *
     * @return void
     */
    public function disableTwoFactor()
    {
        $this->update([
            'two_factor_enabled' => false,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null
        ]);
    }

    /**
     * Get the guard name for the user model
     * This allows the model to work with both web and api guards
     */
    public function getGuardNames()
    {
        return collect(['web', 'api']);
    }

    /**
     * Get the default guard name for permissions
     */
    public function guardName(): string
    {
        return 'api'; // Use api guard for API authentication
    }

    // ===== MULTI-CONTEXT METHODS =====

    /**
     * Get all user contexts
     */
    public function contexts()
    {
        return $this->hasMany(\App\Models\UserContext::class);
    }

    /**
     * Get the customer profile linked to this user.
     */
    public function customer()
    {
        return $this->hasOne(\App\Models\Customer::class);
    }

    /**
     * Get customer context if exists
     */
    public function customerContext()
    {
        return $this->contexts()->where('context_type', 'customer')->where('is_active', true)->first();
    }

    /**
     * Get driver context if exists
     */
    public function driverContext()
    {
        return $this->contexts()->where('context_type', 'driver')->where('is_active', true)->first();
    }

    /**
     * Get vehicle owner context if exists
     */
    public function vehicleOwnerContext()
    {
        return $this->contexts()->where('context_type', 'vehicle_owner')->where('is_active', true)->first();
    }

    /**
     * Get staff context if exists
     */
    public function staffContext()
    {
        return $this->contexts()->where('context_type', 'staff')->where('is_active', true)->first();
    }

    /**
     * Check if user can act as customer
     */
    public function canActAsCustomer(): bool
    {
        return $this->customerContext() !== null || $this->hasRole(['customer', 'staff', 'admin']);
    }

    /**
     * Check if user can act as vehicle owner
     */
    public function canActAsVehicleOwner(): bool
    {
        return $this->vehicleOwnerContext() !== null || $this->hasRole(['admin']);
    }

    public function canActAsDriver(): bool
    {
        return $this->driverContext() !== null || $this->hasRole(['admin']);
    }


    /**
     * Switch to customer context (create if doesn't exist)
     */
    public function switchToCustomerContext()
    {
        if (!$this->customerContext()) {
            // Create customer record if user doesn't have one
            $customer = \App\Models\Customer::create([
                'user_id' => $this->id,
                'created_user_id' => $this->id
            ]);

            // Create context
            \App\Models\UserContext::create([
                'user_id' => $this->id,
                'context_type' => 'customer',
                'context_id' => $customer->id,
                'is_active' => true,
                'created_user_id' => $this->id
            ]);
        }

        return $this->customerContext();
    }

    /**
     * Get current active contexts
     */
    public function getActiveContexts()
    {
        return $this->contexts()->active()->with('context')->get();
    }

    /**
     * Check if user has multiple active contexts
     */
    public function hasMultipleContexts(): bool
    {
        return $this->contexts()->active()->count() > 1;
    }
}
