<?php

namespace App\Models\Sales;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SalesKpiSnapshotRow extends Model
{
    use HasUuids;

    protected $fillable = [
        'snapshot_id',
        'sales_profile_id',
        'staff_id',
        'staff_code',
        'new_sales_target_lkr',
        'collection_target_lkr',
        'new_sales_lkr',
        'gross_new_sales_lkr',
        'new_sales_adjustment_lkr',
        'net_new_sales_lkr',
        'new_sales_value_state',
        'new_booking_collections_lkr',
        'existing_booking_collections_lkr',
        'eligible_collections_lkr',
        'commission_new_business_lkr',
        'commission_existing_business_lkr',
        'new_bookings_count',
        'new_customers_count',
        'activities_count',
        'overdue_collections_count',
        'overdue_collections_lkr',
        'new_sales_achievement_percent',
        'collection_achievement_percent',
        'display_rank',
        'metric_snapshot',
        'row_checksum',
    ];
    protected $casts = [
        'metric_snapshot' => 'array',
        'new_bookings_count' => 'integer',
        'new_customers_count' => 'integer',
        'activities_count' => 'integer',
        'overdue_collections_count' => 'integer',
        'display_rank' => 'integer',
    ];
}
