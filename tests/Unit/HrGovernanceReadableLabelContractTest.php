<?php

it('does not expose database identifiers as governance queue labels', function () {
    $source = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-engagement/components/governance/governance.component.ts'));

    expect($source)->toContain("row.event_type || 'Review item'")
        ->not->toContain('|| row.id');
});
