<?php

namespace App\Enums\Sms;

enum TransactionalSmsEvent: string
{
    case WebsiteInquiryReceived = 'website.inquiry_received';
    case WebsiteQuotationRequested = 'website.quotation_requested';
    case BookingConfirmed = 'booking.confirmed';
    case AdminBookingConfirmedSummary = 'admin.booking_confirmed_summary';
    case DriverDispatched = 'driver.dispatched';
    case DriverArrived = 'driver.arrived';
    case TripStarted = 'trip.started';
    case DriverAssignmentFallback = 'driver.assignment_fallback';
    case TripCompleted = 'trip.completed';
    case PaymentReceived = 'payment.received';
}
