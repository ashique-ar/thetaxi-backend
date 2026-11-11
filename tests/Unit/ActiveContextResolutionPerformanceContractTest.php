<?php

// Request-time callers (booking-flow redaction, EnsureInternalContext, customer-portal guards) only read
// id/context_type/context_id/portal_profile. Building role/permission lists there cost ~300 ms per admin request.
it('resolves the active request context without formatting roles and permissions', function () {
    $source = file_get_contents(app_path('Services/UserContextService.php'));

    expect($source)
        ->toContain('$contexts ?? $this->getSelectableContexts($user, false)')
        ->toContain('private function getSelectableContexts(User $user, bool $withAccess = true): array');
});
