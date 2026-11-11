<?php

namespace App\Http\Responses;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Unified JSON error format of the API (plan §6):
 *
 *   { "error": { "code": "validation", "message": "...", "details": {...} } }
 *
 * Codes: validation, unauthorized, forbidden, rate_limited, duplicate,
 * not_found, method_not_allowed, payload_too_large, server_error.
 */
final class ApiError
{
    /**
     * @param  array<mixed>|null  $details
     * @param  array<string, string>  $headers
     */
    public static function make(string $code, string $message, int $status, ?array $details = null, array $headers = []): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== null) {
            $error['details'] = $details;
        }

        return new JsonResponse(['error' => $error], $status, $headers);
    }

    public static function fromException(Throwable $e): Response
    {
        if ($e instanceof HttpResponseException) {
            return $e->getResponse(); // already a complete response (e.g. throttle)
        }

        return match (true) {
            $e instanceof ValidationException => self::make('validation', 'The given data was invalid.', 422, $e->errors()),
            $e instanceof AuthenticationException => self::make('unauthorized', 'Missing or invalid credentials.', 401),
            $e instanceof AuthorizationException => self::make('forbidden', 'This action is not allowed.', 403),
            $e instanceof ThrottleRequestsException => self::make('rate_limited', 'Too many requests.', 429, null, self::headers($e)),
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => self::make('not_found', 'Resource not found.', 404),
            $e instanceof MethodNotAllowedHttpException => self::make('method_not_allowed', 'This method is not supported. Audit logs cannot be modified or deleted.', 405, null, self::headers($e)),
            $e instanceof PostTooLargeException => self::make('payload_too_large', 'The request body is too large.', 413),
            $e instanceof HttpExceptionInterface => self::make(self::codeFor($e->getStatusCode()), $e->getMessage() ?: 'HTTP error.', $e->getStatusCode(), null, self::headers($e)),
            default => self::make('server_error', config('app.debug') ? $e->getMessage() : 'Internal server error.', 500),
        };
    }

    /** @return array<string, string> */
    private static function headers(Throwable $e): array
    {
        return $e instanceof HttpExceptionInterface ? array_map(fn ($v) => is_array($v) ? implode(', ', $v) : (string) $v, $e->getHeaders()) : [];
    }

    private static function codeFor(int $status): string
    {
        return match ($status) {
            400 => 'bad_request',
            401 => 'unauthorized',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            409 => 'conflict',
            413 => 'payload_too_large',
            415 => 'unsupported_media_type',
            429 => 'rate_limited',
            503 => 'unavailable',
            default => $status >= 500 ? 'server_error' : 'error',
        };
    }
}
