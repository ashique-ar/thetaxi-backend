<?php

namespace App\Services\Payment;

use App\Contracts\PaymentGatewayInterface;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves the appropriate payment gateway from the method name.
 *
 * Add new gateways to the $map array and bind them in a service provider.
 */
class PaymentGatewayManager
{
    /** method name → gateway class */
    private array $map = [
        'webxpay'       => WebXPayGateway::class,
        'credit_card'   => WebXPayGateway::class,
        'debit_card'    => WebXPayGateway::class,
        'bank_transfer' => WebXPayGateway::class,
    ];

    public function __construct(private readonly Container $container) {}

    /**
     * Resolve a gateway for the given payment method.
     * Returns the first enabled gateway when method is 'default'.
     *
     * @throws \InvalidArgumentException when the method is unknown or disabled
     */
    public function resolve(string $paymentMethod): PaymentGatewayInterface
    {
        $class = $this->map[strtolower($paymentMethod)] ?? null;

        if (!$class) {
            throw new \InvalidArgumentException("No payment gateway registered for method: {$paymentMethod}");
        }

        /** @var PaymentGatewayInterface $gateway */
        $gateway = $this->container->make($class);

        return $gateway;
    }

    /** Returns all registered gateway names. */
    public function available(): array
    {
        $seen  = [];
        $names = [];

        foreach ($this->map as $method => $class) {
            if (!isset($seen[$class])) {
                try {
                    $gw = $this->container->make($class);
                    if ($gw->isEnabled()) {
                        $names[] = ['method' => $method, 'gateway' => $gw->getName(), 'enabled' => true];
                    }
                    $seen[$class] = true;
                } catch (\Throwable) {
                    // Gateway not bound
                }
            }
        }

        return $names;
    }
}
