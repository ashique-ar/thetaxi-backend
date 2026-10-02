<?php

it('loads and renders Agent commission booking numbers without exposing booking UUIDs', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Agent/AgentController.php'));
    $agentResource = file_get_contents(app_path('Http/Resources/Agent/AgentResource.php'));
    $commissionResource = file_get_contents(app_path('Http/Resources/Agent/AgentCommissionResource.php'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/admin/agents/detail/agent-detail.component.html'));

    expect($controller)->toContain("'commissions.booking'")
        ->and($agentResource)->toContain("AgentCommissionResource::collection($this->whenLoaded('commissions'))")
        ->and($commissionResource)->toContain("whenLoaded('booking'", 'booking_number')
        ->and($template)->toContain('item.booking_number', 'Booking reference unavailable')
        ->not->toContain('item.booking_id');
});
