<?php

it('returns minimal confirmations for CRM writes whose portal callers reload their records', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesCrmController.php'));

    expect($controller)
        ->toContain(
            'private function writeConfirmation(object $row): array',
            "['id' => (string) \$row->id]",
            "\$confirmation['status'] = (string) \$row->status",
            "\$confirmation['version'] = (int) \$row->state_version",
            '$this->writeConfirmation($crm->createOpportunity(',
            '$this->writeConfirmation($crm->transition(',
            '$this->writeConfirmation($crm->transfer(',
            '$this->writeConfirmation($crm->linkBooking(',
            '$this->writeConfirmation($crm->recordActivity(',
            '$this->writeConfirmation($crm->createTask(',
            '$this->writeConfirmation($crm->transitionTask(',
            '$this->writeConfirmation($crm->transferTask(',
        )
        ->not->toContain(
            "'data' => \$crm->createOpportunity(",
            "'data' => \$crm->recordActivity(",
            "'data' => \$crm->createTask(",
        );
});
