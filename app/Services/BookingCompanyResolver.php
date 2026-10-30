<?php

namespace App\Services;

use App\Models\Booking\Booking;
use App\Models\Company;
use App\Models\Corporate\Corporate;
use Illuminate\Support\Facades\DB;

final class BookingCompanyResolver
{
    public function resolve(?Booking $booking = null): ?string
    {
        if ($booking && ! $booking->company_id) {
            return app(SingleCompanyScope::class)->activeDefaultCompany()?->id;
        }

        if ($booking?->company_id && Company::query()->whereKey($booking->company_id)->where('is_active', true)->exists()) {
            return (string) $booking->company_id;
        }

        if ($booking?->corporate_account_id) {
            $corporateCompanyId = Corporate::query()->whereKey($booking->corporate_account_id)->value('company_id');
            if ($corporateCompanyId && Company::query()->whereKey($corporateCompanyId)->where('is_active', true)->exists()) {
                return (string) $corporateCompanyId;
            }
        }

        $request = request();
        $contextCompanyId = $request->attributes->get('business_company_id');
        if ($contextCompanyId && Company::query()->whereKey($contextCompanyId)->where('is_active', true)->exists()) {
            return (string) $contextCompanyId;
        }

        if ($request->header('X-Active-Context-Type') === 'staff') {
            return app(WebsiteSettingsService::class)->resolveCurrentCompanyId();
        }

        if ($request->header('X-Active-Context-Type') !== 'internal') {
            $requestCompanyId = app(WebsiteSettingsService::class)->resolveCurrentCompanyId();
            if ($requestCompanyId) {
                return $requestCompanyId;
            }
        }

        $userId = $booking?->responsible_user_id ?: $booking?->created_by_user_id;
        if ($userId) {
            $companies = DB::table('staff')->where('user_id', $userId)
                ->whereNull('deleted_at')->distinct()->pluck('company_id');
            if ($companies->count() === 1) {
                $companyId = (string) ($companies->first() ?: app(SingleCompanyScope::class)->activeDefaultCompany()?->id);
                if (Company::query()->whereKey($companyId)->where('is_active', true)->exists()) {
                    return $companyId;
                }
            }
        }

        return app(SingleCompanyScope::class)->activeDefaultCompany()?->id;
    }
}
