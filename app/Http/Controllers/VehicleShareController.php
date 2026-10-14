<?php

namespace App\Http\Controllers;

use App\Models\Vehicle\VehicleShareLink;
use App\Services\WebsiteSettingsService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class VehicleShareController extends Controller
{
    public function __construct(
        protected WebsiteSettingsService $websiteSettingsService
    ) {}

    public function show(Request $request, string $token)
    {
        $shareLink = VehicleShareLink::where('token', $token)->first();

        if (!$shareLink || !$shareLink->is_active) {
            return response()
                ->view('vehicle-share.invalid', [], 404)
                ->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
        }

        if ($shareLink->isExpired()) {
            $companyPhone = $this->websiteSettingsService->get('company_phone', '');
            $whatsappNumber = $this->websiteSettingsService->get('whatsapp_number', $companyPhone);
            return response()
                ->view('vehicle-share.expired', [
                    'shareLink' => $shareLink,
                    'companyPhone' => $companyPhone,
                    'whatsappNumber' => preg_replace('/[^0-9]/', '', $whatsappNumber),
                ], 410)
                ->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
        }

        // Record customer visit count and access timestamp
        $shareLink->recordAccess();

        $vehicle = $shareLink->vehicle()
            ->with([
                'make',
                'model',
                'group.category',
                'group.transmission',
                'group.fuelType',
                'group.make',
                'group.model',
            ])
            ->firstOrFail();

        $companyPhone = $this->websiteSettingsService->get('company_phone', '');
        $whatsappNumber = $this->websiteSettingsService->get('whatsapp_number', $companyPhone);
        $companyEmail = $this->websiteSettingsService->get('company_email', '');
        $companyName = $this->websiteSettingsService->get('company_name', config('app.name', 'TheTaxi'));

        return response()
            ->view('vehicle-share.show', [
                'vehicle' => $vehicle,
                'shareLink' => $shareLink,
                'companyPhone' => $companyPhone,
                'whatsappNumber' => preg_replace('/[^0-9]/', '', $whatsappNumber),
                'companyEmail' => $companyEmail,
                'companyName' => $companyName,
            ])
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
    }
}
