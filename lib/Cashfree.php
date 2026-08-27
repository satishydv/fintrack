<?php
/**
 * Cashfree Integration Library (API v3 - Production)
 */

class Cashfree {

    private string $app_id;
    private string $secret_key;
    private string $base_url;
    private string $api_version = '2023-08-01';

    public function __construct(string $app_id, string $secret_key, string $base_url = 'https://api.cashfree.com/pg') {
        $this->app_id     = $app_id;
        $this->secret_key = $secret_key;
        $this->base_url   = rtrim($base_url, '/');
    }

    // ── Create a Cashfree Order ──────────────────────────────────────────────

    public function createOrder(array $params, string $return_url): array {
        $separator = strpos($return_url, '?') !== false ? '&' : '?';

        $payload = [
            'order_id'       => $params['txn_id'],           // Use txn_id as CF order_id
            'order_amount'   => (float)$params['amount'],
            'order_currency' => $params['currency'] ?? 'INR',
            'order_note'     => $params['description'] ?? 'Payment',
            'customer_details' => [
                'customer_id'    => 'CUST_' . md5($params['customer_email']),
                'customer_name'  => $params['customer_name'],
                'customer_email' => $params['customer_email'],
                'customer_phone' => $params['customer_phone'],
            ],
            'order_meta' => [
                'return_url'   => $return_url . $separator . 'txn=' . $params['txn_id'] . '&order_id={order_id}',
                'notify_url'   => '', // webhook handled separately
            ],
        ];

        return $this->request('POST', '/orders', $payload);
    }

    // ── Get Payment Session ID (needed for JS SDK) ───────────────────────────

    public function getPaymentLink(array $order): string {
        // Cashfree hosted payment page
        return $order['payment_link'] ?? '';
    }

    // ── Verify Order Payment Status ──────────────────────────────────────────

    public function getOrderStatus(string $order_id): array {
        return $this->request('GET', '/orders/' . $order_id);
    }

    // ── Fetch Payments for an Order ──────────────────────────────────────────

    public function getOrderPayments(string $order_id): array {
        return $this->request('GET', '/orders/' . $order_id . '/payments');
    }

    // ── Verify Webhook Signature ─────────────────────────────────────────────

    public function verifyWebhook(string $timestamp, string $body, string $signature): bool {
        $data     = $timestamp . $body;
        $expected = base64_encode(hash_hmac('sha256', $data, $this->secret_key, true));
        return hash_equals($expected, $signature);
    }

    // ── Internal HTTP Request ────────────────────────────────────────────────

    private function request(string $method, string $endpoint, array $data = []): array {
        $url = $this->base_url . $endpoint;

        $headers = [
            "x-client-id: {$this->app_id}",
            "x-client-secret: {$this->secret_key}",
            "x-api-version: {$this->api_version}",
            "Content-Type: application/json",
            "Accept: application/json",
        ];

        if ($method === 'GET') {
            $opts = [
                'http' => [
                    'method'        => 'GET',
                    'header'        => implode("\r\n", $headers),
                    'ignore_errors' => true,
                ]
            ];
        } else {
            $payload = json_encode($data);
            $headers[] = "Content-Length: " . strlen($payload);
            $opts = [
                'http' => [
                    'method'        => 'POST',
                    'header'        => implode("\r\n", $headers),
                    'content'       => $payload,
                    'ignore_errors' => true,
                ]
            ];
        }

        $ctx  = stream_context_create($opts);
        $body = @file_get_contents($url, false, $ctx);
        $resp = json_decode($body, true);

        if (isset($resp['message']) && isset($resp['code']) && !isset($resp['order_id']) && !isset($resp['payment_session_id'])) {
            throw new Exception('Cashfree Error: ' . ($resp['message'] ?? 'Unknown error'));
        }

        return $resp ?? [];
    }
}
