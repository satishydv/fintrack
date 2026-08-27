<?php
/**
 * PayU India Web Checkout Integration Library
 */

class PayU {

    private string $key;
    private string $salt;
    private string $payment_url;
    private string $verify_url;

    public function __construct(string $key, string $salt, string $payment_url, string $verify_url) {
        $this->key         = $key;
        $this->salt        = $salt;
        $this->payment_url = $payment_url;
        $this->verify_url  = $verify_url;
    }

    public function getPaymentUrl(): string {
        return $this->payment_url;
    }

    public function createPaymentData(array $txn, string $return_url): array {
        $fields = [
            'key'         => $this->key,
            'txnid'       => $txn['txn_id'],
            'amount'      => $this->formatAmount((float)$txn['amount']),
            'productinfo' => $txn['description'] ?? 'Payment',
            'firstname'   => $txn['customer_name'],
            'email'       => $txn['customer_email'],
            'phone'       => $txn['customer_phone'],
            'surl'        => $return_url,
            'furl'        => $return_url,
            'udf1'        => $txn['order_id'],
            'udf2'        => $txn['txn_id'],
            'udf3'        => 'payu',
            'udf4'        => '',
            'udf5'        => '',
        ];

        $fields['hash'] = $this->generatePaymentHash($fields);
        return $fields;
    }

    public function verifyPaymentHash(array $response): bool {
        if (empty($response['hash'])) {
            return false;
        }

        $parts = [
            $this->salt,
            $response['status'] ?? '',
            '',
            '',
            '',
            '',
            '',
            $response['udf5'] ?? '',
            $response['udf4'] ?? '',
            $response['udf3'] ?? '',
            $response['udf2'] ?? '',
            $response['udf1'] ?? '',
            $response['email'] ?? '',
            $response['firstname'] ?? '',
            $response['productinfo'] ?? '',
            $response['amount'] ?? '',
            $response['txnid'] ?? '',
            $response['key'] ?? $this->key,
        ];

        if (!empty($response['additional_charges'])) {
            array_unshift($parts, $response['additional_charges']);
        }

        $expected = strtolower(hash('sha512', implode('|', $parts)));
        return hash_equals($expected, strtolower((string)$response['hash']));
    }

    public function verifyChargebackWebhook(array $payload, string $signature): bool {
        if ($signature === '' || empty($payload['txn_id']) || !isset($payload['cb_amount'], $payload['cb_id'])) {
            return false;
        }

        $signature = strtolower(trim($signature));
        $raw_status = (string)($payload['cb_status'] ?? '');
        $status_values = array_unique([
            $raw_status,
            preg_replace('/\s+/', '', $raw_status),
        ]);

        foreach ($status_values as $status) {
            $parts = [
                $this->key,
                (string)$payload['txn_id'],
                (string)$payload['cb_amount'],
                (string)$payload['cb_id'],
                (string)($payload['cb_type'] ?? ''),
                $status,
                $this->salt,
            ];
            $expected = strtolower(hash('sha512', implode('|', $parts)));
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    public function verifyPayment(string $txnid): array {
        return $this->postService('verify_payment', $txnid);
    }

    public function extractVerifiedStatus(array $verify_response, string $txnid): string {
        $details = $verify_response['transaction_details'][$txnid] ?? [];
        return strtolower((string)($details['status'] ?? ''));
    }

    private function generatePaymentHash(array $fields): string {
        $parts = [
            $fields['key'],
            $fields['txnid'],
            $fields['amount'],
            $fields['productinfo'],
            $fields['firstname'],
            $fields['email'],
            $fields['udf1'] ?? '',
            $fields['udf2'] ?? '',
            $fields['udf3'] ?? '',
            $fields['udf4'] ?? '',
            $fields['udf5'] ?? '',
            '',
            '',
            '',
            '',
            '',
            $this->salt,
        ];

        return strtolower(hash('sha512', implode('|', $parts)));
    }

    private function postService(string $command, string $var1): array {
        $payload = [
            'key'     => $this->key,
            'command' => $command,
            'var1'    => $var1,
            'hash'    => strtolower(hash('sha512', $this->key . '|' . $command . '|' . $var1 . '|' . $this->salt)),
        ];

        $body = http_build_query($payload);
        $opts = [
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body),
                'content'       => $body,
                'ignore_errors' => true,
            ],
        ];

        $ctx  = stream_context_create($opts);
        $resp = @file_get_contents($this->verify_url, false, $ctx);
        return json_decode($resp, true) ?? ['raw' => $resp];
    }

    private function formatAmount(float $amount): string {
        return number_format($amount, 2, '.', '');
    }
}
