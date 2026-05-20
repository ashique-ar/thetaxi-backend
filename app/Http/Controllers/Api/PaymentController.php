<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    // Enforce authentication and permissions for payment endpoints
    public function __construct()
    {
        $this->middleware('auth:api');
        $this->middleware('permission:payments.initiate')->only(['initiatePayment']);
        $this->middleware('permission:payments.callback')->only(['paymentCallback']);
        $this->middleware('permission:payments.view')->only(['getPaymentStatus']);
        $this->middleware('permission:payments.refund')->only(['refundPayment', 'refundTransaction']);
        $this->middleware('permission:payments.methods')->only(['getPaymentMethods']);
        $this->middleware('permission:payments.transactions')->only(['getPaymentTransactions', 'getTransactionDetails']);
    }

    /**
     * Initiate payment
     * POST /api/payments/initiate
     */
    public function initiatePayment(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'booking_id' => 'required|exists:bookings,id',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|string|size:3',
            'payment_method' => 'required|string|in:credit_card,debit_card,paypal,stripe,bank_transfer',
            'return_url' => 'nullable|url',
            'cancel_url' => 'nullable|url'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $booking = Booking::findOrFail($request->booking_id);
            
            // Create payment transaction record
            $transaction = DB::table('payment_transactions')->insert([
                'id' => Str::uuid(),
                'booking_id' => $booking->id,
                'amount' => $request->amount,
                'currency' => $request->currency,
                'payment_method' => $request->payment_method,
                'status' => 'pending',
                'transaction_id' => $this->generateTransactionId(),
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // Here you would integrate with your payment provider
            // For now, returning a mock response
            $paymentUrl = $this->generatePaymentUrl($request->payment_method, $transaction);

            return response()->json([
                'status' => 'success',
                'message' => 'Payment initiated successfully',
                'data' => [
                    'transaction_id' => $transaction,
                    'payment_url' => $paymentUrl,
                    'amount' => $request->amount,
                    'currency' => $request->currency,
                    'status' => 'pending'
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to initiate payment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Payment callback
     * POST /api/payments/callback
     */
    public function paymentCallback(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'transaction_id' => 'required|string',
            'status' => 'required|string|in:success,failed,cancelled',
            'payment_id' => 'nullable|string',
            'signature' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $transaction = DB::table('payment_transactions')
                ->where('transaction_id', $request->transaction_id)
                ->first();

            if (!$transaction) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Transaction not found'
                ], 404);
            }

            // Verify signature if provided
            if ($request->signature && !$this->verifyPaymentSignature($request->all())) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid payment signature'
                ], 400);
            }

            // Update transaction status
            DB::table('payment_transactions')
                ->where('transaction_id', $request->transaction_id)
                ->update([
                    'status' => $request->status,
                    'payment_id' => $request->payment_id,
                    'updated_at' => now()
                ]);

            // Update booking status if payment successful
            if ($request->status === 'success') {
                $booking = Booking::find($transaction->booking_id);
                if ($booking) {
                    $booking->payment_status = 'paid';
                    $booking->save();
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Payment callback processed successfully',
                'data' => [
                    'transaction_id' => $request->transaction_id,
                    'status' => $request->status
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process payment callback',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get payment status
     * GET /api/payments/{id}/status
     */
    public function getPaymentStatus(string $id): JsonResponse
    {
        try {
            $transaction = DB::table('payment_transactions')
                ->where('transaction_id', $id)
                ->first();

            if (!$transaction) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Transaction not found'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'transaction_id' => $transaction->transaction_id,
                    'booking_id' => $transaction->booking_id,
                    'amount' => $transaction->amount,
                    'currency' => $transaction->currency,
                    'payment_method' => $transaction->payment_method,
                    'status' => $transaction->status,
                    'created_at' => $transaction->created_at,
                    'updated_at' => $transaction->updated_at
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get payment status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Refund payment
     * POST /api/payments/{id}/refund
     */
    public function refundPayment(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'nullable|numeric|min:0.01',
            'reason' => 'required|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $transaction = DB::table('payment_transactions')
                ->where('transaction_id', $id)
                ->where('status', 'success')
                ->first();

            if (!$transaction) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Transaction not found or not eligible for refund'
                ], 404);
            }

            $refundAmount = $request->amount ?? $transaction->amount;

            // Create refund record
            $refundId = Str::uuid();
            DB::table('payment_refunds')->insert([
                'id' => $refundId,
                'transaction_id' => $transaction->id,
                'amount' => $refundAmount,
                'reason' => $request->reason,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // Here you would integrate with your payment provider for actual refund
            // For now, marking as completed
            DB::table('payment_refunds')
                ->where('id', $refundId)
                ->update([
                    'status' => 'completed',
                    'updated_at' => now()
                ]);

            // Update booking payment status
            $booking = Booking::find($transaction->booking_id);
            if ($booking && $refundAmount >= $transaction->amount) {
                $booking->payment_status = 'refunded';
                $booking->save();
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Payment refunded successfully',
                'data' => [
                    'refund_id' => $refundId,
                    'amount' => $refundAmount,
                    'status' => 'completed'
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process refund',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get payment methods
     * GET /api/payments/methods
     */
    public function getPaymentMethods(): JsonResponse
    {
        $methods = [
            [
                'id' => 'credit_card',
                'name' => 'Credit Card',
                'description' => 'Pay with your credit card',
                'icon' => 'credit-card',
                'is_active' => true
            ],
            [
                'id' => 'debit_card',
                'name' => 'Debit Card',
                'description' => 'Pay with your debit card',
                'icon' => 'debit-card',
                'is_active' => true
            ],
            [
                'id' => 'paypal',
                'name' => 'PayPal',
                'description' => 'Pay with PayPal',
                'icon' => 'paypal',
                'is_active' => true
            ],
            [
                'id' => 'stripe',
                'name' => 'Stripe',
                'description' => 'Pay with Stripe',
                'icon' => 'stripe',
                'is_active' => true
            ],
            [
                'id' => 'bank_transfer',
                'name' => 'Bank Transfer',
                'description' => 'Pay via bank transfer',
                'icon' => 'bank',
                'is_active' => true
            ]
        ];

        return response()->json([
            'status' => 'success',
            'data' => [
                'payment_methods' => $methods
            ]
        ]);
    }

    /**
     * Get payment transactions
     * GET /api/payment-transactions
     */
    public function getPaymentTransactions(Request $request): JsonResponse
    {
        $limit = $request->get('limit', 20);
        $status = $request->get('status');
        $bookingId = $request->get('booking_id');

        $query = DB::table('payment_transactions')
            ->join('bookings', 'payment_transactions.booking_id', '=', 'bookings.id')
            ->leftJoin('customers', 'bookings.customer_id', '=', 'customers.id')
            ->leftJoin('users', 'customers.user_id', '=', 'users.id')
            ->select([
                'payment_transactions.*',
                'bookings.booking_number',
                'users.first_name',
                'users.last_name',
                'users.email'
            ]);

        if ($status) {
            $query->where('payment_transactions.status', $status);
        }

        if ($bookingId) {
            $query->where('payment_transactions.booking_id', $bookingId);
        }

        $transactions = $query->orderBy('payment_transactions.created_at', 'desc')
            ->paginate($limit);

        return response()->json([
            'status' => 'success',
            'data' => [
                'transactions' => $transactions->items(),
                'pagination' => [
                    'current_page' => $transactions->currentPage(),
                    'last_page' => $transactions->lastPage(),
                    'per_page' => $transactions->perPage(),
                    'total' => $transactions->total()
                ]
            ]
        ]);
    }

    /**
     * Get transaction details
     * GET /api/payment-transactions/{id}
     */
    public function getTransactionDetails(string $id): JsonResponse
    {
        try {
            $transaction = DB::table('payment_transactions')
                ->join('bookings', 'payment_transactions.booking_id', '=', 'bookings.id')
                ->leftJoin('customers', 'bookings.customer_id', '=', 'customers.id')
                ->leftJoin('users', 'customers.user_id', '=', 'users.id')
                ->select([
                    'payment_transactions.*',
                    'bookings.booking_number',
                    'users.first_name',
                    'users.last_name',
                    'users.email'
                ])
                ->selectRaw('COALESCE(bookings.total_actual, bookings.total_estimated, 0) as booking_amount')
                ->where('payment_transactions.id', $id)
                ->first();

            if (!$transaction) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Transaction not found'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'transaction' => $transaction
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get transaction details',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Refund transaction
     * POST /api/payment-transactions/{id}/refund
     */
    public function refundTransaction(Request $request, string $id): JsonResponse
    {
        return $this->refundPayment($request, $id);
    }

    /**
     * Generate transaction ID
     */
    private function generateTransactionId(): string
    {
        return 'TXN' . strtoupper(Str::random(12));
    }

    /**
     * Generate payment URL (mock implementation)
     */
    private function generatePaymentUrl(string $paymentMethod, $transactionId): string
    {
        // This would be replaced with actual payment provider URLs
        return config('app.url') . "/payments/{$paymentMethod}/{$transactionId}";
    }

    /**
     * Verify payment signature (mock implementation)
     */
    private function verifyPaymentSignature(array $data): bool
    {
        // This would be replaced with actual signature verification
        return true;
    }
}
