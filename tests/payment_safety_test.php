<?php
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/Razorpay.php';

$mode = $argv[1] ?? 'main';
$tmp = $argv[2] ?? (sys_get_temp_dir() . '/payment_safety_' . getmypid());
$notify_base = $argv[3] ?? 'http://127.0.0.1:8765';

$config['data_dir'] = $tmp;
if (!is_dir($config['data_dir'])) {
    mkdir($config['data_dir'], 0777, true);
}

function test_txn(string $id, string $notify_url): array {
    return [
        'txn_id' => $id,
        'order_id' => 'ORD_' . $id,
        'amount' => 100.00,
        'currency' => 'INR',
        'customer_name' => 'Test User',
        'customer_email' => 'test@example.com',
        'customer_phone' => '9999999999',
        'gateway' => 'razorpay',
        'description' => 'Test payment',
        'return_url' => 'https://example.test/return',
        'webhook_url' => $notify_url,
        'status' => 'pending',
        'gateway_order_id' => 'order_' . $id,
        'gateway_txn_id' => null,
        'gateway_response' => null,
        'created_at' => date('c'),
        'updated_at' => date('c'),
        'paid_at' => null,
    ];
}

function assert_true(bool $condition, string $message): void {
    if (!$condition) {
        throw new Exception($message);
    }
}

function reset_notify_count(): void {
    $file = __DIR__ . '/notify_count.txt';
    if (file_exists($file)) unlink($file);
}

function notify_count(): int {
    $file = __DIR__ . '/notify_count.txt';
    return file_exists($file) ? strlen(file_get_contents($file)) : 0;
}

function process_razorpay_success(string $txn_id, string $payment_id, float $amount = 100.00, string $currency = 'INR'): array {
    return with_payment_lock(function () use ($txn_id, $payment_id, $amount, $currency) {
        $txn = txn_load($txn_id);
        if (!$txn || txn_is_final($txn)) return $txn;

        $payload = [
            'event' => 'payment.captured',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => $payment_id,
                        'order_id' => $txn['gateway_order_id'],
                        'status' => 'captured',
                        'amount' => (int)round($amount * 100),
                        'currency' => $currency,
                        'email' => $txn['customer_email'],
                        'contact' => $txn['customer_phone'],
                        'notes' => ['txn_id' => $txn['txn_id']],
                    ],
                ],
            ],
        ];
        $payment = $payload['payload']['payment']['entity'];

        [$ok, $error] = validate_gateway_success($txn, [
            'gateway' => 'razorpay',
            'gateway_order_id' => $payment['order_id'],
            'payment_id' => $payment_id,
            'amount' => $amount,
            'currency' => $currency,
            'txn_id' => $payment['notes']['txn_id'],
            'customer_email' => $payment['email'],
            'customer_phone' => $payment['contact'],
        ]);

        if (!$ok) {
            $payload['validation_error'] = $error;
            return mark_non_success_status($txn, 'validation_failed', $payload, $payment_id);
        }

        return mark_gateway_success_and_notify($txn, $payment_id, $payload);
    });
}

function process_non_success(string $txn_id, string $status, string $payment_id = 'pay_failed'): array {
    return with_payment_lock(function () use ($txn_id, $status, $payment_id) {
        $txn = txn_load($txn_id);
        return mark_non_success_status($txn, $status, ['status' => $status], $payment_id);
    });
}

if ($mode === 'parallel-worker') {
    process_razorpay_success($argv[4], 'pay_parallel');
    exit(0);
}

array_map('unlink', glob($config['data_dir'] . '/TXN_*.json') ?: []);
reset_notify_count();
$ok_url = $notify_base . '/notify_ok.php';
$fail_url = $notify_base . '/notify_fail.php';

$txn = test_txn('TXN_FAILED_THEN_SUCCESS', $ok_url);
txn_save($txn);
process_non_success($txn['txn_id'], 'failed');
process_razorpay_success($txn['txn_id'], 'pay_success_1');
assert_true(txn_load($txn['txn_id'])['status'] === 'paid', 'failed then success should become paid');

reset_notify_count();
$txn = test_txn('TXN_AUTH_THEN_CAPTURED', $ok_url);
txn_save($txn);
process_non_success($txn['txn_id'], 'authorized', 'pay_auth');
assert_true(txn_load($txn['txn_id'])['status'] === 'authorized', 'authorized should not credit');
process_razorpay_success($txn['txn_id'], 'pay_auth');
assert_true(txn_load($txn['txn_id'])['status'] === 'paid', 'authorized then captured should become paid');

reset_notify_count();
$txn = test_txn('TXN_DUPLICATE_SUCCESS', $ok_url);
txn_save($txn);
process_razorpay_success($txn['txn_id'], 'pay_duplicate');
process_razorpay_success($txn['txn_id'], 'pay_duplicate');
assert_true(txn_load($txn['txn_id'])['status'] === 'paid', 'duplicate success remains paid');
assert_true(notify_count() === 1, 'duplicate success should notify once');

reset_notify_count();
$txn = test_txn('TXN_PARALLEL_SUCCESS', $ok_url);
txn_save($txn);
$cmd = PHP_BINARY . ' ' . escapeshellarg(__FILE__) . ' parallel-worker ' . escapeshellarg($config['data_dir']) . ' ' . escapeshellarg($notify_base) . ' ' . escapeshellarg($txn['txn_id']);
$p1 = proc_open($cmd, [], $pipes1);
$p2 = proc_open($cmd, [], $pipes2);
$c1 = proc_close($p1);
$c2 = proc_close($p2);
assert_true($c1 === 0 && $c2 === 0, 'parallel workers should exit cleanly');
assert_true(txn_load($txn['txn_id'])['status'] === 'paid', 'parallel success should become paid');
assert_true(notify_count() === 1, 'parallel success should notify once');

$before = txn_load($txn['txn_id']);
process_non_success($txn['txn_id'], 'failed', 'pay_parallel');
assert_true(txn_load($txn['txn_id'])['status'] === $before['status'], 'late failure should not overwrite paid');

$txn = test_txn('TXN_WRONG_AMOUNT', $ok_url);
txn_save($txn);
process_razorpay_success($txn['txn_id'], 'pay_wrong_amount', 90.00);
assert_true(txn_load($txn['txn_id'])['status'] === 'validation_failed', 'wrong amount should fail validation');

$txn = test_txn('TXN_WRONG_CURRENCY', $ok_url);
txn_save($txn);
process_razorpay_success($txn['txn_id'], 'pay_wrong_currency', 100.00, 'USD');
assert_true(txn_load($txn['txn_id'])['status'] === 'validation_failed', 'wrong currency should fail validation');

$rp = new Razorpay('key', 'secret');
$body = '{"event":"payment.captured"}';
$good_sig = hash_hmac('sha256', $body, 'webhook_secret');
assert_true($rp->verifyWebhook($body, $good_sig, 'webhook_secret'), 'valid signature should pass');
assert_true(!$rp->verifyWebhook($body, 'bad', 'webhook_secret'), 'invalid signature should fail');

$txn = test_txn('TXN_NOTIFY_FAIL', $fail_url);
txn_save($txn);
process_razorpay_success($txn['txn_id'], 'pay_notify_fail');
assert_true(txn_load($txn['txn_id'])['status'] === 'notify_failed', 'notify failure should remain retryable');

echo "All payment safety tests passed\n";
