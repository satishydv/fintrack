<?php
/**
 * Razorpay Integration Library
 */

class Razorpay {

    private string $key_id;
    private string $key_secret;
    private string $base = 'https://api.razorpay.com/v1';

    public function __construct(string $key_id, string $key_secret) {
        $this->key_id     = $key_id;
        $this->key_secret = $key_secret;
    }

    // ── Create a Razorpay Order ──────────────────────────────────────────────

    public function createOrder(array $params): array {
        // amount must be in paise (INR × 100)
        $payload = [
            'amount'          => (int)($params['amount'] * 100),
            'currency'        => $params['currency'] ?? 'INR',
            'receipt'         => $params['order_id'],
            'notes'           => [
                'txn_id'      => $params['txn_id'],
                'description' => $params['description'] ?? 'Payment',
            ],
        ];

        $response = $this->request('POST', '/orders', $payload);
        return $response;
    }

    // ── Verify Payment Signature ─────────────────────────────────────────────

    public function verifySignature(string $order_id, string $payment_id, string $signature): bool {
        $expected = hash_hmac('sha256', $order_id . '|' . $payment_id, $this->key_secret);
        return hash_equals($expected, $signature);
    }

    // ── Fetch Payment Details ────────────────────────────────────────────────

    public function fetchPayment(string $payment_id): array {
        return $this->request('GET', '/payments/' . $payment_id);
    }

    // ── Fetch Order Details ──────────────────────────────────────────────────

    public function fetchOrder(string $order_id): array {
        return $this->request('GET', '/orders/' . $order_id);
    }

    // ── Verify Webhook Signature ─────────────────────────────────────────────

    public function verifyWebhook(string $body, string $signature, ?string $webhook_secret = null): bool {
        $secret = $webhook_secret ?: $this->key_secret;
        $expected = hash_hmac('sha256', $body, $secret);
        return hash_equals($expected, $signature);
    }

    // ── Internal HTTP Request ────────────────────────────────────────────────

    private function request(string $method, string $endpoint, array $data = []): array {
        $url = $this->base . $endpoint;

        if ($method === 'GET') {
            $opts = [
                'http' => [
                    'method'  => 'GET',
                    'header'  => "Authorization: Basic " . base64_encode($this->key_id . ':' . $this->key_secret) . "\r\nContent-Type: application/json",
                    'ignore_errors' => true,
                ]
            ];
        } else {
            $payload = json_encode($data);
            $opts = [
                'http' => [
                    'method'  => 'POST',
                    'header'  => "Authorization: Basic " . base64_encode($this->key_id . ':' . $this->key_secret) . "\r\nContent-Type: application/json\r\nContent-Length: " . strlen($payload),
                    'content' => $payload,
                    'ignore_errors' => true,
                ]
            ];
        }

        $ctx  = stream_context_create($opts);
        $body = @file_get_contents($url, false, $ctx);
        $resp = json_decode($body, true);

        if (isset($resp['error'])) {
            throw new Exception('Razorpay Error: ' . ($resp['error']['description'] ?? 'Unknown error'));
        }

        return $resp ?? [];
    }

    public function getKeyId(): string {
        return $this->key_id;
    }
}
