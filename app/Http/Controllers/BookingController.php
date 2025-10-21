<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BookingController extends Controller
{
    /**
     * Handle booking search request
     */
    public function search(Request $request)
    {
        // Validate based on service type
        $serviceType = $request->input('service_type');
        
        $validator = $this->getValidator($request, $serviceType);
        
        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        // Store booking data in session
        session(['booking_data' => $request->all()]);

        // Redirect to search/results page with booking data
        return redirect()->route('search')->with('booking_data', $request->all());
    }

    /**
     * Handle corporate enquiry submission
     */
    public function enquiry(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'company_name' => 'required|string|max:255',
            'contact_person' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'required|string|max:20',
            'requirements' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        // TODO: Store enquiry in database and/or send email notification
        // Example:
        // Enquiry::create($request->all());
        // Mail::to('admin@thetaxi.com')->send(new CorporateEnquiry($request->all()));

        return redirect()->route('contact')
            ->with('success', 'Thank you for your enquiry! We will contact you shortly.');
    }

    /**
     * Get validator based on service type
     */
    private function getValidator(Request $request, $serviceType)
    {
        switch ($serviceType) {
            case 'airport-transfer':
                return Validator::make($request->all(), [
                    'service_type' => 'required|string',
                    'transfer_type' => 'required|in:from-airport,to-airport',
                    'from' => 'nullable|string|max:255',
                    'to' => 'nullable|string|max:255',
                    'date' => 'required|date|after_or_equal:today',
                    'time' => 'required',
                ]);

            case 'drop-pickup':
                $rules = [
                    'service_type' => 'required|string',
                    'pickup' => 'required|string|max:255',
                    'dropoff' => 'required|string|max:255',
                    'date' => 'required|date|after_or_equal:today',
                    'time' => 'required',
                ];

                // Add return transfer validation if needed
                if ($request->input('need_return') == '1') {
                    $rules['return_pickup'] = 'required|string|max:255';
                    $rules['return_dropoff'] = 'required|string|max:255';
                    $rules['return_date'] = 'required|date|after_or_equal:date';
                    $rules['return_time'] = 'required';
                }

                return Validator::make($request->all(), $rules);

            case 'rental-packages':
                return Validator::make($request->all(), [
                    'service_type' => 'required|string',
                    'package_type' => 'required|in:taxi-100km,tour-200km',
                    'pickup' => 'required|string|max:255',
                    'dropoff' => 'required|string|max:255',
                    'pickup_date' => 'required|date|after_or_equal:today',
                    'pickup_time' => 'required',
                    'dropoff_date' => 'required|date|after_or_equal:pickup_date',
                    'dropoff_time' => 'required',
                ]);

            case 'custom-tour':
                $rules = [
                    'service_type' => 'required|string',
                    'starting_location' => 'required|string|max:255',
                    'start_date' => 'required|date|after_or_equal:today',
                    'end_date' => 'required|date|after_or_equal:start_date',
                    'destinations' => 'required|array|min:1',
                    'destinations.*.location' => 'required|string|max:255',
                    'destinations.*.visit_date' => 'required|date|after_or_equal:start_date|before_or_equal:end_date',
                    'destinations.*.visit_time' => 'nullable|string',
                    'destinations.*.notes' => 'nullable|string|max:500',
                    'destinations.*.lat' => 'nullable|numeric',
                    'destinations.*.lng' => 'nullable|numeric',
                ];
                
                return Validator::make($request->all(), $rules);

            default:
                return Validator::make($request->all(), [
                    'service_type' => 'required|string',
                ]);
        }
    }
}
