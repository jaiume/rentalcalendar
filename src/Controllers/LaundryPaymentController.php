<?php

namespace App\Controllers;

use App\DAO\PaymentDAO;
use App\Models\PortalGroup;
use App\Services\AdminNotificationService;
use App\Services\LogService;
use App\Services\PayPalService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;

/**
 * Laundry payment endpoints — server side of the PayPal Smart Buttons
 * flow. Guests never see the padlock combination until a successful
 * `capture` confirms the payment with PayPal and matches the captured
 * amount/currency to the row this controller created.
 *
 * The amount and currency come exclusively from the resolved PortalGroup;
 * client-supplied amounts are ignored. The Origin header (when present)
 * must match the request Host as a lightweight CSRF defence — the rest
 * of the app has no CSRF tokens, so this matches that pattern.
 */
class LaundryPaymentController
{
    public function __construct(
        private readonly PDO $db,
        private readonly PayPalService $paypal,
        private readonly PaymentDAO $paymentDao,
        private readonly AdminNotificationService $adminNotifier
    ) {
    }

    public function createOrder(Request $request, Response $response): Response
    {
        $portal = $this->portal($request);
        if (!$portal->laundryEnabled()) {
            return $this->jsonResponse(['error' => 'Laundry is not available'], 404);
        }
        if (!$this->originMatchesHost($request)) {
            return $this->jsonResponse(['error' => 'Bad request origin'], 400);
        }

        $laundry = $portal->laundry();
        $amountCents = (int) ($laundry['price_cents'] ?? 0);
        $currency = strtoupper((string) ($laundry['currency'] ?? 'USD'));
        $description = sprintf('Laundry access — %s', $portal->name());

        $body = (array) $request->getParsedBody();
        $propertyIdInput = isset($body['property_id']) ? (int) $body['property_id'] : 0;
        $itemReference = ($propertyIdInput > 0 && $portal->hasProperty($propertyIdInput))
            ? (string) $propertyIdInput
            : null;

        $this->db->beginTransaction();
        try {
            $orderResponse = $this->paypal->createOrder($amountCents, $currency, $description);
            $orderId = is_array($orderResponse) ? ($orderResponse['id'] ?? null) : null;
            $orderStatus = is_array($orderResponse) ? ($orderResponse['status'] ?? null) : null;

            if (!is_string($orderId) || $orderId === '' || $orderStatus !== 'CREATED') {
                $this->db->rollBack();
                LogService::warning('PayPal order create returned unexpected payload', [
                    'portal_slug' => $portal->slug(),
                    'response' => $orderResponse,
                ]);
                return $this->jsonResponse(
                    ['error' => 'Payment service unavailable, please try again.'],
                    502
                );
            }

            $this->paymentDao->create([
                'portal_group_id' => $portal->id(),
                'item_type' => 'laundry_access',
                'item_reference' => $itemReference,
                'description' => $description,
                'paypal_order_id' => $orderId,
                'provider' => 'paypal',
                'amount_cents' => $amountCents,
                'currency' => $currency,
            ]);
            $this->db->commit();

            LogService::info('PayPal laundry order created', [
                'paypal_order_id' => $orderId,
                'portal_group_id' => $portal->id(),
                'amount_cents' => $amountCents,
                'currency' => $currency,
            ]);

            return $this->jsonResponse(['id' => $orderId]);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            LogService::exception($e, 'PayPal laundry order create failed');
            return $this->jsonResponse(
                ['error' => 'Payment service unavailable, please try again.'],
                502
            );
        }
    }

    public function captureOrder(Request $request, Response $response, array $args): Response
    {
        $portal = $this->portal($request);
        if (!$portal->laundryEnabled()) {
            return $this->jsonResponse(['error' => 'Laundry is not available'], 404);
        }
        if (!$this->originMatchesHost($request)) {
            return $this->jsonResponse(['error' => 'Bad request origin'], 400);
        }

        $orderId = (string) ($args['orderId'] ?? '');
        if ($orderId === '') {
            return $this->jsonResponse(['error' => 'Missing order id'], 400);
        }

        $payment = $this->paymentDao->findByPaypalOrderId($orderId);
        if (!$payment || (int) $payment['portal_group_id'] !== $portal->id()) {
            LogService::warning('Laundry capture for unknown order', [
                'paypal_order_id' => $orderId,
                'portal_group_id' => $portal->id(),
            ]);
            return $this->jsonResponse(['error' => 'Order not found'], 404);
        }

        try {
            $captureBody = $this->paypal->captureOrder($orderId);
        } catch (\Throwable $e) {
            LogService::exception($e, 'PayPal capture call failed');
            return $this->jsonResponse(
                ['error' => 'Payment service unavailable, please try again.'],
                502
            );
        }

        if (PayPalService::isAlreadyCapturedError($captureBody)) {
            try {
                $captureBody = $this->paypal->getOrder($orderId);
            } catch (\Throwable $e) {
                LogService::exception($e, 'PayPal getOrder after duplicate-capture failed');
                return $this->jsonResponse(
                    ['error' => 'Payment service unavailable, please try again.'],
                    502
                );
            }
        }

        $captured = PayPalService::extractCapturedAmount($captureBody);
        $statusOk = is_array($captured)
            && (string) $captureBody['status'] === 'COMPLETED'
            && $captured['currency'] === strtoupper((string) $payment['currency'])
            && $captured['amount_cents'] === (int) $payment['amount_cents'];

        if (!$statusOk) {
            $this->paymentDao->markFailed((int) $payment['payment_id'], $this->clientIp($request));
            LogService::warning('Laundry capture rejected: amount/currency/status mismatch', [
                'paypal_order_id' => $orderId,
                'expected_amount_cents' => (int) $payment['amount_cents'],
                'expected_currency' => strtoupper((string) $payment['currency']),
                'paypal_status' => $captureBody['status'] ?? null,
                'captured' => $captured,
            ]);
            return $this->jsonResponse(['error' => 'Payment could not be confirmed'], 402);
        }

        $wasAlreadyCompleted = ((string) ($payment['status'] ?? '')) === 'completed';
        $payerEmail = PayPalService::extractPayerEmail($captureBody);
        $this->paymentDao->markCompleted(
            (int) $payment['payment_id'],
            $payerEmail,
            $this->clientIp($request)
        );

        LogService::info('PayPal laundry order captured', [
            'paypal_order_id' => $orderId,
            'portal_group_id' => $portal->id(),
            'payment_id' => (int) $payment['payment_id'],
            'replayed' => $wasAlreadyCompleted,
        ]);

        if (!$wasAlreadyCompleted) {
            $this->adminNotifier->notifyLaundryPayment(
                $portal,
                $payment,
                $payerEmail,
                (int) $captured['amount_cents'],
                (string) $captured['currency']
            );
        }

        $laundry = $portal->laundry();
        $jsonResponse = $this->jsonResponse([
            'combination' => (string) ($laundry['padlock_combination'] ?? ''),
            'instructions_html' => (string) ($laundry['padlock_instructions_html'] ?? ''),
        ]);

        return $jsonResponse->withHeader(
            'Set-Cookie',
            $this->buildPaidLaundryCookie($request, $orderId, $portal->laundryAccessDays())
        );
    }

    /**
     * Build the `paid_laundry` cookie string sent on successful capture.
     * The value is the PayPal order id (already unguessable), which the
     * guest portal looks up server-side on every visit. Cookie Max-Age is
     * a hint to the browser; the DB age check is authoritative.
     */
    private function buildPaidLaundryCookie(Request $request, string $orderId, int $accessDays): string
    {
        $maxAge = $accessDays * 86400;
        $secure = $this->isSecureRequest($request) ? '; Secure' : '';
        return sprintf(
            'paid_laundry=%s; Max-Age=%d; Path=/; HttpOnly; SameSite=Lax%s',
            urlencode($orderId),
            $maxAge,
            $secure
        );
    }

    /**
     * Detect HTTPS on this request, honouring the standard reverse-proxy
     * header so the cookie picks up Secure when a TLS-terminating proxy
     * (nginx/HestiaCP in production) sets X-Forwarded-Proto: https.
     */
    private function isSecureRequest(Request $request): bool
    {
        if (strtolower($request->getUri()->getScheme()) === 'https') {
            return true;
        }
        $forwardedProto = strtolower(trim($request->getHeaderLine('X-Forwarded-Proto')));
        return $forwardedProto === 'https';
    }

    private function portal(Request $request): PortalGroup
    {
        $portal = $request->getAttribute('portal_group');
        if (!$portal instanceof PortalGroup) {
            throw new \RuntimeException('Laundry controller invoked without a PortalGroup attribute');
        }
        return $portal;
    }

    private function originMatchesHost(Request $request): bool
    {
        $origin = $request->getHeaderLine('Origin');
        if ($origin === '') {
            return true;
        }
        $hostHeader = $request->getHeaderLine('Host');
        $expectedHost = strtolower(trim($hostHeader));
        $expectedHost = preg_replace('/:\d+$/', '', $expectedHost) ?? $expectedHost;
        $parsedHost = parse_url($origin, PHP_URL_HOST);
        if (!is_string($parsedHost) || $parsedHost === '') {
            return false;
        }
        return strtolower($parsedHost) === $expectedHost;
    }

    private function clientIp(Request $request): ?string
    {
        $params = $request->getServerParams();
        $ip = $params['REMOTE_ADDR'] ?? null;
        return is_string($ip) && $ip !== '' ? $ip : null;
    }

    private function jsonResponse(array $payload, int $status = 200): Response
    {
        $response = new SlimResponse($status);
        $response->getBody()->write((string) json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
