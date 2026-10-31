<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<Throwable>>
     */
    protected $dontReport = [
        BusinessRuleException::class,
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    /**
     * Render an exception into an HTTP response.
     *
     * Every /api/* error uses one JSON shape:
     * { "message": "...", "code": "...", "errors": { field: [...] } }
     * Internal details (SQL, stack traces, file paths) are never included;
     * they are written to the log instead.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function render($request, Throwable $e)
    {
        if ($request->is('api/*')) {
            return $this->renderApiException($e);
        }

        return parent::render($request, $e);
    }

    protected function renderApiException(Throwable $e): JsonResponse
    {
        if ($e instanceof ValidationException) {
            return $this->apiError(422, 'validation_failed', 'Some of the information provided is not valid.', $e->errors());
        }

        if ($e instanceof BusinessRuleException) {
            return $this->apiError($e->status(), $e->errorCode(), $e->getMessage());
        }

        if ($e instanceof AuthenticationException) {
            return $this->apiError(401, 'unauthenticated', 'Please sign in to continue.');
        }

        if ($e instanceof AuthorizationException || $e instanceof AccessDeniedHttpException) {
            return $this->apiError(403, 'forbidden', 'You do not have permission to do this.');
        }

        if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
            return $this->apiError(404, 'not_found', 'The requested item was not found.');
        }

        if ($e instanceof TokenMismatchException) {
            return $this->apiError(419, 'session_expired', 'Your session has expired. Please refresh and try again.');
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $codes = [405 => 'method_not_allowed', 429 => 'too_many_requests', 503 => 'unavailable'];
            $messages = [429 => 'Too many requests. Please wait a moment and try again.'];

            return $this->apiError(
                $status,
                $codes[$status] ?? 'http_error',
                $messages[$status] ?? (Response::$statusTexts[$status] ?? 'Request failed.')
            )->withHeaders($e->getHeaders());
        }

        return $this->apiError(500, 'server_error', 'Something went wrong on our side. Please try again.');
    }

    protected function apiError(int $status, string $code, string $message, array $errors = []): JsonResponse
    {
        $body = ['message' => $message, 'code' => $code];

        if ($errors) {
            $body['errors'] = $errors;
        }

        return new JsonResponse($body, $status);
    }
}
