<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class BookingFormTab extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'code',
        'label',
        'service_type_code',
        'sort_order',
        'enabled',
        'icon_type',
        'icon_data',
        'metadata',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'metadata' => 'array',
    ];

    /**
     * Get the service type associated with this tab
     */
    public function serviceType()
    {
        return $this->belongsTo(\App\Models\Service\ServiceType::class, 'service_type_code', 'code');
    }

    /**
     * Get all enabled tabs in order
     */
    public static function getOrderedTabs()
    {
        return static::where('enabled', true)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Update the order of tabs
     */
    public static function updateOrder(array $tabIds)
    {
        foreach ($tabIds as $index => $tabId) {
            static::where('id', $tabId)->update(['sort_order' => $index + 1]);
        }
    }

    /**
     * Toggle tab enabled status
     */
    public function toggle()
    {
        $this->enabled = !$this->enabled;
        $this->save();
        return $this;
    }
}
