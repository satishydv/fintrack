<?php
/**
 * Helper functions for the Payment Gateway Aggregator
 */

$config = require __DIR__ . '/../config.php';

date_default_timezone_set($config['timezone'] ?? 'Asia/Kolkata');

// ─── Transaction JSON Storage ─────────────────────────────────────────────────

function txn_path(string $txn_id): string {
    global $config;
    return $config['data_dir'] . '/' . $txn_id . '.json';
}

function txn_save(array $data): bool {
    $path = txn_path($data['txn_id']);
    $data['updated_at'] = date('c');
    return file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT)) !== false;
}

function txn_load(string $txn_id): ?array {
    $path = txn_path($txn_id);
    if (!file_exists($path)) return null;
    return json_decode(file_get_contents($path), true);
}

function txn_list(): array {
    global $config;
    $files = glob($config['data_dir'] . '/TXN_*.json');
    $txns  = [];
    foreach ($files as $f) {
        $d = json_decode(file_get_contents($f), true);
        if ($d) $txns[] = $d;
    }
    usort($txns, fn($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));
    return $txns;
}

function txn_find_by_gateway_reference(string $gateway, string $gateway_order_id = '', string $gateway_txn_id = ''): ?array {
    $txns = array_values(array_filter(
        txn_list(),
        fn($txn) => strtolower((string)($txn['gateway'] ?? '')) === strtolower($gateway)
    ));

    if ($gateway_order_id !== '') {
        foreach ($txns as $txn) {
            if ((string)($txn['gateway_order_id'] ?? '') === $gateway_order_id) return $txn;
        }
    }

    if ($gateway_txn_id !== '') {
        foreach ($txns as $txn) {
            if ((string)($txn['gateway_txn_id'] ?? '') === $gateway_txn_id) return $txn;
        }
    }

    return null;
}

function txn_update(string $txn_id, array $fields): bool {
    $data = txn_load($txn_id);
    if (!$data) return false;
    $data = array_merge($data, $fields);
    return txn_save($data);
}

function client_ip_address(): string {
    $headers = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_REAL_IP',
        'HTTP_X_FORWARDED_FOR',
        'REMOTE_ADDR',
    ];

    foreach ($headers as $header) {
        $value = $_SERVER[$header] ?? '';
        if ($value === '') continue;

        $ip = trim(explode(',', $value)[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }

    return 'unknown';
}

function with_payment_lock(callable $callback) {
    global $config;

    $lock_path = $config['data_dir'] . '/payment-processing.lock';
    $fh = fopen($lock_path, 'c');
    if (!$fh) {
        throw new Exception('Unable to open payment lock');
    }

    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        throw new Exception('Unable to acquire payment lock');
    }

    try {
        return $callback();
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function txn_is_final(array $txn): bool {
    return in_array($txn['status'] ?? '', ['paid', 'completed', 'chargeback', 'disputed', 'refunded'], true);
}

function txn_is_success_in_progress(array $txn): bool {
    return in_array($txn['status'] ?? '', ['paid', 'completed', 'gateway_paid_pending_notify'], true);
}

function gateway_payment_seen(string $gateway, string $payment_id, string $exclude_txn_id = ''): ?array {
    if ($payment_id === '') return null;

    foreach (txn_list() as $txn) {
        if (($txn['txn_id'] ?? '') === $exclude_txn_id) continue;
        if (($txn['gateway'] ?? '') !== $gateway) continue;
        if (($txn['gateway_txn_id'] ?? '') !== $payment_id) continue;
        if (in_array($txn['status'] ?? '', ['paid', 'completed', 'gateway_paid_pending_notify', 'notify_failed'], true)) {
            return $txn;
        }
    }

    return null;
}

function amounts_match($actual, $expected): bool {
    return abs(((float)$actual) - ((float)$expected)) < 0.01;
}

function normalize_phone(?string $phone): string {
    return preg_replace('/\D+/', '', (string)$phone);
}

function validate_gateway_success(array $txn, array $data): array {
    $gateway = $data['gateway'] ?? ($txn['gateway'] ?? '');
    $payment_id = (string)($data['payment_id'] ?? '');
    $gateway_order_id = (string)($data['gateway_order_id'] ?? '');
    $amount = $data['amount'] ?? null;
    $currency = strtoupper((string)($data['currency'] ?? ''));

    if (empty($txn['txn_id'])) {
        return [false, 'Local transaction missing'];
    }
    if ($gateway_order_id === '' || $gateway_order_id !== (string)($txn['gateway_order_id'] ?? '')) {
        return [false, 'Gateway order mismatch'];
    }
    if ($payment_id === '') {
        return [false, 'Gateway payment id missing'];
    }
    if ($amount === null || !amounts_match($amount, $txn['amount'] ?? 0)) {
        return [false, 'Amount mismatch'];
    }
    if ($currency === '' || $currency !== strtoupper((string)($txn['currency'] ?? ''))) {
        return [false, 'Currency mismatch'];
    }

    $seen = gateway_payment_seen($gateway, $payment_id, $txn['txn_id']);
    if ($seen) {
        return [false, 'Gateway payment id already used by ' . ($seen['txn_id'] ?? 'another transaction')];
    }

    if (!empty($data['txn_id']) && $data['txn_id'] !== ($txn['txn_id'] ?? '')) {
        return [false, 'Local transaction id mismatch'];
    }
    if (!empty($data['order_id']) && $data['order_id'] !== ($txn['order_id'] ?? '')) {
        return [false, 'Local order id mismatch'];
    }
    if (!empty($data['customer_email']) && strcasecmp($data['customer_email'], $txn['customer_email'] ?? '') !== 0) {
        return [false, 'Customer email mismatch'];
    }
    if (!empty($data['customer_phone']) && normalize_phone($data['customer_phone']) !== normalize_phone($txn['customer_phone'] ?? '')) {
        return [false, 'Customer phone mismatch'];
    }

    return [true, null];
}

function mark_gateway_success_and_notify(array $txn, string $gateway_txn_id, array $gateway_response): array {
    $pending_update = [
        'status'           => 'gateway_paid_pending_notify',
        'gateway_txn_id'   => $gateway_txn_id,
        'gateway_response' => $gateway_response,
        'paid_at'          => date('c'),
        'notify_error'     => null,
    ];

    txn_update($txn['txn_id'], $pending_update);
    $pending_txn = array_merge($txn, $pending_update);

    $notify_txn = array_merge($pending_txn, ['status' => 'paid']);
    if (notify_webhook($notify_txn)) {
        $paid_update = [
            'status'       => 'paid',
            'notify_error' => null,
            'notified_at'  => date('c'),
        ];
        txn_update($txn['txn_id'], $paid_update);
        return array_merge($pending_txn, $paid_update);
    }

    $failed_update = [
        'status'       => 'notify_failed',
        'notify_error' => 'Wallet notification failed; retry required',
    ];
    txn_update($txn['txn_id'], $failed_update);
    return array_merge($pending_txn, $failed_update);
}

function mark_non_success_status(array $txn, string $status, array $gateway_response = [], ?string $gateway_txn_id = null): array {
    if (txn_is_final($txn) || in_array($txn['status'] ?? '', ['gateway_paid_pending_notify', 'notify_failed'], true)) {
        return $txn;
    }

    $update = [
        'status'           => $status,
        'gateway_response' => $gateway_response,
    ];
    if ($gateway_txn_id) {
        $update['gateway_txn_id'] = $gateway_txn_id;
    }

    txn_update($txn['txn_id'], $update);
    return array_merge($txn, $update);
}

function mark_risk_status(
    array $txn,
    string $status,
    array $gateway_response = [],
    ?string $gateway_txn_id = null,
    array $risk_details = []
): array {
    $update = [
        'status'           => $status,
        'gateway_response' => $gateway_response,
    ];
    if ($gateway_txn_id) {
        $update['gateway_txn_id'] = $gateway_txn_id;
    }
    if ($risk_details) {
        $update['risk_details'] = $risk_details;
    }

    txn_update($txn['txn_id'], $update);
    return array_merge($txn, $update);
}

function format_app_datetime(?string $value, string $format = 'd M, h:i a'): string {
    global $config;

    if (empty($value)) {
        return '-';
    }

    try {
        $date = new DateTime($value);
        $date->setTimezone(new DateTimeZone($config['timezone'] ?? 'Asia/Kolkata'));
        return strtolower($date->format($format));
    } catch (Exception $e) {
        return $value;
    }
}

// ─── ID Generation ────────────────────────────────────────────────────────────

function generate_txn_id(): string {
    return 'TXN_' . strtoupper(bin2hex(random_bytes(6))) . '_' . time();
}

// ─── API Auth Middleware ──────────────────────────────────────────────────────

function require_api_auth(): void {
    global $config;
    $headers = getallheaders();
    $key = $headers['X-Api-Key'] ?? $headers['x-api-key'] ?? ($_SERVER['HTTP_X_API_KEY'] ?? '');
    if (empty($key) || !hash_equals($config['api_key'], $key)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized: invalid or missing X-Api-Key header']);
        exit;
    }
}

// ─── JSON Response Helpers ────────────────────────────────────────────────────

function json_response(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function json_error(string $msg, int $code = 400): void {
    json_response(['success' => false, 'error' => $msg], $code);
}

// ─── HTTP Client (no cURL dependency — uses PHP streams) ─────────────────────

function http_post(string $url, array $data, array $headers = [], bool $basic_auth = false, string $user = '', string $pass = ''): array {
    $payload = json_encode($data);
    $h = ["Content-Type: application/json", "Content-Length: " . strlen($payload)];
    $h = array_merge($h, $headers);

    $opts = [
        'http' => [
            'method'  => 'POST',
            'header'  => implode("\r\n", $h),
            'content' => $payload,
            'ignore_errors' => true,
        ]
    ];

    if ($basic_auth) {
        $opts['http']['header'] .= "\r\nAuthorization: Basic " . base64_encode("$user:$pass");
    }

    $ctx  = stream_context_create($opts);
    $body = @file_get_contents($url, false, $ctx);
    $meta = $http_response_header ?? [];
    $code = 0;
    foreach ($meta as $line) {
        if (preg_match('/HTTP\/\d\.\d (\d+)/', $line, $m)) {
            $code = (int)$m[1];
        }
    }

    return [
        'status' => $code,
        'body'   => $body,
        'data'   => json_decode($body, true),
    ];
}

function http_get(string $url, array $headers = []): array {
    $h = ["Content-Type: application/json"];
    $h = array_merge($h, $headers);

    $opts = [
        'http' => [
            'method'  => 'GET',
            'header'  => implode("\r\n", $h),
            'ignore_errors' => true,
        ]
    ];

    $ctx  = stream_context_create($opts);
    $body = @file_get_contents($url, false, $ctx);
    $meta = $http_response_header ?? [];
    $code = 0;
    foreach ($meta as $line) {
        if (preg_match('/HTTP\/\d\.\d (\d+)/', $line, $m)) {
            $code = (int)$m[1];
        }
    }

    return [
        'status' => $code,
        'body'   => $body,
        'data'   => json_decode($body, true),
    ];
}

// ─── Notify vara567.com webhook ───────────────────────────────────────────────

function notify_webhook(array $txn): bool {
    if (empty($txn['webhook_url'])) {
        log_error('[notify_webhook] Missing webhook_url for txn: ' . ($txn['txn_id'] ?? 'unknown'));
        return false;
    }

    $payload = [
        'event'        => 'payment.' . $txn['status'],
        'txn_id'       => $txn['txn_id'],
        'order_id'     => $txn['order_id'],
        'gateway'      => $txn['gateway'],
        'amount'       => $txn['amount'],
        'currency'     => $txn['currency'],
        'status'       => $txn['status'],
        'gateway_txn'  => $txn['gateway_txn_id'] ?? null,
        'gateway_order'=> $txn['gateway_order_id'] ?? null,
        'paid_at'      => $txn['paid_at'] ?? null,
        'customer'     => [
            'name'  => $txn['customer_name'],
            'email' => $txn['customer_email'],
            'phone' => $txn['customer_phone'],
        ],
        'raw_response' => $txn['gateway_response'] ?? null,
    ];

    // Sign the webhook payload
    global $config;
    $signature = hash_hmac('sha256', json_encode($payload), $config['api_key']);

    $response = http_post($txn['webhook_url'], $payload, [
        'X-Webhook-Signature: ' . $signature,
        'X-Source: pay.rudhvedinfotech.com',
    ]);

    $status = (int)($response['status'] ?? 0);
    $data = $response['data'] ?? null;

    if ($status < 200 || $status >= 300) {
        log_error('[notify_webhook] Non-2xx response for txn ' . ($txn['txn_id'] ?? 'unknown') . ': HTTP ' . $status);
        return false;
    }

    if (!is_array($data)) {
        log_error('[notify_webhook] Invalid JSON response for txn ' . ($txn['txn_id'] ?? 'unknown'));
        return false;
    }

    if (($data['success'] ?? true) === false || isset($data['error'])) {
        log_error('[notify_webhook] Error response for txn ' . ($txn['txn_id'] ?? 'unknown'));
        return false;
    }

    return true;
}

// ─── Log errors ───────────────────────────────────────────────────────────────

function log_error(string $msg): void {
    global $config;
    $log = $config['data_dir'] . '/error.log';
    file_put_contents($log, date('c') . ' ' . $msg . PHP_EOL, FILE_APPEND);
}
