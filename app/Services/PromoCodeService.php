<?php

namespace App\Services;

use App\Models\PromoCode;
use App\Models\PromoCodeUsage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * PromoCodeService handles all promo code business logic.
 * 
 * This service provides CRUD operations, validation, discount calculation,
 * usage tracking, and analytics for promotional codes.
 */
class PromoCodeService
{
    /**
     * Cache key prefix for promo code data
     */
    private const CACHE_PREFIX = 'promo_codes_';
    
    /**
     * Cache duration in seconds (15 minutes)
     */
    private const CACHE_DURATION = 900;

    /**
     * Get all promo codes with optional filtering
     *
     * @param array $filters Optional filters (is_active, search, discount_type)
     * @return Collection
     */
    public function getAll(array $filters = []): Collection
    {
        $query = PromoCode::query();

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        if (isset($filters['discount_type'])) {
            $query->where('discount_type', $filters['discount_type']);
        }

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    /**
     * Get a promo code by ID
     *
     * @param string $id
     * @return PromoCode|null
     */
    public function getById(string $id): ?PromoCode
    {
        return PromoCode::find($id);
    }

    /**
     * Get a promo code by its code (case-insensitive)
     *
     * @param string $code
     * @return PromoCode|null
     */
    public function getByCode(string $code): ?PromoCode
    {
        return PromoCode::byCode($code)->first();
    }

    /**
     * Create a new promo code
     *
     * @param array $data
     * @return PromoCode
     * @throws ValidationException
     */
    public function create(array $data): PromoCode
    {
        $this->validatePromoCodeData($data);

        DB::beginTransaction();

        try {
            $promoCode = PromoCode::create([
                'code' => strtoupper(trim($data['code'])),
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'discount_type' => $data['discount_type'],
                'discount_value' => $data['discount_value'],
                'minimum_order_amount' => $data['minimum_order_amount'] ?? 0,
                'maximum_discount_amount' => $data['maximum_discount_amount'] ?? null,
                'usage_limit' => $data['usage_limit'] ?? null,
                'usage_limit_per_customer' => $data['usage_limit_per_customer'] ?? 1,
                'usage_count' => 0,
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);

            DB::commit();
            $this->clearCache();

            Log::info('Promo code created successfully', ['promo_code_id' => $promoCode->id, 'code' => $promoCode->code]);

            return $promoCode;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating promo code: ' . $e->getMessage(), ['data' => $data]);
            throw $e;
        }
    }

    /**
     * Update an existing promo code
     *
     * @param string $id
     * @param array $data
     * @return PromoCode
     * @throws ValidationException
     */
    public function update(string $id, array $data): PromoCode
    {
        $promoCode = PromoCode::findOrFail($id);

        $this->validatePromoCodeData($data, $promoCode);

        DB::beginTransaction();

        try {
            $updateData = [];

            if (isset($data['code'])) {
                $updateData['code'] = strtoupper(trim($data['code']));
            }
            if (isset($data['name'])) {
                $updateData['name'] = $data['name'];
            }
            if (array_key_exists('description', $data)) {
                $updateData['description'] = $data['description'];
            }
            if (isset($data['discount_type'])) {
                $updateData['discount_type'] = $data['discount_type'];
            }
            if (isset($data['discount_value'])) {
                $updateData['discount_value'] = $data['discount_value'];
            }
            if (array_key_exists('minimum_order_amount', $data)) {
                $updateData['minimum_order_amount'] = $data['minimum_order_amount'] ?? 0;
            }
            if (array_key_exists('maximum_discount_amount', $data)) {
                $updateData['maximum_discount_amount'] = $data['maximum_discount_amount'];
            }
            if (array_key_exists('usage_limit', $data)) {
                $updateData['usage_limit'] = $data['usage_limit'];
            }
            if (array_key_exists('usage_limit_per_customer', $data)) {
                $updateData['usage_limit_per_customer'] = $data['usage_limit_per_customer'] ?? 1;
            }
            if (array_key_exists('start_date', $data)) {
                $updateData['start_date'] = $data['start_date'];
            }
            if (array_key_exists('end_date', $data)) {
                $updateData['end_date'] = $data['end_date'];
            }
            if (isset($data['is_active'])) {
                $updateData['is_active'] = $data['is_active'];
            }

            $promoCode->update($updateData);

            DB::commit();
            $this->clearCache();

            Log::info('Promo code updated successfully', ['promo_code_id' => $promoCode->id]);

            return $promoCode->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating promo code: ' . $e->getMessage(), ['promo_code_id' => $id, 'data' => $data]);
            throw $e;
        }
    }

    /**
     * Soft delete a promo code
     *
     * @param string $id
     * @return bool
     */
    public function softDelete(string $id): bool
    {
        $promoCode = PromoCode::findOrFail($id);

        DB::beginTransaction();

        try {
            $promoCode->delete();

            DB::commit();
            $this->clearCache();

            Log::info('Promo code soft deleted successfully', ['promo_code_id' => $id]);

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error soft deleting promo code: ' . $e->getMessage(), ['promo_code_id' => $id]);
            throw $e;
        }
    }

    /**
     * Toggle promo code active status
     *
     * @param string $id
     * @return PromoCode
     */
    public function toggleStatus(string $id): PromoCode
    {
        $promoCode = PromoCode::findOrFail($id);

        DB::beginTransaction();

        try {
            $promoCode->update([
                'is_active' => !$promoCode->is_active,
            ]);

            DB::commit();
            $this->clearCache();

            Log::info('Promo code status toggled', [
                'promo_code_id' => $promoCode->id,
                'new_status' => $promoCode->is_active,
            ]);

            return $promoCode->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error toggling promo code status: ' . $e->getMessage(), ['promo_code_id' => $id]);
            throw $e;
        }
    }


    /**
     * Validate promo code data for creation or update
     *
     * @param array $data
     * @param PromoCode|null $existingPromoCode
     * @return array
     * @throws ValidationException
     */
    public function validatePromoCodeData(array $data, ?PromoCode $existingPromoCode = null): array
    {
        $errors = [];

        // Validate required fields for new promo codes
        if (!$existingPromoCode) {
            if (empty($data['code'])) {
                $errors['code'] = ['The code field is required.'];
            }
            if (empty($data['name'])) {
                $errors['name'] = ['The name field is required.'];
            }
            if (empty($data['discount_type'])) {
                $errors['discount_type'] = ['The discount type field is required.'];
            }
            if (!isset($data['discount_value']) || $data['discount_value'] === '') {
                $errors['discount_value'] = ['The discount value field is required.'];
            }
        }

        // Validate code uniqueness
        if (isset($data['code']) && !empty($data['code'])) {
            $codeUpper = strtoupper(trim($data['code']));
            $existingCode = PromoCode::byCode($codeUpper)->first();
            
            if ($existingCode && (!$existingPromoCode || $existingCode->id !== $existingPromoCode->id)) {
                $errors['code'] = ['This promo code already exists.'];
            }
        }

        // Validate discount type
        if (isset($data['discount_type'])) {
            $validTypes = [PromoCode::DISCOUNT_TYPE_PERCENTAGE, PromoCode::DISCOUNT_TYPE_FIXED];
            if (!in_array($data['discount_type'], $validTypes)) {
                $errors['discount_type'] = ['Invalid discount type. Must be "percentage" or "fixed".'];
            }
        }

        // Validate discount value is positive
        if (isset($data['discount_value'])) {
            if (!is_numeric($data['discount_value']) || $data['discount_value'] <= 0) {
                $errors['discount_value'] = ['The discount value must be a positive number.'];
            }
            
            // For percentage, validate it's not more than 100
            $discountType = $data['discount_type'] ?? ($existingPromoCode?->discount_type);
            if ($discountType === PromoCode::DISCOUNT_TYPE_PERCENTAGE && $data['discount_value'] > 100) {
                $errors['discount_value'] = ['Percentage discount cannot exceed 100%.'];
            }
        }

        // Validate minimum order amount is non-negative
        if (isset($data['minimum_order_amount']) && $data['minimum_order_amount'] !== null) {
            if (!is_numeric($data['minimum_order_amount']) || $data['minimum_order_amount'] < 0) {
                $errors['minimum_order_amount'] = ['The minimum order amount must be a non-negative number.'];
            }
        }

        // Validate maximum discount amount is positive if set
        if (isset($data['maximum_discount_amount']) && $data['maximum_discount_amount'] !== null) {
            if (!is_numeric($data['maximum_discount_amount']) || $data['maximum_discount_amount'] <= 0) {
                $errors['maximum_discount_amount'] = ['The maximum discount amount must be a positive number.'];
            }
        }

        // Validate usage limits are positive if set
        if (isset($data['usage_limit']) && $data['usage_limit'] !== null) {
            if (!is_numeric($data['usage_limit']) || $data['usage_limit'] <= 0) {
                $errors['usage_limit'] = ['The usage limit must be a positive number.'];
            }
        }

        if (isset($data['usage_limit_per_customer']) && $data['usage_limit_per_customer'] !== null) {
            if (!is_numeric($data['usage_limit_per_customer']) || $data['usage_limit_per_customer'] <= 0) {
                $errors['usage_limit_per_customer'] = ['The usage limit per customer must be a positive number.'];
            }
        }

        // Validate date range
        $startDate = $data['start_date'] ?? ($existingPromoCode?->start_date);
        $endDate = $data['end_date'] ?? ($existingPromoCode?->end_date);

        if ($startDate && $endDate) {
            $start = $startDate instanceof \Carbon\Carbon ? $startDate : \Carbon\Carbon::parse($startDate);
            $end = $endDate instanceof \Carbon\Carbon ? $endDate : \Carbon\Carbon::parse($endDate);

            if ($end->lt($start)) {
                $errors['end_date'] = ['The end date must be after or equal to the start date.'];
            }
        }

        if (!empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        return $data;
    }

    /**
     * Validate a promo code for application
     * 
     * Checks: code exists, is active, date range, minimum order, usage limits
     *
     * @param string $code
     * @param float $orderAmount
     * @param string|null $customerId
     * @return array
     */
    public function validatePromoCode(string $code, float $orderAmount, ?string $customerId = null): array
    {
        $promoCode = $this->getByCode($code);

        if (!$promoCode) {
            return [
                'valid' => false,
                'error_code' => 'PROMO_CODE_NOT_FOUND',
                'message' => 'The promo code does not exist.',
            ];
        }

        return $promoCode->validate($orderAmount, $customerId);
    }

    /**
     * Calculate discount for a given promo code and order amount
     *
     * For percentage: min(order_amount * (discount_value / 100), maximum_discount_amount)
     * For fixed: min(discount_value, order_amount)
     *
     * @param PromoCode $promoCode
     * @param float $orderAmount
     * @return float
     */
    public function calculateDiscount(PromoCode $promoCode, float $orderAmount): float
    {
        return $promoCode->calculateDiscount($orderAmount);
    }

    /**
     * Calculate discount by promo code string
     *
     * @param string $code
     * @param float $orderAmount
     * @return float|null Returns null if code not found
     */
    public function calculateDiscountByCode(string $code, float $orderAmount): ?float
    {
        $promoCode = $this->getByCode($code);
        
        if (!$promoCode) {
            return null;
        }

        return $this->calculateDiscount($promoCode, $orderAmount);
    }


    /**
     * Record promo code usage after a booking is completed
     *
     * @param PromoCode $promoCode
     * @param string|null $customerId
     * @param string|null $bookingId
     * @param float $discountAmount
     * @param float $orderAmount
     * @return PromoCodeUsage
     */
    public function recordUsage(
        PromoCode $promoCode,
        ?string $customerId,
        ?string $bookingId,
        float $discountAmount,
        float $orderAmount
    ): PromoCodeUsage {
        DB::beginTransaction();

        try {
            $usage = PromoCodeUsage::recordUsage(
                $promoCode->id,
                $customerId,
                $bookingId,
                $discountAmount,
                $orderAmount
            );

            $promoCode->incrementUsageCount();

            DB::commit();
            $this->clearCache();

            Log::info('Promo code usage recorded', [
                'promo_code_id' => $promoCode->id,
                'customer_id' => $customerId,
                'booking_id' => $bookingId,
                'discount_amount' => $discountAmount,
            ]);

            return $usage;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error recording promo code usage: ' . $e->getMessage(), [
                'promo_code_id' => $promoCode->id,
                'customer_id' => $customerId,
            ]);
            throw $e;
        }
    }

    /**
     * Get analytics for a promo code
     *
     * Returns:
     * - total_redemptions: count of usage records
     * - total_discount_given: sum of discount_amount from all usage records
     * - total_revenue_generated: sum of order_amount from all usage records
     * - unique_customers: count of distinct customer_id values
     * - average_order_value: total_revenue_generated / total_redemptions
     *
     * @param string $promoCodeId
     * @return array
     */
    public function getAnalytics(string $promoCodeId): array
    {
        $promoCode = PromoCode::findOrFail($promoCodeId);

        $usages = PromoCodeUsage::forPromoCode($promoCodeId)->get();

        $totalRedemptions = $usages->count();
        $totalDiscountGiven = $usages->sum('discount_amount');
        $totalRevenueGenerated = $usages->sum('order_amount');
        $uniqueCustomers = $usages->whereNotNull('customer_id')->pluck('customer_id')->unique()->count();
        $averageOrderValue = $totalRedemptions > 0 ? $totalRevenueGenerated / $totalRedemptions : 0;

        return [
            'promo_code_id' => $promoCodeId,
            'code' => $promoCode->code,
            'name' => $promoCode->name,
            'total_redemptions' => $totalRedemptions,
            'total_discount_given' => round($totalDiscountGiven, 2),
            'total_revenue_generated' => round($totalRevenueGenerated, 2),
            'unique_customers' => $uniqueCustomers,
            'average_order_value' => round($averageOrderValue, 2),
        ];
    }

    /**
     * Get statistics for all promo codes
     *
     * @return array
     */
    public function getStatistics(): array
    {
        return [
            'total' => PromoCode::count(),
            'active' => PromoCode::where('is_active', true)->count(),
            'inactive' => PromoCode::where('is_active', false)->count(),
            'currently_valid' => PromoCode::active()->available()->count(),
            'total_redemptions' => PromoCodeUsage::count(),
            'total_discount_given' => PromoCodeUsage::sum('discount_amount'),
        ];
    }

    /**
     * Clear all promo code related cache
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_PREFIX . 'active');
        Cache::forget(self::CACHE_PREFIX . 'statistics');
        
        Log::debug('Promo code cache cleared');
    }

    /**
     * Get active and available promo codes (for public display if needed)
     *
     * @return Collection
     */
    public function getActivePromoCodes(): Collection
    {
        $cacheKey = self::CACHE_PREFIX . 'active';

        return Cache::remember($cacheKey, self::CACHE_DURATION, function () {
            return PromoCode::active()->available()->get();
        });
    }
}
