<?php

namespace App\Http\Controllers;

use App\Services\StaffPaymentLinkService;
use App\Services\WebXPayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StaffPaymentLinkController extends Controller
{
    public function __construct(private readonly StaffPaymentLinkService $links, private readonly WebXPayService $webXPay) {}

    public function store(Request $request)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999999'],
            'currency' => ['sometimes', 'in:LKR'],
        ]);
        return response()->json(['data' => $this->links->create((string) $request->user()->id, (float) $data['amount'])], 201);
    }

    public function redirect(string $link)
    {
        return response()->view('checkout.webxpay-redirect', $this->links->redirectPayload($link));
    }

    public function cancel(string $link)
    {
        $this->links->cancel($link);
        return redirect()->away(rtrim((string) config('app.portal_url', config('app.url')), '/').'/staff/payment-links?payment=cancelled');
    }

    public function callback(Request $request)
    {
        $result = $this->links->complete($this->webXPay->verifyPayment($request->all()));
        if ($result === null) {
            Log::warning('WebXPay staff payment callback did not match a link');
            return response()->view('checkout.callback-error', ['message' => 'Payment could not be matched. If you were charged, contact support with your transaction reference.'], 422);
        }
        return redirect()->away(rtrim((string) config('app.portal_url', config('app.url')), '/').'/staff/payment-links?payment='.($result ? 'paid' : 'failed'));
    }

    public function notify(Request $request)
    {
        $result = $this->links->complete($this->webXPay->verifyPayment($request->all()));
        if ($result === null) return response()->json(['status' => 'error', 'message' => 'Payment link not found.'], 404);
        return response()->json(['status' => $result ? 'success' : 'error'], $result ? 200 : 422);
    }
}
