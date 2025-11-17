<?php

namespace Database\Seeders;

use App\Models\TermsAndCondition;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class TermsAndConditionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $termsData = [
            [
                'title' => 'Vehicle Rental Agreement',
                'slug' => 'vehicle-rental-agreement',
                'content' => '<p>By booking a vehicle through TheTaxi, you agree to the following terms:</p>
                <ul>
                    <li><strong>Age Requirement:</strong> Driver must be at least 23 years old with a valid driving license</li>
                    <li><strong>Valid License:</strong> An International Driving Permit (IDP) or valid national license is required</li>
                    <li><strong>Security Deposit:</strong> A refundable security deposit is required at pickup</li>
                    <li><strong>Insurance:</strong> Basic insurance is included. Optional additional coverage available</li>
                    <li><strong>Fuel Policy:</strong> Vehicle will be provided full; return with full tank</li>
                    <li><strong>Mileage Limit:</strong> Unlimited mileage included in all packages</li>
                </ul>',
                'service_type' => 'vehicle_rental',
                'payment_type' => null,
                'version' => 1,
                'is_active' => true,
                'display_order' => 1
            ],
            [
                'title' => 'Full Payment Terms',
                'slug' => 'full-payment-terms',
                'content' => '<p>When selecting full payment option:</p>
                <ul>
                    <li>Full amount must be paid before vehicle pickup</li>
                    <li>Payment is non-refundable unless cancelled 48 hours before pickup</li>
                    <li>All booking details must be confirmed in writing</li>
                    <li>Late cancellation fees may apply (see cancellation policy)</li>
                </ul>
                <p><strong>Cancellation Policy:</strong></p>
                <ul>
                    <li>More than 7 days before pickup: Full refund</li>
                    <li>3-7 days before pickup: 50% refund</li>
                    <li>Less than 48 hours: No refund</li>
                </ul>',
                'service_type' => 'vehicle_rental',
                'payment_type' => 'full',
                'version' => 1,
                'is_active' => true,
                'display_order' => 2
            ],
            [
                'title' => 'Advance Payment Terms',
                'slug' => 'advance-payment-terms',
                'content' => '<p>For 50% advance payment bookings:</p>
                <ul>
                    <li>50% of total amount is due at booking</li>
                    <li>Remaining 50% must be paid at vehicle pickup</li>
                    <li>Booking is tentative until full payment is received</li>
                    <li>Vehicle will be held for maximum 48 hours after initial payment</li>
                    <li>Advance payment is non-refundable unless cancelled 7 days before pickup</li>
                </ul>
                <p><strong>Payment Methods:</strong> Credit Card, PayPal, or Bank Transfer</p>',
                'service_type' => 'vehicle_rental',
                'payment_type' => 'advance',
                'version' => 1,
                'is_active' => true,
                'display_order' => 3
            ],
            [
                'title' => 'Quotation Terms',
                'slug' => 'quotation-terms',
                'content' => '<p>When requesting a quotation:</p>
                <ul>
                    <li>Our team will review your requirements within 24 hours</li>
                    <li>A customized quote will be sent to your email</li>
                    <li>Quote is valid for 7 days</li>
                    <li>No payment is required to submit quotation request</li>
                    <li>Quotation may differ based on vehicle availability and special requests</li>
                </ul>
                <p><strong>Special Requests:</strong> Airport transfers, additional drivers, and equipment rentals can be arranged upon request</p>',
                'service_type' => 'vehicle_rental',
                'payment_type' => 'quotation',
                'version' => 1,
                'is_active' => true,
                'display_order' => 4
            ],
            [
                'title' => 'Damage and Liability',
                'slug' => 'damage-and-liability',
                'content' => '<p>Customer responsibility for vehicle damage:</p>
                <ul>
                    <li>Renter is responsible for any damage beyond normal wear and tear</li>
                    <li>Damage assessment will be documented at return</li>
                    <li>Repair costs will be charged to renter or insurance claim</li>
                    <li>Windshield and tire damage are typically covered by insurance</li>
                </ul>
                <p><strong>Traffic Violations & Fines:</strong> Renter is responsible for all traffic violations and parking fines incurred during rental period</p>
                <p><strong>Accident Reporting:</strong> Must report all accidents to TheTaxi within 24 hours with photos and police report number</p>',
                'service_type' => 'vehicle_rental',
                'payment_type' => null,
                'version' => 1,
                'is_active' => true,
                'display_order' => 5
            ],
            [
                'title' => 'Privacy Policy',
                'slug' => 'privacy-policy',
                'content' => '<p>We value your privacy and are committed to protecting your personal data:</p>
                <ul>
                    <li>Your personal information is used solely for booking and service delivery</li>
                    <li>We do not share your information with third parties without consent</li>
                    <li>Your payment information is encrypted and securely processed</li>
                    <li>You may request data deletion anytime (subject to legal requirements)</li>
                    <li>We use cookies to improve your browsing experience</li>
                </ul>
                <p><strong>Contact Us:</strong> For privacy concerns, contact privacy@thetaxi.com</p>',
                'service_type' => 'general',
                'payment_type' => null,
                'version' => 1,
                'is_active' => true,
                'display_order' => 6
            ]
        ];

        foreach ($termsData as $data) {
            TermsAndCondition::updateOrCreate(
                ['slug' => $data['slug']],
                $data
            );
        }
    }
}
