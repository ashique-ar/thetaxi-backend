<?php

beforeEach(function () {
    $root = dirname(__DIR__, 2);
    $this->tokenService = file_get_contents($root . '/../portal-thetaxi/src/app/core/auth/token.service.ts');
    $this->authService = file_get_contents($root . '/../portal-thetaxi/src/app/core/auth/auth.service.ts');
    $this->backendAuth = file_get_contents($root . '/app/Services/AuthService.php');
});

it('reuses one browser session across reloads tabs and token rotations', function () {
    expect($this->tokenService)
        ->toContain('localStorage.setItem(this.ACCESS_TOKEN_KEY, token)')
        ->toContain('localStorage.setItem(this.TOKEN_EXPIRES_AT_KEY, expiresAt)');

    expect($this->authService)
        ->toContain("navigator.locks.request('portal-auth-token-refresh'")
        ->toContain('refreshToken !== this._tokenService.getRefreshToken()');

    expect($this->backendAuth)
        ->toContain("ApiSession::where('token_id', \$token->id)->latest()->first()")
        ->toContain('$apiSession->update($meta)');
});
