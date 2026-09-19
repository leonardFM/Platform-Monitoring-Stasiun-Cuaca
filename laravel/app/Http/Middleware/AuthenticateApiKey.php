<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    public function __construct(private readonly DatabaseManager $db)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('X-API-Key');

        if ($key === null || $key === '') {
            throw ApiException::unauthorized('missing or invalid x-api-key header');
        }

        $device = $this->db->selectOne(
            'SELECT id, name, calibration FROM devices WHERE api_key = ? AND is_active = TRUE',
            [$key],
        );

        if (! $device) {
            throw ApiException::unauthorized('invalid or inactive API key');
        }

        $request->attributes->set('device', $device);

        return $next($request);
    }
}