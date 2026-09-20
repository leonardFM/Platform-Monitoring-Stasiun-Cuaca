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

        // Get all active credentials for this API key
        $credentials = $this->db->select(
            'SELECT d.id, d.device_code, d.name, d.status, d.firmware_version,
                    c.id AS credential_id, c.api_key, c.secret_hash
             FROM device_credentials c
             JOIN devices d ON d.id = c.device_id
             WHERE c.api_key = ?
               AND c.revoked_at IS NULL
               AND d.status = ?
               AND d.deleted_at IS NULL
             ORDER BY c.created_at ASC',
            [$key, 'active'],
        );

        if ($credentials === []) {
            throw ApiException::unauthorized('invalid or inactive API key');
        }

        // Try to match device_id from request payload (for POST ingest endpoints)
        $payloadDeviceId = null;
        if ($request->isMethod('POST') && $request->getContent()) {
            $content = json_decode($request->getContent(), true);
            if (is_array($content) && isset($content['device_id'])) {
                $payloadDeviceId = $content['device_id'];
            }
        }

        // Find matching credential
        $device = null;
        if ($payloadDeviceId !== null) {
            foreach ($credentials as $cred) {
                if ($cred->device_code === $payloadDeviceId || $cred->id === $payloadDeviceId) {
                    $device = $cred;
                    break;
                }
            }
        }

        // Fallback to first credential
        if ($device === null) {
            $device = $credentials[0];
        }

        $this->db->update(
            'UPDATE device_credentials SET last_used_at = now() WHERE id = ?',
            [$device->credential_id],
        );

        $request->attributes->set('device', $device);

        return $next($request);
    }
}