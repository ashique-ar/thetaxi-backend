<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\UUID;

/**
 * Driving License Model
 * 
 * Represents driving licenses held by users/drivers. Stores license details including
 * validity dates, classes, restrictions, and issuing authority information.
 * 
 * @property string $id Primary key (UUID)
 * @property string $user_id Foreign key to users table
 * @property string $license_number License number identifier
 * @property string|null $license_type Foreign key to driving license types table
 * @property \Carbon\Carbon $issue_date Date when license was issued
 * @property \Carbon\Carbon $expiry_date Date when license expires
 * @property string|null $issuing_authority Authority that issued the license
 * @property string|null $license_class License class (A, B, C, etc.)
 * @property string|null $restrictions License restrictions text
 * @property string|null $endorsements License endorsements text
 * @property string|null $country Country code where license was issued
 * @property string|null $state State/province where license was issued
 * @property string|null $document_path Path to license document/image
 * @property string $status License status (active, expired, suspended)
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 * @property \Carbon\Carbon|null $created_at Record creation timestamp
 * @property \Carbon\Carbon|null $updated_at Record last update timestamp
 * @property \Carbon\Carbon|null $deleted_at Soft deletion timestamp
 * 
 * @property-read User $user User who holds this license
 * @property-read DrivingLicenseType|null $licenseType Type of driving license
 * @property-read User|null $createdBy User who created this record
 * @property-read User|null $updatedBy User who last updated this record
 */
class DrivingLicense extends BaseModel
{
    

    /**
     * The table associated with the model.
     */
    protected $table = 'driving_licenses';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'user_id',
        'license_number',
        'license_type',
        'issue_date',
        'expiry_date',
        'issuing_authority',
        'license_class',
        'restrictions',
        'endorsements',
        'country',
        'state',
        'document_path',
        'status',
        'created_user_id',
        'updated_user_id',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'issue_date' => 'date',
        'expiry_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the user who holds this license.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the type of driving license.
     */
    public function licenseType(): BelongsTo
    {
        return $this->belongsTo(DrivingLicenseType::class, 'license_type');
    }

    /**
     * Get the user who created this record.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_user_id');
    }

    /**
     * Get the user who last updated this record.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_user_id');
    }

    /**
     * Check if the license is expired.
     */
    public function isExpired(): bool
    {
        return $this->expiry_date < now();
    }

    /**
     * Check if the license is active and not expired.
     */
    public function isValid(): bool
    {
        return $this->status === 'active' && !$this->isExpired();
    }
}
