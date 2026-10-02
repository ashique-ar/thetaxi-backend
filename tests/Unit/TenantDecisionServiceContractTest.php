<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class TenantDecisionServiceContractTest extends TestCase
{
    public function test_runtime_decisions_are_approved_only_and_company_scoped(): void
    {
        $source = file_get_contents(base_path('app/Services/TenantDecisionService.php'));
        $this->assertStringContainsString("status'] ?? null) !== 'approved'", $source);
        $this->assertStringContainsString("where('company_id', \$companyId)", $source);
        $this->assertStringContainsString('array_diff(array_keys($value), $allowed)', $source);
        $this->assertStringContainsString("'timezone' => is_string(\$input) && in_array(\$input, timezone_identifiers_list(), true)", $source);
        $this->assertStringContainsString("'select' => is_string(\$input) && in_array(\$input, \$field['options'] ?? [], true)", $source);
        $this->assertStringContainsString("where('status', 'approved')", $source);
        $this->assertStringContainsString("whereNull('effective_from')->orWhere('effective_from', '<=', \$today)", $source);
        $this->assertStringContainsString('if (! $version) return $default;', $source);
    }
}
