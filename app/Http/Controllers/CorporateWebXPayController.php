<?php

namespace App\Http\Controllers;

use App\Services\CorporateOnlinePaymentService;
use App\Services\WebXPayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CorporateWebXPayController extends Controller
{
    public function __construct(
        private readonly CorporateOnlinePaymentService $payments,
        private readonly WebXPayService $webXPay,
    ) {}

    public function redirect(string $attempt)
    {
        return response()->view('checkout.webxpay-redirect', $this->payments->redirectPayload($attempt));
    }

    public function cancel(string $attempt)
    {
        $result = $this->payments->cancel($attempt);
        return redirect()->away($this->payments->resultUrl($result, !$result['paid']));
    }

    public function callback(Request $request)
    {
        $verification = $this->webXPay->verifyPayment($request->all());
        $result = $this->payments->completeFromCallback($verification);
        if ($result === null) {
            Log::warning('WebXPay corporate callback did not match a payment attempt', ['order_id' => $verification['order_id'] ?? null]);
            return response()->view('checkout.callback-error', ['message' => 'Payment could not be matched to a corporate invoice. If you were charged, contact support with your transaction reference.'], 422);
        }

        return redirect()->away($this->payments->resultUrl($result));
    }

    public function notify(Request $request)
    {
        $verification = $this->webXPay->verifyPayment($request->all());
        $result = $this->payments->completeFromCallback($verification);
        if ($result === null) {
            return response()->json(['status' => 'error', 'message' => 'Corporate payment attempt not found.'], 404);
        }
        if (!$result['paid']) {
            return response()->json(['status' => 'error', 'message' => 'WebXPay payment was not approved.'], 422);
        }

        return response()->json(['status' => 'success']);
    }
}
