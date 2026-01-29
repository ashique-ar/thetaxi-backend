<?php

namespace App\Models;

use App\Models\BaseModel;
use App\Models\Service\ServiceType;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TermsAndCondition extends BaseModel
{
    use HasFactory;

    protected $table = 'terms_and_conditions';

    protected $fillable = [
        'title',
        'slug',
        'content',
        'service_type_id',
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

    // Append transformed service type information for API responses
    protected $appends = ['service_type_data'];

    /**
     * Relation to ServiceType model
     */
    public function serviceType()
    {
        return $this->belongsTo(ServiceType::class, 'service_type_id');
    }

    public function getServiceTypeDataAttribute()
    {
        return $this->serviceType ? $this->serviceType->toArray() : null;
    }

    /**
     * Scope: Get active T&C only
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: Get T&C by service type (accepts either old code string or UUID id)
     */
    public function scopeByServiceType($query, $serviceType)
    {
        // If a UUID is provided, prefer service_type_id; otherwise fallback to legacy service_type code
        if (is_string($serviceType) && preg_match('/^[0-9a-fA-F-]{36}$/', $serviceType)) {
            return $query->where('service_type_id', $serviceType)
                ->orWhereNull('service_type_id');
        }

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
                if (is_string($serviceType) && preg_match('/^[0-9a-fA-F-]{36}$/', $serviceType)) {
                    $q->where('service_type_id', $serviceType)->orWhereNull('service_type_id');
                } else {
                    $q->where('service_type', $serviceType)->orWhereNull('service_type');
                }
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

    /**
     * Get service-type terms only (no payment-type filtering).
     */
    public static function getServiceTerms(string $serviceType)
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $serviceType)) {
            return self::active()
                ->where('service_type_id', $serviceType)
                ->whereNull('payment_type')
                ->orderBy('display_order', 'asc')
                ->get();
        }

        return self::active()
            ->where('service_type', $serviceType)
            ->whereNull('payment_type')
            ->orderBy('display_order', 'asc')
            ->get();
    }

    /**
     * Get general service terms (applies to all services).
     */
    public static function getGeneralServiceTerms()
    {
        return self::active()
            ->whereNull('payment_type')
            ->whereNull('service_type_id')
            ->orderBy('display_order', 'asc')
            ->active()
            ->get();
    }

    /**
     * Get payment-type terms (service_type must be null).
     */
    public static function getPaymentTermsForCheckout(string $paymentType)
    {
        return self::active()
            ->where('payment_type', $paymentType)
            ->orderBy('display_order', 'asc')
            ->get();
    }
}
