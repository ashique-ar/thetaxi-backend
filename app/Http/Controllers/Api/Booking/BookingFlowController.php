<?php

namespace App\Http\Controllers\Api\Booking;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Booking\Traits\BookingAnalyticsTrait;
use App\Http\Controllers\Api\Booking\Traits\BookingApprovalTrait;
use App\Http\Controllers\Api\Booking\Traits\BookingAvailabilityTrait;
use App\Http\Controllers\Api\Booking\Traits\BookingCorporateTrait;
use App\Http\Controllers\Api\Booking\Traits\BookingDiscountTrait;
use App\Http\Controllers\Api\Booking\Traits\BookingPricingTrait;
use App\Http\Controllers\Api\Booking\Traits\BookingSubmissionTrait;
use App\Services\BookingFlowService;
use App\Services\AssignmentService;
use App\Services\CorporateService;
use App\Services\CurrencyService;
use App\Services\DiscountService;

class BookingFlowController extends Controller
{
    use BookingAvailabilityTrait;
    use BookingPricingTrait;
    use BookingSubmissionTrait;
    use BookingApprovalTrait;
    use BookingDiscountTrait;
    use BookingCorporateTrait;
    use BookingAnalyticsTrait;

    protected $bookingFlowService;
    protected $currencyService;
    protected $discountService;
    protected $assignmentService;
    protected $corporateService;

    public function __construct(
        BookingFlowService $bookingFlowService,
        CurrencyService $currencyService,
        DiscountService $discountService,
        AssignmentService $assignmentService,
        CorporateService $corporateService
    ) {
        $this->bookingFlowService = $bookingFlowService;
        $this->currencyService = $currencyService;
        $this->discountService = $discountService;
        $this->assignmentService = $assignmentService;
        $this->corporateService = $corporateService;
    }
}
