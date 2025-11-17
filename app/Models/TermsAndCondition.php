<?php

namespace App\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TermsAndCondition extends BaseModel
{
    use HasFactory;

    protected $table = 'terms_and_conditions';

    protected $fillable = [
        'title',
        'slug',
        'content',
        'service_type',
        'payment_type',
        'version',
        'is_active',
        'effective_date',
        'display_order'
    ];

    protected $casts = [
        'effective_date' => 'datetime',
        'is_active' => 'boolean'
    ];

    /**
     * Scope: Get active T&C only
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: Get T&C by service type
     */
    public function scopeByServiceType($query, $serviceType)
    {
        return $query->where('service_type', $serviceType)
                     ->orWhereNull('service_type');
    }

    /**
     * Scope: Get T&C by payment type
     */
    public function scopeByPaymentType($query, $paymentType)
    {
        return $query->where('payment_type', $paymentType)
                     ->orWhereNull('payment_type');
    }

    /**
     * Get T&C for specific service and payment type
     */
    public static function getForCheckout($serviceType = null, $paymentType = null)
    {
        $query = self::active()->orderBy('display_order', 'asc');

        if ($serviceType) {
            $query->where(function ($q) use ($serviceType) {
                $q->where('service_type', $serviceType)
                  ->orWhereNull('service_type');
            });
        }

        if ($paymentType) {
            $query->where(function ($q) use ($paymentType) {
                $q->where('payment_type', $paymentType)
                  ->orWhereNull('payment_type');
            });
        }

        return $query->get();
    }
}
