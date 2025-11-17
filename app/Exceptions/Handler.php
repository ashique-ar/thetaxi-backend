<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Session\TokenMismatchException;
use Throwable;
use Illuminate\Http\Request;

class Handler extends ExceptionHandler
{
    /**
     * Render an exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Throwable  $exception
     * @return \Illuminate\Http\Response
     */
    public function render($request, Throwable $exception)
    {
        if ($exception instanceof TokenMismatchException) {
            // Preserve old input and flash error message to session, then return an Illuminate\Http\Response redirecting back.
            session()->flashInput($request->input());
            session()->flash('error', 'Your session expired. Please try again.');

            return new \Illuminate\Http\Response('', 302, ['Location' => url()->previous()]);
        }

        return parent::render($request, $exception);
    }
}