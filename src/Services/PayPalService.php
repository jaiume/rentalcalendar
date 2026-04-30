<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Thin wrapper around the PayPal Orders v2 REST API used by the laundry
 * payment flow. All amounts come from the resolved PortalGroup, never
 * from client input. OAuth access tokens are cached per-process for
 * the small number of calls made within a single request.
 *
 * Sandbox base: https://api-m.sandbox.paypal.com
 * Live base:    https://api-m.paypal.com
 */
class PayPalService
{
    private const SANDBOX_BASE = 'https://api-m.sandbox.paypal.com';
    private const LIVE_BASE    = 'https://api-m.paypal.com';

    private ?string $cachedAccessToken = null;
    private int $cachedTokenExpiresAt = 0;

    public function __construct(
        private readonly Client $http
    ) {
    }

    public function env(): string
    {
        $env = (string) ConfigService::get('paypal.env', 'sandbox');
        return $env === 'live' ? 'live' : 'sandbox';
    }

    public function clientId(): string
    {
        $id = (string) ConfigService::get('paypal.client_id', '');
        if ($id === '') {
            throw new \RuntimeException('PayPal client_id is not configured (config.ini [paypal] client_id)');
        }
        return $id;
    }

    private function clientSecret(): string
    {
        $secret = (string) ConfigService::get('paypal.secret', '');
        if ($secret === '') {
            throw new \RuntimeException('PayPal secret is not configured (config.ini [paypal] secret)');
        }
        return $secret;
    }

    private function baseUrl(): string
    {
        return $this->env() === 'live' ? self::LIVE_BASE : self::SANDBOX_BASE;
    }

    /**
     * Convert integer cents to the decimal string PayPal expects, e.g.
     * 500 -> "5.00", 1234 -> "12.34". Currencies with non-2 decimal
     * places aren't supported by the laundry flow.
     */
    public static function centsToDecimalString(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /**
     * Convert a PayPal decimal string (e.g. "5.00") back to integer cents.
     */
    public static function decimalStringToCents(string $decimal): int
    {
        return (int) round(((float) $decimal) * 100);
    }

    /**
     * Create a PayPal order. Returns the parsed response body.
     *
     * @return array<string, mixed>
     */
    public function createOrder(int $amountCents, string $currency, string $description): array
    {
        $body = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'amount' => [
                    'currency_code' => strtoupper($currency),
                    'value' => self::centsToDecimalString($amountCents),
                ],
                'description' => substr($description, 0, 127),
            ]],
            'application_context' => [
                'shipping_preference' => 'NO_SHIPPING',
                'user_action' => 'PAY_NOW',
            ],
        ];

        $response = $this->request('POST', '/v2/checkout/orders', $body);
        return $this->decodeJson($response);
    }

    /**
     * Capture a previously-created PayPal order. Returns the parsed
     * response body. PayPal returns 422 with `ORDER_ALREADY_CAPTURED`
     * if a duplicate capture is attempted; the caller should treat
     * that as success and read the existing capture details via
     * getOrder() instead.
     *
     * @return array<string, mixed>
     */
    public function captureOrder(string $orderId): array
    {
        $response = $this->request('POST', sprintf('/v2/checkout/orders/%s/capture', urlencode($orderId)));
        return $this->decodeJson($response);
    }

    /**
     * Read an order's current state.
     *
     * @return array<string, mixed>
     */
    public function getOrder(string $orderId): array
    {
        $response = $this->request('GET', sprintf('/v2/checkout/orders/%s', urlencode($orderId)));
        return $this->decodeJson($response);
    }

    /**
     * Returns true if the PayPal API response indicates the order has
     * already been captured. Used to swallow 422 ORDER_ALREADY_CAPTURED
     * during refresh/replay.
     *
     * @param array<string, mixed> $body
     */
    public static function isAlreadyCapturedError(array $body): bool
    {
        if (!isset($body['details']) || !is_array($body['details'])) {
            return false;
        }
        foreach ($body['details'] as $detail) {
            if (!is_array($detail)) {
                continue;
            }
            if (($detail['issue'] ?? null) === 'ORDER_ALREADY_CAPTURED') {
                return true;
            }
        }
        return false;
    }

    /**
     * Convenience: extract the captured amount + currency from a v2 capture
     * response (or a getOrder() response with COMPLETED status).
     *
     * @param array<string, mixed> $body
     * @return array{amount_cents: int, currency: string}|null
     */
    public static function extractCapturedAmount(array $body): ?array
    {
        $units = $body['purchase_units'] ?? null;
        if (!is_array($units)) {
            return null;
        }
        foreach ($units as $unit) {
            $captures = $unit['payments']['captures'] ?? null;
            if (!is_array($captures)) {
                continue;
            }
            foreach ($captures as $capture) {
                if (($capture['status'] ?? null) !== 'COMPLETED') {
                    continue;
                }
                $amount = $capture['amount'] ?? null;
                if (!is_array($amount)) {
                    continue;
                }
                return [
                    'amount_cents' => self::decimalStringToCents((string) ($amount['value'] ?? '0')),
                    'currency' => strtoupper((string) ($amount['currency_code'] ?? '')),
                ];
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function extractPayerEmail(array $body): ?string
    {
        $email = $body['payer']['email_address'] ?? null;
        if (is_string($email) && $email !== '') {
            return $email;
        }
        return null;
    }

    /**
     * Issue an authenticated PayPal API call. Throws on non-2xx unless
     * the body is parseable JSON, in which case the body is still
     * returned (so callers can inspect e.g. `details[].issue`). Network
     * failures bubble up as RuntimeException.
     */
    private function request(string $method, string $path, array $body = []): string
    {
        $token = $this->getAccessToken();

        try {
            $options = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'PayPal-Request-Id' => bin2hex(random_bytes(16)),
                ],
                'http_errors' => false,
                'timeout' => 15,
            ];
            if (!empty($body)) {
                $options['json'] = $body;
            }

            $response = $this->http->request($method, $this->baseUrl() . $path, $options);
        } catch (GuzzleException $e) {
            LogService::exception($e, 'PayPal HTTP request failed');
            throw new \RuntimeException('PayPal API call failed: ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        $payload = (string) $response->getBody();

        if ($status >= 200 && $status < 300) {
            return $payload;
        }

        // Non-2xx: surface the body so the caller can inspect PayPal's
        // structured error (e.g. ORDER_ALREADY_CAPTURED). For bookkeeping
        // we also log it.
        LogService::warning('PayPal API non-2xx response', [
            'method' => $method,
            'path' => $path,
            'status' => $status,
            'body' => $payload,
        ]);

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $payload): array
    {
        if ($payload === '') {
            return [];
        }
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('PayPal returned a non-JSON body: ' . substr($payload, 0, 200));
        }
        return $decoded;
    }

    private function getAccessToken(): string
    {
        if ($this->cachedAccessToken !== null && $this->cachedTokenExpiresAt > time() + 30) {
            return $this->cachedAccessToken;
        }

        try {
            $response = $this->http->request('POST', $this->baseUrl() . '/v1/oauth2/token', [
                'auth' => [$this->clientId(), $this->clientSecret()],
                'headers' => [
                    'Accept' => 'application/json',
                    'Accept-Language' => 'en_US',
                ],
                'form_params' => ['grant_type' => 'client_credentials'],
                'http_errors' => true,
                'timeout' => 15,
            ]);
        } catch (GuzzleException $e) {
            LogService::exception($e, 'PayPal OAuth token request failed');
            throw new \RuntimeException('PayPal authentication failed: ' . $e->getMessage(), 0, $e);
        }

        $body = json_decode((string) $response->getBody(), true);
        if (!is_array($body) || !isset($body['access_token']) || !isset($body['expires_in'])) {
            throw new \RuntimeException('PayPal OAuth response was malformed');
        }

        $this->cachedAccessToken = (string) $body['access_token'];
        $this->cachedTokenExpiresAt = time() + (int) $body['expires_in'];
        return $this->cachedAccessToken;
    }
}
