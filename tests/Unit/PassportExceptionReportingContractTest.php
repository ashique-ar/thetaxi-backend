<?php

use League\OAuth2\Server\Exception\OAuthServerException;

it('does not report routine Passport bearer token rejections as application errors', function () {
    $bootstrap = file_get_contents(dirname(__DIR__, 2).'/bootstrap/app.php');

    expect($bootstrap)
        ->toContain('use League\\OAuth2\\Server\\Exception\\OAuthServerException;')
        ->toContain('$exceptions->dontReportWhen(')
        ->toContain('$exception instanceof OAuthServerException')
        ->toContain('$exception->getCode() === 9');
});
