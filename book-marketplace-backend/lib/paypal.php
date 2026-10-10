<?php
declare(strict_types=1);

/**
 * PayPal Orders v2 helper.
 *
 * Supports two modes (set PAYPAL_MODE in .env):
 *
 *   simulated  — no PayPal account; runs fully offline.
 *                Generates a SIM-xxxxxxxx order id and returns a
 *                simulated capture id.  Safe for class demos.
 *
 *   sandbox    — real PayPal sandbox API with test money.
 *                Get a free developer app at developer.paypal.com
 *                and put the Client ID + Secret in .env.
 *
 * The secret never leaves the server.  The client only sees the
 * public Client ID (returned by GET /payments.php?action=config).
 */

function paypal_mode(): string
{
    $mode = strtolower(trim(getenv('PAYPAL_MODE') ?: 'simulated'));
    return in_array($mode, ['sandbox', 'simulated'], true) ? $mode : 'simulated';
}

function paypal_currency(): string
{
    $c = strtoupper(trim(getenv('PAYPAL_CURRENCY') ?: 'PHP'));
    return $c !== '' ? $c : 'PHP';
}

function paypal_base_url(): string
{
    return 'https://api-m.sandbox.paypal.com';
}

// ── OAuth token cache ──────────────────────────────────────────────────────

/**
 * Returns a cached OAuth2 access token.
 * Fetches a new one when the current one is about to expire.
 * Throws RuntimeException on failure.
 */
function paypal_access_token(): string
{
    static $cached = null;
    static $expires_at = 0;

    if ($cached !== null && time() < $expires_at - 30) {
        return $cached;
    }

    $clientId     = trim(getenv('PAYPAL_CLIENT_ID')     ?: '');
    $clientSecret = trim(getenv('PAYPAL_CLIENT_SECRET') ?: '');
    if ($clientId === '' || $clientSecret === '') {
        throw new RuntimeException('PAYPAL_CLIENT_ID and PAYPAL_CLIENT_SECRET are not configured.');
    }

    $ch = curl_init(paypal_base_url() . '/v1/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERPWD        => $clientId . ':' . $clientSecret,
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $body    = curl_exec($ch);
    $status  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr !== '') throw new RuntimeException('PayPal cURL error (token): ' . $curlErr);
    if ($status !== 200) throw new RuntimeException('PayPal token error (HTTP ' . $status . '): ' . $body);

    $data = json_decode((string) $body, true);
    if (empty($data['access_token'])) throw new RuntimeException('PayPal token response malformed.');

    $cached    = $data['access_token'];
    $expires_at = time() + (int) ($data['expires_in'] ?? 3600);
    return $cached;
}

// ── PayPal REST helper ─────────────────────────────────────────────────────

function paypal_request(string $method, string $path, array $payload = [], string $idempotencyKey = ''): array
{
    $url     = paypal_base_url() . $path;
    $token   = paypal_access_token();
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
        'Prefer: return=representation',
    ];
    if ($idempotencyKey !== '') {
        $headers[] = 'PayPal-Request-Id: ' . $idempotencyKey;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CUSTOMREQUEST  => $method,
    ]);
    if ($payload) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }
    $body    = curl_exec($ch);
    $status  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr !== '') throw new RuntimeException('PayPal cURL error: ' . $curlErr);

    $data = json_decode((string) $body, true) ?: [];
    return ['status' => $status, 'body' => $data, 'raw' => (string) $body];
}

// ── Public API ─────────────────────────────────────────────────────────────

/**
 * Creates a PayPal order for the given amount / currency / reference.
 * Returns ['order_id' => '...', 'approve_url' => '...' (sandbox only), 'raw' => ...].
 */
function paypal_create_order(float $amount, string $currency, int $transactionId, string $idempotencyKey): array
{
    if (paypal_mode() === 'simulated') {
        return [
            'order_id'    => 'SIM-' . strtoupper(bin2hex(random_bytes(8))),
            'approve_url' => null,
            'raw'         => ['simulated' => true],
        ];
    }

    $amountStr = number_format($amount, 2, '.', '');
    $resp = paypal_request('POST', '/v2/checkout/orders', [
        'intent'         => 'CAPTURE',
        'purchase_units' => [[
            'reference_id' => 'TX-' . $transactionId,
            'description'  => 'Librowse Book Purchase — Order #' . $transactionId,
            'amount'       => ['currency_code' => $currency, 'value' => $amountStr],
        ]],
        'payment_source' => [
            'paypal' => [
                'experience_context' => [
                    'payment_method_preference' => 'IMMEDIATE_PAYMENT_REQUIRED',
                    'user_action'               => 'PAY_NOW',
                ],
            ],
        ],
    ], $idempotencyKey);

    if ($resp['status'] < 200 || $resp['status'] >= 300) {
        throw new RuntimeException('PayPal create order failed (HTTP ' . $resp['status'] . '): ' . json_encode($resp['body']));
    }

    $orderId    = $resp['body']['id'] ?? '';
    $approveUrl = null;
    foreach ($resp['body']['links'] ?? [] as $link) {
        if ($link['rel'] === 'payer-action') { $approveUrl = $link['href']; break; }
    }

    return ['order_id' => $orderId, 'approve_url' => $approveUrl, 'raw' => $resp['body']];
}

/**
 * Captures a PayPal order.
 * Returns ['capture_id' => '...', 'payer_email' => '...', 'amount' => 12.99, 'currency' => 'PHP', 'status' => 'COMPLETED', 'raw' => ...].
 * Throws on any failure or if the capture is not COMPLETED.
 */
function paypal_capture_order(string $orderId, string $idempotencyKey): array
{
    if (paypal_mode() === 'simulated') {
        return [
            'capture_id'  => 'SIMCAP-' . strtoupper(bin2hex(random_bytes(8))),
            'payer_email' => 'sandbox.buyer@example.com',
            'amount'      => null,   // amount verified from DB, not PayPal response
            'currency'    => paypal_currency(),
            'status'      => 'COMPLETED',
            'raw'         => ['simulated' => true, 'order_id' => $orderId],
        ];
    }

    $resp = paypal_request('POST', '/v2/checkout/orders/' . $orderId . '/capture', [], $idempotencyKey);

    if ($resp['status'] < 200 || $resp['status'] >= 300) {
        throw new RuntimeException('PayPal capture failed (HTTP ' . $resp['status'] . '): ' . json_encode($resp['body']));
    }

    $body   = $resp['body'];
    $ppStatus = $body['status'] ?? '';
    if ($ppStatus !== 'COMPLETED') {
        throw new RuntimeException("PayPal capture status is '{$ppStatus}', expected COMPLETED.");
    }

    // Drill into purchase_units[0].payments.captures[0]
    $capture    = $body['purchase_units'][0]['payments']['captures'][0] ?? [];
    $captureId  = $capture['id'] ?? '';
    $amount     = (float) ($capture['amount']['value'] ?? 0);
    $currency   = $capture['amount']['currency_code'] ?? paypal_currency();
    $payerEmail = $body['payer']['email_address'] ?? '';

    return [
        'capture_id'  => $captureId,
        'payer_email' => $payerEmail,
        'amount'      => $amount,
        'currency'    => $currency,
        'status'      => 'COMPLETED',
        'raw'         => $body,
    ];
}

/**
 * Issues a full refund for a capture.
 * Returns ['refund_id' => '...', 'status' => 'COMPLETED', 'raw' => ...].
 * Throws on failure.
 */
function paypal_refund_capture(string $captureId, float $amount, string $currency, string $idempotencyKey): array
{
    if (paypal_mode() === 'simulated') {
        return [
            'refund_id' => 'SIMREF-' . strtoupper(bin2hex(random_bytes(8))),
            'status'    => 'COMPLETED',
            'raw'       => ['simulated' => true, 'capture_id' => $captureId],
        ];
    }

    $amountStr = number_format($amount, 2, '.', '');
    $resp = paypal_request('POST', '/v2/payments/captures/' . $captureId . '/refund', [
        'amount' => ['value' => $amountStr, 'currency_code' => $currency],
    ], $idempotencyKey);

    if ($resp['status'] < 200 || $resp['status'] >= 300) {
        throw new RuntimeException('PayPal refund failed (HTTP ' . $resp['status'] . '): ' . json_encode($resp['body']));
    }

    return [
        'refund_id' => $resp['body']['id'] ?? '',
        'status'    => $resp['body']['status'] ?? 'COMPLETED',
        'raw'       => $resp['body'],
    ];
}
