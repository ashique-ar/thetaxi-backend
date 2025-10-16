<?php

namespace App\Models\Vehicle;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleAddonDependency extends BaseModel
{

    protected $fillable = [
        'parent_addon_id',
        'required_addon_id',
        'dependency_type',
        'description',
        'is_automatic',
        'conditions',
        'minimum_quantity',
        'maximum_quantity',
        'discount_percentage',
        'discount_amount',
        'is_active',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'conditions' => 'array',
        'is_automatic' => 'boolean',
        'is_active' => 'boolean',
        'minimum_quantity' => 'integer',
        'maximum_quantity' => 'integer',
        'discount_percentage' => 'decimal:2',
        'discount_amount' => 'decimal:2',
    ];

    /**
     * The parent addon that requires the dependency
     */
    public function parentAddon(): BelongsTo
    {
        return $this->belongsTo(VehicleAddon::class, 'parent_addon_id');
    }

    /**
     * The required dependency addon
     */
    public function requiredAddon(): BelongsTo
    {
        return $this->belongsTo(VehicleAddon::class, 'required_addon_id');
    }

    /**
     * User who created this record
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_user_id');
    }

    /**
     * User who last updated this record
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_user_id');
    }

    /**
     * Scope for active dependencies
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for required dependencies
     */
    public function scopeRequired($query)
    {
        return $query->where('dependency_type', 'required');
    }

    /**
     * Scope for automatic dependencies
     */
    public function scopeAutomatic($query)
    {
        return $query->where('is_automatic', true);
    }

    /**
     * Check if dependency applies to given conditions
     */
    public function appliesTo(array $bookingData): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if (!$this->conditions) {
            return true;
        }

        // Check each condition
        foreach ($this->conditions as $condition) {
            if (!$this->evaluateCondition($condition, $bookingData)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Evaluate a single condition
     */
    private function evaluateCondition(array $condition, array $bookingData): bool
    {
        $field = $condition['field'] ?? null;
        $operator = $condition['operator'] ?? 'equals';
        $value = $condition['value'] ?? null;

        if (!$field || !isset($bookingData[$field])) {
            return false;
        }

        $actualValue = $bookingData[$field];

        return match($operator) {
            'equals' => $actualValue == $value,
            'not_equals' => $actualValue != $value,
            'greater_than' => $actualValue > $value,
            'less_than' => $actualValue < $value,
            'greater_equal' => $actualValue >= $value,
            'less_equal' => $actualValue <= $value,
            'in' => in_array($actualValue, (array)$value),
            'not_in' => !in_array($actualValue, (array)$value),
            'contains' => str_contains($actualValue, $value),
            default => false,
        };
    }

    /**
     * Get discount amount for given base amount
     */
    public function getDiscountAmount(float $baseAmount): float
    {
        if ($this->discount_amount) {
            return $this->discount_amount;
        }

        if ($this->discount_percentage) {
            return $baseAmount * ($this->discount_percentage / 100);
        }

        return 0;
    }
}
