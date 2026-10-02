<?php

it('keeps API key IDs internal to Agent API actions', function () {
    $list = file_get_contents(base_path('../portal-thetaxi/src/app/modules/agent/components/api-management/api-management.component.html'));
    $usage = file_get_contents(base_path('../portal-thetaxi/src/app/modules/agent/components/api-management/api-key-usage-dialog/api-key-usage-dialog.component.html'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/agent/components/api-management/api-key-usage-dialog/api-key-usage-dialog.component.ts'));

    expect($list)->toContain('{{ apiKey.name }}')
        ->not->toContain('{{ apiKey.id }}')
        ->and($usage)->toContain('data.apiKey.name')
        ->not->toContain('{{ data.apiKey.id }}')
        ->and($component)->toContain('getApiKeyUsage(apiKey.id)')
        ->toContain('getApiKeyLogs(apiKey.id');
});
