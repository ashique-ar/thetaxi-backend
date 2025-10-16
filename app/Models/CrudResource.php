<?php

namespace App\Models;

use App\Traits\UUID;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CRUD Resource Model
 * 
 * Represents CRUD resource configurations that were previously stored in config/crud.php.
 * This allows for dynamic management of CRUD resources through admin interface.
 * 
 * @property string $id Primary key (UUID)
 * @property string $resource_name Unique resource name (e.g., 'countries', 'customers')
 * @property string $title Human-readable title for the resource
 * @property string $model_class Full class name of the Eloquent model
 * @property string|null $description Description of the resource
 * @property array $fields Field configuration (validation, types, etc.)
 * @property array|null $relationships Relationship definitions
 * @property array|null $permissions Permission requirements for CRUD operations
 * @property array|null $search_config Search and filter configuration
 * @property string|null $business_logic_class Optional business logic service class
 * @property array|null $ui_config UI-specific configuration (icons, colors, etc.)
 * @property bool $is_active Whether this resource is active
 * @property int $sort Sort order for display
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 * @property \Carbon\Carbon|null $created_at Record creation timestamp
 * @property \Carbon\Carbon|null $updated_at Record last update timestamp
 * @property \Carbon\Carbon|null $deleted_at Soft deletion timestamp
 * 
 * @property-read User|null $createdBy User who created this record
 * @property-read User|null $updatedBy User who last updated this record
 */
class CrudResource extends BaseModel
{
    

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'resource_name',
        'title',
        'model_class',
        'description',
        'fields',
        'hidden',
        'fillables',
        'relationships',
        'permissions',
        'search_config',
        'business_logic_class',
        'ui_config',
        'is_active',
        'sort',
        'created_user_id',
        'updated_user_id',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'fields' => 'array',
        'relationships' => 'array',
        'permissions' => 'array',
        'hidden'          => 'array', 
        'fillables'          => 'array', 
        'search_config' => 'array',
        'ui_config' => 'array',
        'is_active' => 'boolean',
        'sort' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

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
     * Scope to get only active resources.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to order by sort column.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort');
    }

    /**
     * Get the formatted configuration for use in CRUD operations.
     */
    public function getFormattedConfig(): array
    {
        return [
            'model' => $this->model_class,
            'title' => $this->title,
            'description' => $this->description,
            'fields' => $this->fields ?? [],
            'relationships' => $this->relationships ?? [],
            'hidden' => $this->hidden ?? [],
            'fillable' => $this->fillables ?? [],
            'permissions' => $this->permissions ?? [],
            'search' => $this->search_config ?? [],
            'business_logic' => $this->business_logic_class,
            'ui' => $this->ui_config ?? [],
        ];
    }

    /**
     * Validate that the model class exists and is valid.
     */
    public function validateModelClass(): bool
    {
        if (!$this->model_class) {
            return false;
        }

        if (!class_exists($this->model_class)) {
            return false;
        }

        // Check if it's an Eloquent model
        $reflection = new \ReflectionClass($this->model_class);
        return $reflection->isSubclassOf(\Illuminate\Database\Eloquent\Model::class);
    }

    /**
     * Validate that the business logic class exists and is valid.
     */
    public function validateBusinessLogicClass(): bool
    {
        if (!$this->business_logic_class) {
            return true; // Optional field
        }

        return class_exists($this->business_logic_class);
    }
}
