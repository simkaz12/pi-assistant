<?php

namespace App\Modules\Oura\Services;

use App\Modules\Oura\Models\OuraConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OuraClient
{
    private const API = 'https://api.ouraring.com';

    private const TOKEN = 'https://moi.ouraring.com/oauth/v2/ext/oauth-token';

    private const OAUTH_ERRORS = [
        'invalid_request',
        'invalid_client',
        'invalid_grant',
        'unauthorized_client',
        'unsupported_grant_type',
        'invalid_scope',
        'access_denied',
        'unsupported_response_type',
        'server_error',
        'temporarily_unavailable',
    ];

    public function exchange(string $code): array
    {
        try {
            $response = Http::asForm()->timeout(10)->post(self::TOKEN, [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => config('services.oura.redirect_uri'),
                'client_id' => config('services.oura.client_id'),
                'client_secret' => config('services.oura.client_secret'),
            ]);
        } catch (ConnectionException) {
            throw new OuraOAuthExchangeException('authorization_transport');
        }

        if (! $response->successful()) {
            $error = $response->json('error');
            $errorCode = 'authorization_http_'.$response->status();

            if (is_string($error) && in_array($error, self::OAUTH_ERRORS, true)) {
                $errorCode .= '_'.$error;
            }

            throw new OuraOAuthExchangeException($errorCode);
        }

        try {
            return $this->credentials($response);
        } catch (RuntimeException) {
            throw new OuraOAuthExchangeException('authorization_malformed_response');
        }
    }

    public function request(OuraConnection $connection, string $method, string $path, array $query = []): Response
    {
        $this->ensureFresh($connection);
        $response = Http::withToken($connection->access_token)
            ->timeout(20)
            ->send($method, self::API.$path, ['query' => $query]);

        if ($response->status() === 401) {
            $this->ensureFresh($connection, true);
            $response = Http::withToken($connection->access_token)
                ->timeout(20)
                ->send($method, self::API.$path, ['query' => $query]);
        }

        return $response;
    }

    private function ensureFresh(OuraConnection $connection, bool $force = false): void
    {
        if (! $force && $connection->expires_at?->isAfter(now()->addMinute())) {
            return;
        }

        $attempt = DB::transaction(function () use ($connection, $force): array {
            $locked = OuraConnection::query()->lockForUpdate()->findOrFail($connection->id);

            if ($locked->status === 'refreshing') {
                if ($locked->refresh_started_at?->isBefore(now()->subSeconds(30))) {
                    $locked->update([
                        'status' => 'reconnect_required',
                        'refresh_started_at' => null,
                        'error_code' => 'refresh_uncertain',
                    ]);

                    return ['action' => 'reconnect'];
                }

                return ['action' => 'wait'];
            }

            if ($locked->status !== 'connected' || ! $locked->refresh_token) {
                return ['action' => 'reconnect'];
            }

            if ($locked->access_token !== $connection->access_token) {
                $connection->setRawAttributes($locked->getAttributes(), true);

                return ['action' => 'ready'];
            }

            if (! $force && $locked->expires_at?->isAfter(now()->addMinute())) {
                $connection->setRawAttributes($locked->getAttributes(), true);

                return ['action' => 'ready'];
            }

            $refreshToken = $locked->refresh_token;
            $locked->update([
                'status' => 'refreshing',
                'refresh_started_at' => now(),
                'error_code' => null,
            ]);

            return [
                'action' => 'refresh',
                'generation' => $locked->generation,
                'refresh_token' => $refreshToken,
            ];
        });

        if ($attempt['action'] === 'ready') {
            return;
        }

        if ($attempt['action'] === 'wait') {
            throw new OuraRetryLater(5);
        }

        if ($attempt['action'] === 'reconnect') {
            throw new RuntimeException('Oura connection needs authorization.');
        }

        try {
            $response = Http::asForm()->timeout(10)->post(self::TOKEN, [
                'grant_type' => 'refresh_token',
                'refresh_token' => $attempt['refresh_token'],
                'client_id' => config('services.oura.client_id'),
                'client_secret' => config('services.oura.client_secret'),
            ]);
            $credentials = $response->successful() ? $this->credentials($response) : null;
        } catch (\Throwable) {
            $credentials = null;
        }

        DB::transaction(function () use ($connection, $attempt, $credentials): void {
            $locked = OuraConnection::query()->lockForUpdate()->findOrFail($connection->id);

            if ($locked->generation !== $attempt['generation'] || $locked->status !== 'refreshing') {
                throw new RuntimeException('Oura connection needs authorization.');
            }

            if ($credentials === null) {
                $locked->update([
                    'status' => 'reconnect_required',
                    'refresh_started_at' => null,
                    'error_code' => 'refresh_uncertain',
                ]);

                return;
            }

            $locked->update($credentials + [
                'status' => 'connected',
                'refresh_started_at' => null,
                'error_code' => null,
            ]);
            $connection->setRawAttributes($locked->getAttributes(), true);
        });

        if ($credentials === null) {
            throw new RuntimeException('Oura connection needs authorization.');
        }
    }

    private function credentials(Response $response): array
    {
        $access = $response->json('access_token');
        $refresh = $response->json('refresh_token');
        $expires = $response->json('expires_in');

        if (! is_string($access) || ! is_string($refresh) || ! is_numeric($expires)) {
            throw new RuntimeException('Oura returned incomplete credentials.');
        }

        $credentials = [
            'access_token' => $access,
            'refresh_token' => $refresh,
            'expires_at' => now()->addSeconds(max(1, (int) $expires)),
        ];

        $scope = $response->json('scope');
        if ($scope !== null) {
            if (! is_string($scope)) {
                throw new RuntimeException('Oura returned invalid scope.');
            }

            $credentials['scopes'] = OuraConnectionService::normalizeScopes($scope);
        }

        return $credentials;
    }
}
