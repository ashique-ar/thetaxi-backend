<?php

namespace App\Services;

class CompanyCustomerScope
{
    public static function constrain($query, string $customerAlias, string $companyId): void
    {
        $query->where(function ($scope) use ($customerAlias, $companyId): void {
            $scope->whereExists(fn ($owner) => $owner->selectRaw('1')->from('customer_relationship_owner_assignments as owner')
                ->whereColumn('owner.customer_id', "{$customerAlias}.id")->where('owner.company_id', $companyId))
                ->orWhereExists(fn ($inquiry) => $inquiry->selectRaw('1')->from('inquiries as inquiry')
                    ->whereColumn('inquiry.customer_id', "{$customerAlias}.id")->where('inquiry.company_id', $companyId)->whereNull('inquiry.deleted_at'))
                ->orWhereExists(fn ($opportunity) => $opportunity->selectRaw('1')->from('sales_opportunities as opportunity')
                    ->whereColumn('opportunity.customer_id', "{$customerAlias}.id")->where('opportunity.company_id', $companyId)->whereNull('opportunity.deleted_at'))
                ->orWhereExists(function ($booking) use ($customerAlias, $companyId): void {
                    $booking->selectRaw('1')->from('bookings as booking')
                        ->whereColumn('booking.customer_id', "{$customerAlias}.id")->whereNull('booking.deleted_at');
                    CompanyBookingScope::constrain($booking, 'booking', $companyId);
                });
        });
    }
}
