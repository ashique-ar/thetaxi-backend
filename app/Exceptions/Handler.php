<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;
use Illuminate\Http\Request;

class Handler extends ExceptionHandler
{
    /**
     * Render an exception into an HTTP response.
     */
    public function render($request, Throwable $exception)
    {
        if ($exception instanceof TokenMismatchException && !$request->expectsJson()) {
            session()->flashInput($request->input());
            session()->flash('error', 'Your session expired. Please try again.');
            return new \Illuminate\Http\Response('', 302, ['Location' => url()->previous()]);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return $this->renderApiException($exception);
        }

        return parent::render($request, $exception);
    }

    private function renderApiException(Throwable $exception): JsonResponse
    {
        if ($exception instanceof ValidationException) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $exception->errors(),
            ], 422);
        }

        if ($exception instanceof AuthenticationException) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthenticated',
            ], 401);
        }

        if ($exception instanceof AuthorizationException) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized',
            ], 403);
        }

        if ($exception instanceof ModelNotFoundException) {
            $model = class_basename($exception->getModel());
            return response()->json([
                'status' => 'error',
                'message' => "{$model} not found",
            ], 404);
        }

        if ($exception instanceof HttpException) {
            return response()->json([
                'status' => 'error',
                'message' => $exception->getMessage() ?: 'HTTP error',
            ], $exception->getStatusCode());
        }

        $statusCode = method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 500;

        return response()->json([
            'status' => 'error',
            'message' => app()->environment('production')
                ? 'An unexpected error occurred'
                : $exception->getMessage(),
        ], $statusCode);
    }
}