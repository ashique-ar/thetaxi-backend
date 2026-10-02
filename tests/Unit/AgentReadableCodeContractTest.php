<?php

it('uses the Agent code instead of the internal ID in the Agent list', function () {
    $resource = file_get_contents(app_path('Http/Resources/Agent/AgentResource.php'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/agent/components/agent-list/agent-list.component.html'));

    expect($resource)->toContain("'code' => \$this->code")
        ->and($template)->toContain('agent.code', 'Agent code unavailable')
        ->not->toContain('ID: {{agent.id}}');
});
