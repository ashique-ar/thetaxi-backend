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
}
