<?php

declare(strict_types=1);

namespace Ecotech\Chat;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * OAuth-style token management for api.ecotechcrm.ca/Api/Authenticate.
 */
final class CrmAuth
{
    private ?string $token = null;
    private ?int $expiresAt = null;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $clientId,
        private readonly string $username,
        private readonly string $password,
        private readonly int $timeout = 30,
    ) {
    }

    public function getToken(): ?string
    {
        if ($this->token && $this->expiresAt && time() < $this->expiresAt - 60) {
            return $this->token;
        }

        return $this->authenticate();
    }

    public function clearToken(): void
    {
        $this->token = null;
        $this->expiresAt = null;
    }

    private function authenticate(): ?string
    {
        $http = new Client(['timeout' => $this->timeout]);

        try {
            $response = $http->post(rtrim($this->baseUrl, '/') . '/Authenticate', [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'X-Client-Id' => $this->clientId,
                ],
                'form_params' => [
                    'username' => $this->username,
                    'password' => $this->password,
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);
            if (!is_array($data)) {
                return null;
            }

            $token = $data['access_token'] ?? $data['accessToken'] ?? $data['token'] ?? null;
            if (!$token || !empty($data['error'])) {
                return null;
            }

            $this->token = (string) $token;
            $ttl = (int) ($data['expires_in'] ?? 3600);
            $this->expiresAt = time() + $ttl;

            return $this->token;
        } catch (GuzzleException) {
            return null;
        }
    }
}
