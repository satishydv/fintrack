<?php
/**
 * POST /api/initiate.php
 * Called by vara567.com (Laravel) to initiate a payment.
 *
 * Request Headers:
 *   Content-Type: application/json
 *   X-Api-Key: <shared_secret_from_config>
 *
 * Request Body (JSON):
 * {
 *   "order_id":       "ORD_123",          // your order ID from vara567.com
 *   "amount":         499.00,             // in INR (decimal)
 *   "currency":       "INR",              // optional, default INR
 *   "customer_name":  "John Doe",
 *   "customer_email": "john@example.com",
 *   "customer_phone": "9999999999",
 *   "gateway":        "razorpay",         // "razorpay" or "cashfree"
 *   "return_url":     "https://vara567.com/payment/return",
 *   "webhook_url":    "https://vara567.com/payment/webhook",
 *   "description":    "Order #123"        // optional
 * }
 *
 * Success Response:
 * {
 *   "success":     true,
 *   "txn_id":      "TXN_XXXXX_1234567890",
 *   "payment_url": "https://pay.rudhvedinfotech.com/pay.php?txn=TXN_XXXXX"
 * }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://vara567.com');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Api-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/Razorpay.php';
require_once __DIR__ . '/../lib/Cashfree.php';
require_once __DIR__ . '/../lib/PayU.php';

// ── Auth ──────────────────────────────────────────────────────────────────────
require_api_auth();

// ── Parse Payload ─────────────────────────────────────────────────────────────
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);

if (!$body) {
    json_error('Invalid JSON payload');
}

// ── Validate Required Fields ─────────────────────────────────────────────────
$required = ['order_id', 'amount', 'customer_name', 'customer_email', 'customer_phone', 'gateway', 'return_url', 'webhook_url'];
foreach ($required as $field) {
    if (empty($body[$field])) {
        json_error("Missing required field: $field");
    }
}

$gateway = strtolower(trim($body['gateway']));
if (!in_array($gateway, ['razorpay', 'cashfree', 'payu'])) {
    json_error('Invalid gateway. Use "razorpay", "cashfree", or "payu"');
}

$amount = (float)$body['amount'];
if ($amount <= 0) {
    json_error('Amount must be greater than 0');
}

// ── Build Transaction Record ──────────────────────────────────────────────────
$txn_id = generate_txn_id();

$txn = [
    'txn_id'          => $txn_id,
    'order_id'        => $body['order_id'],
    'amount'          => $amount,
    'currency'        => strtoupper($body['currency'] ?? 'INR'),
    'customer_name'   => $body['customer_name'],
    'customer_email'  => $body['customer_email'],
    'customer_phone'  => $body['customer_phone'],
    'gateway'         => $gateway,
    'description'     => $body['description'] ?? 'Payment for Order ' . $body['order_id'],
    'return_url'      => $body['return_url'],
    'webhook_url'     => $body['webhook_url'],
    'status'          => 'pending',
    'gateway_order_id'=> null,
    'gateway_txn_id'  => null,
    'gateway_response'=> null,
    'ip_address'      => $body['customer_ip'] ?? $body['client_ip'] ?? $body['ip_address'] ?? client_ip_address(),
    'request_ip'      => client_ip_address(),
    'user_agent'      => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'created_at'      => date('c'),
    'updated_at'      => date('c'),
    'paid_at'         => null,
];

// ── Create Gateway Order ──────────────────────────────────────────────────────
$gateway_request_started_at = hrtime(true);
try {
    if ($gateway === 'razorpay') {
        $rp    = new Razorpay($config['razorpay']['key_id'], $config['razorpay']['key_secret']);
        $order = $rp->createOrder([
            'txn_id'      => $txn_id,
            'order_id'    => $body['order_id'],
            'amount'      => $amount,
            'currency'    => $txn['currency'],
            'description' => $txn['description'],
        ]);

        if (empty($order['id'])) {
            throw new Exception('Failed to create Razorpay order: ' . json_encode($order));
        }

        $txn['gateway_order_id']  = $order['id'];
        $txn['gateway_order_raw'] = $order;

    } elseif ($gateway === 'cashfree') {
        $cf    = new Cashfree($config['cashfree']['app_id'], $config['cashfree']['secret_key'], $config['cashfree']['base_url']);
        $return_base = $config['pay_url'] . '/return.php';

        $order = $cf->createOrder(array_merge($txn, ['txn_id' => $txn_id]), $return_base);

        if (empty($order['order_id'])) {
            throw new Exception('Failed to create Cashfree order: ' . json_encode($order));
        }

        $txn['gateway_order_id']       = $order['order_id'];
        $txn['cf_payment_session_id']  = $order['payment_session_id'] ?? null;
        $txn['cf_payment_link']        = $order['payment_link'] ?? null;
        $txn['gateway_order_raw']      = $order;
    } elseif ($gateway === 'payu') {
        $payu = new PayU(
            $config['payu']['key'],
            $config['payu']['salt'],
            $config['payu']['payment_url'],
            $config['payu']['verify_url']
        );
        $return_url = $config['pay_url'] . '/return.php?txn=' . urlencode($txn_id);
        $payment_data = $payu->createPaymentData($txn, $return_url);

        $txn['gateway_order_id']  = $txn_id;
        $txn['payu_payment_url']  = $payu->getPaymentUrl();
        $txn['payu_form_fields']  = $payment_data;
        $txn['gateway_order_raw'] = [
            'payment_url' => $txn['payu_payment_url'],
            'txnid'       => $payment_data['txnid'],
            'amount'      => $payment_data['amount'],
        ];
    }

} catch (Exception $e) {
    log_error('[initiate] ' . $e->getMessage());
    json_error('Gateway error: ' . $e->getMessage(), 500);
}

// ── Save Transaction ──────────────────────────────────────────────────────────
$txn['gateway_latency_ms'] = round((hrtime(true) - $gateway_request_started_at) / 1_000_000, 2);
txn_save($txn);

// ── Return Payment URL ────────────────────────────────────────────────────────
$payment_url = $config['pay_url'] . '/pay.php?txn=' . urlencode($txn_id);

json_response([
    'success'     => true,
    'txn_id'      => $txn_id,
    'payment_url' => $payment_url,
    'gateway'     => $gateway,
    'amount'      => $amount,
    'currency'    => $txn['currency'],
]);
