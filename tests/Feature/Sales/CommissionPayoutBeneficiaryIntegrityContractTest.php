<?php

it('validates each payout statement against its frozen Sales Profile beneficiary before writing', function () {
    $service = file_get_contents(app_path('Services/Sales/CommissionPayoutService.php'));

    expect($service)
        ->toContain('$this->assertFrozenProfileBeneficiaries($statements);')
        ->toContain('private function assertFrozenProfileBeneficiaries($statements): void')
        ->toContain("->lockForUpdate()->get()->keyBy('id')")
        ->toContain("(string) \$profiles->get(\$statement->sales_profile_id)->company_id !== (string) \$statement->company_id")
        ->toContain("(string) \$profiles->get(\$statement->sales_profile_id)->staff_id !== (string) \$statement->staff_id")
        ->toContain('Every statement must match its frozen Sales Profile company and Staff beneficiary.')
        ->toContain("(string) \$statement->company_id !== (string) \$original->company_id")
        ->toContain("(string) \$statement->staff_id !== (string) \$original->staff_id")
        ->toContain('A payout without statement allocations cannot be reversed.')
        ->toContain("whereIn('id', \$statementIds)\n                ->orderBy('id')->lockForUpdate()->get();")
        ->toContain("whereIn('id', \$statementIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id')")
        ->toContain("sortBy([['period_end', 'asc'], ['id', 'asc']])->values()")
        ->toContain("round((float) \$allocations->sum('amount_lkr'), 4)")
        ->toContain('Payout reversal allocations must reconcile to the original payout amount.')
        ->toContain('Payout reversal allocation exceeds its statement paid balance.');
});
