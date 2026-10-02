<?php

it('uses the narrow searchable company options endpoints and human-readable autocomplete', function () {
    $service = file_get_contents(base_path('../portal-thetaxi/src/app/shared/services/legal-entity-lookup.service.ts'));
    $selector = file_get_contents(base_path('../portal-thetaxi/src/app/shared/components/ui/legal-entity-select/legal-entity-select.component.ts'));

    expect($service)
        ->toContain('`${this.url}/options`')
        ->toContain('`${this.url}/${id}/option`')
        ->and($selector)
        ->toContain('this.lookup.active(value)')
        ->toContain('this.lookup.selected(this.value)')
        ->toContain('company.name');
});
