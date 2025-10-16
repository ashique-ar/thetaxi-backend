<?php

namespace App\Models;

use App\Traits\UUID;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Side Menu Model
 * 
 * Represents menu items in the application's sidebar navigation. Supports hierarchical
 * menu structure with parent-child relationships and permission-based access control.
 * 
 * @property string $id Primary key (UUID)
 * @property string $title Menu item title
 * @property string|null $icon Icon class or name for the menu item
 * @property string|null $description Description of the menu item
 * @property string|null $route_name Laravel route name for navigation
 * @property string|null $permission_name Permission required to view this menu item
 * @property string|null $crud_master CRUD master entity name
 * @property string|null $parent_id Foreign key to parent menu item
 * @property int $sort Sort order for menu display
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 * @property \Carbon\Carbon|null $created_at Record creation timestamp
 * @property \Carbon\Carbon|null $updated_at Record last update timestamp
 * @property \Carbon\Carbon|null $deleted_at Soft deletion timestamp
 * 
 * @property-read SideMenu|null $parent Parent menu item
 * @property-read \Illuminate\Database\Eloquent\Collection<SideMenu> $children Child menu items
 * @property-read User|null $createdBy User who created this record
 * @property-read User|null $updatedBy User who last updated this record
 */
class SideMenu extends BaseModel
{
    
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'title',
        'icon',
        'description',
        'route_name',
        'permission_name',
        'crud_master',
        'parent_id',
        'priority',
        'sort',
        'created_user_id',
        'updated_user_id',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'sort' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the parent menu item.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(SideMenu::class, 'parent_id');
    }

    /**
     * Get all child menu items.
     */
    public function children(): HasMany
    {
        return $this->hasMany(SideMenu::class, 'parent_id')->orderBy('sort');
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
}
