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
    }
}
