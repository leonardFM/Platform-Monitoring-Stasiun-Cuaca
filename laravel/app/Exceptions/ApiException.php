<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * API error carrying the wire-level error code and HTTP status used by the
 * platform. Rendered as {"error":{"code":...,"message":...}}.
 */
class ApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
    ) {
        parent::__construct($message, $status);
    }

    public static function badRequest(string $message): self
    {
        return new self('bad_request', $message, 400);
    }

    public static function unauthorized(string $message): self
    {
        return new self('unauthorized', $message, 401);
    }

    public static function payloadTooLarge(string $message): self
    {
        return new self('payload_too_large', $message, 413);
    }

    public static function tooManyRequests(string $message): self
    {
        return new self('too_many_requests', $message, 429);
    }

    public static function conflict(string $message): self
    {
        return new self('conflict', $message, 409);
    }

    public static function notFound(string $message): self
    {
        return new self('not_found', $message, 404);
    }

    public static function internal(string $message): self
    {
        return new self('internal', $message, 500);
    }

    public static function broker(string $message): self
    {
        return new self('broker_unavailable', $message, 503);
    }
}