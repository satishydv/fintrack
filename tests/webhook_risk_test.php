<?php

require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/PayU.php';
require_once __DIR__ . '/../lib/WebhookEvents.php';

function risk_assert(bool $condition, string $message): void {
    if (!$condition) {
        throw new Exception($message);
    }
}

$cashfree_dispute = cashfree_webhook_event([
    'type' => 'DISPUTE_CREATED',
    'data' => [
        'dispute' => [
            'dispute_id' => '569157',
            'dispute_type' => 'CHARGEBACK',
            'dispute_status' => 'DISPUTE_CREATED',
            'dispute_amount' => 1000,
            'dispute_amount_currency' => 'INR',
            'reason_description' => 'Goods or services not provided',
            'respond_by' => '2026-08-04T23:59:59+05:30',
        ],
        'order_details' => [
            'order_id' => 'TXN_CASHFREE_DISPUTE',
            'cf_payment_id' => '6116163436',
            'payment_amount' => 1000,
            'payment_currency' => 'INR',
        ],
    ],
]);
risk_assert($cashfree_dispute['status'] === 'chargeback', 'Cashfree dispute should become chargeback');
risk_assert($cashfree_dispute['order_id'] === 'TXN_CASHFREE_DISPUTE', 'Cashfree dispute should read order_details.order_id');
risk_assert($cashfree_dispute['payment_id'] === '6116163436', 'Cashfree dispute should read order_details.cf_payment_id');
risk_assert($cashfree_dispute['risk_details']['amount'] === 1000.0, 'Cashfree dispute amount should be retained');

$cashfree_refund = cashfree_webhook_event([
    'type' => 'REFUND_STATUS_WEBHOOK',
    'data' => [
        'refund' => [
            'order_id' => 'TXN_CASHFREE_REFUND',
            'cf_payment_id' => '6116163437',
            'cf_refund_id' => '11325632',
            'refund_status' => 'SUCCESS',
            'refund_amount' => 250,
            'refund_currency' => 'INR',
        ],
    ],
]);
risk_assert($cashfree_refund['status'] === 'refunded', 'Successful Cashfree refund should become refunded');
risk_assert($cashfree_refund['order_id'] === 'TXN_CASHFREE_REFUND', 'Cashfree refund should read refund.order_id');

$cashfree_failed_refund = cashfree_webhook_event([
    'type' => 'REFUND_STATUS_WEBHOOK',
    'data' => ['refund' => ['order_id' => 'TXN_CASHFREE_REFUND', 'refund_status' => 'FAILED']],
]);
risk_assert($cashfree_failed_refund['status'] === null, 'Failed Cashfree refund must not mark payment refunded');

$razorpay_dispute = razorpay_webhook_event([
    'event' => 'payment.dispute.created',
    'payload' => [
        'payment' => ['entity' => ['id' => 'pay_disputed', 'order_id' => null]],
        'dispute' => ['entity' => [
            'id' => 'disp_123',
            'payment_id' => 'pay_disputed',
            'amount' => 50000,
            'currency' => 'INR',
            'status' => 'open',
        ]],
    ],
]);
risk_assert($razorpay_dispute['status'] === 'chargeback', 'Razorpay dispute should become chargeback');
risk_assert($razorpay_dispute['order_id'] === '', 'Razorpay dispute test should have no order id');
risk_assert($razorpay_dispute['payment_id'] === 'pay_disputed', 'Razorpay dispute should fall back to payment id');

$payu_payload = [
    'type' => 'payments',
    'event' => 'dispute',
    'txn_id' => '999000000000468',
    'cb_amount' => '1000.0',
    'cb_id' => '1761758',
    'cb_type' => 'Chargeback',
    'cb_status' => 'Pending Response',
    'reason_code' => 'Fraud',
];
$payu_event = payu_chargeback_webhook_event($payu_payload);
risk_assert($payu_event !== null && $payu_event['status'] === 'chargeback', 'PayU dispute should become chargeback');

$payu = new PayU('merchant_key', 'merchant_salt', 'https://example.test/pay', 'https://example.test/verify');
$payu_signature = hash('sha512', 'merchant_key|999000000000468|1000.0|1761758|Chargeback|PendingResponse|merchant_salt');
risk_assert($payu->verifyChargebackWebhook($payu_payload, $payu_signature), 'Valid PayU chargeback signature should pass');
risk_assert(!$payu->verifyChargebackWebhook($payu_payload, 'bad'), 'Invalid PayU chargeback signature should fail');

$test_dir = sys_get_temp_dir() . '/payment_webhook_risk_' . getmypid();
if (!is_dir($test_dir)) {
    mkdir($test_dir, 0777, true);
}
$config['data_dir'] = $test_dir;
$test_txn = [
    'txn_id' => 'TXN_REFERENCE_TEST',
    'gateway' => 'razorpay',
    'gateway_order_id' => 'order_reference',
    'gateway_txn_id' => 'pay_disputed',
    'status' => 'paid',
    'created_at' => date('c'),
];
txn_save($test_txn);
$matched = txn_find_by_gateway_reference('razorpay', '', 'pay_disputed');
risk_assert(($matched['txn_id'] ?? '') === 'TXN_REFERENCE_TEST', 'Provider payment id should locate transaction');
risk_assert(txn_is_final(['status' => 'chargeback']), 'Chargeback should block later non-risk updates');
risk_assert(txn_is_final(['status' => 'refunded']), 'Refund should block later non-risk updates');

mark_risk_status($matched, 'chargeback', ['event' => 'payment.dispute.created'], 'pay_disputed', $razorpay_dispute['risk_details']);
$saved_risk_txn = txn_load('TXN_REFERENCE_TEST');
risk_assert($saved_risk_txn['status'] === 'chargeback', 'Risk event should update the saved transaction status');
risk_assert($saved_risk_txn['risk_details']['id'] === 'disp_123', 'Risk event details should be saved for the dashboard');
mark_non_success_status($saved_risk_txn, 'failed', ['event' => 'payment.failed'], 'pay_disputed');
risk_assert(txn_load('TXN_REFERENCE_TEST')['status'] === 'chargeback', 'Late payment event must not overwrite chargeback');

@unlink(txn_path('TXN_REFERENCE_TEST'));
@rmdir($test_dir);

echo "All webhook risk tests passed\n";
