<?php

it('recovers task command key races only from a matching locked event', function () {
    $service = file_get_contents(app_path('Services/Sales/SalesCrmService.php'));
    $transitionStart = strpos($service, 'public function transitionTask(');
    $transferStart = strpos($service, 'public function transferTask(', $transitionStart);
    $recoveryStart = strpos($service, 'private function recoverTaskEventReplay(', $transferStart);
    $transition = substr($service, $transitionStart, $transferStart - $transitionStart);
    $transfer = substr($service, $transferStart, $recoveryStart - $transferStart);
    $recovery = substr($service, $recoveryStart, strpos($service, 'private function assertTaskCompanyLinks(', $recoveryStart) - $recoveryStart);

    expect($transition)->toContain('catch (QueryException $exception)', 'recoverTaskEventReplay')
        ->and($transfer)->toContain('catch (QueryException $exception)', 'recoverTaskEventReplay')
        ->and($recovery)->toContain("where('idempotency_key', \$key)->lockForUpdate()", 'assertTaskCompanyLinks($locked)', '$assertReplay($event, $locked)');
});
