<?php

namespace Tests\Unit;

use App\Models\Cart;
use App\Models\PromoCode;
use App\Services\CartService;
use App\Services\PromoCodeService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class CheckoutPromotionRevalidationContractTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_applied_promotion_is_repriced_for_the_current_subtotal(): void
    {
        $promoService = $this->promoService(valid: true);
        $cart = $this->cart('SAVE10', 50.0);

        $state = $this->cartService($promoService)
            ->refreshAppliedPromoCode($cart, 800.0, 'customer-a');

        $this->assertTrue($state['valid']);
        $this->assertTrue($state['applied']);
        $this->assertSame(80.0, $state['discount']);
        $this->assertSame('SAVE10', $cart->coupon_code);
        $this->assertSame(80.0, (float) $cart->coupon_discount);
        $this->assertSame([['SAVE10', 800.0, 'customer-a']], $promoService->validations);
    }

    public function test_invalid_portal_rule_removes_the_stale_cart_discount(): void
    {
        $promoService = $this->promoService(
            valid: false,
            errorCode: 'PROMO_CODE_INACTIVE',
            message: 'This promo code is not active.'
        );
        $cart = $this->cart('SAVE10', 50.0);

        $state = $this->cartService($promoService)
            ->refreshAppliedPromoCode($cart, 800.0, 'customer-a');

        $this->assertFalse($state['valid']);
        $this->assertFalse($state['applied']);
        $this->assertSame('PROMO_CODE_INACTIVE', $state['error_code']);
        $this->assertSame('SAVE10', $state['removed_code']);
        $this->assertNull($cart->coupon_code);
        $this->assertSame(0.0, (float) $cart->coupon_discount);
    }

    public function test_portal_date_boundaries_are_inclusive_and_null_customer_limit_is_unlimited(): void
    {
        Carbon::setTestNow('2026-07-27 18:00:00');
        $promo = $this->promo([
            'start_date' => '2026-07-27 00:00:00',
            'end_date' => '2026-07-27 00:00:00',
            'usage_limit_per_customer' => null,
        ]);

        $this->assertTrue($promo->isWithinDateRange());
        $this->assertFalse($promo->hasCustomerReachedLimit('customer-a'));
    }

    public function test_checkout_and_portal_editor_share_the_revalidation_contract(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $checkoutController = file_get_contents(
            $projectRoot . '/app/Http/Controllers/CheckoutController.php'
        );
        $portalForm = file_get_contents(
            dirname($projectRoot)
                . '/portal-thetaxi/src/app/modules/admin/cms/promo-codes/form/promo-code-form-dialog.component.ts'
        );

        $this->assertStringContainsString(
            '$promoState = $this->cartService->updateTotals($cartModel, $existingCustomer?->id);',
            $checkoutController
        );
        $this->assertStringContainsString(
            "' It has been removed; please review the updated total.'",
            $checkoutController
        );
        $this->assertStringContainsString('minimum_order_amount: [null, [Validators.min(0)]]', $portalForm);
        $this->assertStringContainsString('usage_limit_per_customer: [1, [Validators.min(1)]]', $portalForm);
        $this->assertStringContainsString('start_date: [null]', $portalForm);
        $this->assertStringContainsString('end_date: [null]', $portalForm);
        $this->assertStringContainsString('is_active: [true]', $portalForm);
    }

    private function cart(string $code, float $discount): Cart
    {
        return new class($code, $discount) extends Cart {
            public ?string $coupon_code;
            public float $coupon_discount;

            public function __construct(string $code, float $discount)
            {
                $this->coupon_code = $code;
                $this->coupon_discount = $discount;
            }

            public function applyCoupon(string $couponCode, float $discount): void
            {
                $this->coupon_code = $couponCode;
                $this->coupon_discount = $discount;
            }

            public function removeCoupon(): void
            {
                $this->coupon_code = null;
                $this->coupon_discount = 0;
            }
        };
    }

    private function promo(array $attributes = []): PromoCode
    {
        $promo = new class extends PromoCode {
            public string $code = '';
            public bool $is_active = true;
            public string $discount_type = PromoCode::DISCOUNT_TYPE_PERCENTAGE;
            public float $discount_value = 0;
            public float $minimum_order_amount = 0;
            public ?int $usage_limit = null;
            public int $usage_count = 0;
            public ?int $usage_limit_per_customer = null;
            public ?Carbon $start_date = null;
            public ?Carbon $end_date = null;

            public function __construct()
            {
            }
        };
        $values = array_merge([
            'code' => 'SAVE10',
            'is_active' => true,
            'discount_type' => PromoCode::DISCOUNT_TYPE_PERCENTAGE,
            'discount_value' => 10,
            'minimum_order_amount' => 0,
            'usage_limit' => null,
            'usage_count' => 0,
            'usage_limit_per_customer' => 1,
        ], $attributes);

        foreach ($values as $key => $value) {
            if (in_array($key, ['start_date', 'end_date'], true) && $value !== null) {
                $value = Carbon::parse($value);
            }
            $promo->{$key} = $value;
        }

        return $promo;
    }

    private function cartService(PromoCodeService $promoService): CartService
    {
        return new class($promoService) extends CartService {
            public function __construct(PromoCodeService $promoService)
            {
                $this->promoCodeService = $promoService;
            }
        };
    }

    private function promoService(
        bool $valid,
        string $errorCode = 'PROMO_CODE_INVALID',
        string $message = 'Invalid promo code.'
    ): PromoCodeService {
        return new class($valid, $errorCode, $message, $this->promo()) extends PromoCodeService {
            /** @var array<int, array{0: string, 1: float, 2: string|null}> */
            public array $validations = [];

            public function __construct(
                private readonly bool $valid,
                private readonly string $errorCode,
                private readonly string $message,
                private readonly PromoCode $promo
            ) {
            }

            public function validatePromoCode(
                string $code,
                float $orderAmount,
                ?string $customerId = null
            ): array {
                $this->validations[] = [$code, $orderAmount, $customerId];

                return $this->valid
                    ? ['valid' => true]
                    : [
                        'valid' => false,
                        'error_code' => $this->errorCode,
                        'message' => $this->message,
                    ];
            }

            public function getByCode(string $code): ?PromoCode
            {
                return $this->promo;
            }

            public function calculateDiscount(PromoCode $promoCode, float $orderAmount): float
            {
                return round($orderAmount * 0.10, 2);
            }
        };
    }
}
