<?php

namespace App\Services;

class CompanyBookingScope
{
    public static function constrain($query, string $bookingAlias, string $companyId): void
    {
        $query->where(function ($scope) use ($bookingAlias, $companyId): void {
            $scope->whereExists(fn ($attribution) => $attribution->selectRaw('1')->from('sales_booking_attributions as attribution')
                ->whereColumn('attribution.booking_id', "{$bookingAlias}.id")->where('attribution.company_id', $companyId))
                ->orWhereExists(fn ($owner) => $owner->selectRaw('1')->from('staff as owner_staff')
                    ->whereColumn('owner_staff.user_id', "{$bookingAlias}.responsible_user_id")->where('owner_staff.company_id', $companyId)
                    ->whereNull('owner_staff.deleted_at'))
                ->orWhereExists(fn ($creator) => $creator->selectRaw('1')->from('staff as creator_staff')
                    ->whereColumn('creator_staff.user_id', "{$bookingAlias}.created_by_user_id")->where('creator_staff.company_id', $companyId)
                    ->whereNull('creator_staff.deleted_at'))
                ->orWhereExists(fn ($commission) => $commission->selectRaw('1')->from('staff as commission_staff')
                    ->whereColumn('commission_staff.id', "{$bookingAlias}.commission_owner_staff_id")->where('commission_staff.company_id', $companyId))
                ->orWhereExists(fn ($opportunity) => $opportunity->selectRaw('1')->from('sales_opportunities as opportunity')
                    ->whereColumn('opportunity.id', "{$bookingAlias}.sales_opportunity_id")->where('opportunity.company_id', $companyId)->whereNull('opportunity.deleted_at'))
                ->orWhereExists(fn ($item) => $item->selectRaw('1')->from('booking_items as item')
                    ->whereColumn('item.booking_id', "{$bookingAlias}.id")
                    ->where(function ($resourceScope) use ($companyId): void {
                        $resourceScope->whereExists(fn ($service) => $service->selectRaw('1')->from('service_types as service')
                            ->whereColumn('service.id', 'item.service_type_id')->where('service.owner_type', 'company')->where('service.owner_id', $companyId))
                            ->orWhereExists(fn ($vehicle) => $vehicle->selectRaw('1')->from('vehicles as vehicle')
                                ->whereColumn('vehicle.id', 'item.vehicle_id')->where('vehicle.company_id', $companyId))
                            ->orWhereExists(fn ($driver) => $driver->selectRaw('1')->from('drivers as driver')
                                ->join('staff as driver_staff', 'driver_staff.user_id', '=', 'driver.user_id')
                                ->whereColumn('driver.id', 'item.driver_id')->where('driver_staff.company_id', $companyId)
                                ->whereNull('driver_staff.deleted_at'));
                    }));
        });
    }
}
