<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ToastAutoDismissContractTest extends TestCase
{
    #[Test]
    public function website_toasts_use_bootstrap_5_compatible_auto_dismissal(): void
    {
        foreach ([
            'resources/views/components/cart-summary-float.blade.php',
            'resources/views/components/vehicle-card-scripts.blade.php',
            'resources/views/rate-chart.blade.php',
        ] as $view) {
            $source = file_get_contents(base_path($view));

            $this->assertStringNotContainsString(".alert('close')", $source, $view);
            $this->assertStringContainsString('setTimeout(() => alert.remove()', $source, $view);
        }
    }

    #[Test]
    public function cart_notification_is_removed_after_it_hides(): void
    {
        $source = file_get_contents(base_path('resources/views/layouts/app.blade.php'));

        $this->assertStringContainsString('clearTimeout(notification.hideTimer)', $source);
        $this->assertStringContainsString('clearTimeout(notification.removeTimer)', $source);
        $this->assertStringContainsString('notification.removeTimer = setTimeout(() => notification.remove(), 300)', $source);
    }
}
