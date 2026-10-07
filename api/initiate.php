<?php
/**
 * POST /api/initiate.php
 * Called by vara567.com (Laravel) or other merchants to initiate a payment.
 *
 * Supports Smart Routing:
 * - If auto-routing is ON or "gateway": "auto" is passed, automatically selects
 *   the fastest operational gateway, skipping failing gateways via Circuit Breaker.
 * - Supports real-time cascading / auto-failover if the chosen gateway experiences an outage.
 *
 * Request Headers:
 *   Content-Type: application/json
 *   X-Api-Key: <shared_secret_from_config>
 *
 * Request Body (JSON):
 * {
 *   "order_id":       "ORD_123",
 *   "amount":         499.00,
 *   "currency":       "INR",              // optional, default INR
 *   "customer_name":  "John Doe",
 *   "customer_email": "john@example.com",
 *   "customer_phone": "9999999999",
 *   "gateway":        "auto",             // "auto", "razorpay", "cashfree", or "payu"
 *   "return_url":     "https://vara567.com/payment/return",
 *   "webhook_url":    "https://vara567.com/payment/webhook",
 *   "description":    "Order #123"        // optional
 * }
 *
 * Success Response:
 * {
 *   "success":     true,
 *   "txn_id":      "TXN_XXXXX_1234567890",
 *   "payment_url": "https://deposit.yourdomain.com/pay.php?txn=TXN_XXXXX",
 *   "gateway":     "cashfree",
 *   "amount":      499.00,
 *   "currency":    "INR",
 *   "auto_routed": true,
 *   "latency_ms":  142.5
 * }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
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
require_once __DIR__ . '/../lib/SmartRouter.php';

// ── Auth ──────────────────────────────────────────────────────────────────────
require_api_auth();

// ── Parse Payload ─────────────────────────────────────────────────────────────
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);

if (!$body) {
    json_error('Invalid JSON payload');
}

// ── Validate Required Fields ─────────────────────────────────────────────────
$required = ['order_id', 'amount', 'customer_name', 'customer_email', 'customer_phone', 'return_url', 'webhook_url'];
foreach ($required as $field) {
    if (empty($body[$field])) {
        json_error("Missing required field: $field");
    }
}

$amount = (float)$body['amount'];
if ($amount <= 0) {
    json_error('Amount must be greater than 0');
}

// ── Smart Gateway Routing ────────────────────────────────────────────────────
$routing_config = SmartRouter::getConfig();
$req_gateway    = strtolower(trim((string)($body['gateway'] ?? '')));

if ($req_gateway !== '' && !in_array($req_gateway, array_merge(SmartRouter::ALL_GATEWAYS, ['auto']), true)) {
    json_error('Invalid gateway. Use "auto", "razorpay", "cashfree", or "payu"');
}

if ($req_gateway === '' && empty($routing_config['enabled'])) {
    json_error('Missing required field: gateway (or enable automatic routing)');
}

$routing_decision   = SmartRouter::resolveRouting($req_gateway);
$candidate_gateways = $routing_decision['ranked_list'];
$auto_cascade       = (bool)$routing_decision['auto_cascade'];
$primary_gateway    = $routing_decision['primary_gateway'];

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
    'gateway'         => $primary_gateway,
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
    'routing'         => [
        'auto_routed'       => $routing_decision['auto_routed'],
        'strategy'          => $routing_decision['strategy'],
        'primary_choice'    => $primary_gateway,
        'selected_gateway'  => null,
        'selection_reason'  => $routing_decision['selection_reason'],
        'candidates'        => $candidate_gateways,
        'failovers'         => [],
    ],
];

// ── Gateway Order Creation with Auto-Failover Cascade ────────────────────────
$successful_gateway = null;
$last_exception     = null;

foreach ($candidate_gateways as $gateway_attempt) {
    $attempt_start = hrtime(true);
    try {
        if ($gateway_attempt === 'razorpay') {
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

        } elseif ($gateway_attempt === 'cashfree') {
            $cf          = new Cashfree($config['cashfree']['app_id'], $config['cashfree']['secret_key'], $config['cashfree']['base_url']);
            $return_base = $config['pay_url'] . '/return.php';

            $order = $cf->createOrder(array_merge($txn, ['txn_id' => $txn_id]), $return_base);

            if (empty($order['order_id'])) {
                throw new Exception('Failed to create Cashfree order: ' . json_encode($order));
            }

            $txn['gateway_order_id']      = $order['order_id'];
            $txn['cf_payment_session_id'] = $order['payment_session_id'] ?? null;
            $txn['cf_payment_link']       = $order['payment_link'] ?? null;
            $txn['gateway_order_raw']     = $order;

        } elseif ($gateway_attempt === 'payu') {
            $payu = new PayU(
                $config['payu']['key'],
                $config['payu']['salt'],
                $config['payu']['payment_url'],
                $config['payu']['verify_url']
            );
            $return_url   = $config['pay_url'] . '/return.php?txn=' . urlencode($txn_id);
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

        $latency_ms = round((hrtime(true) - $attempt_start) / 1_000_000, 2);
        SmartRouter::recordSuccess($gateway_attempt, $latency_ms);

        $successful_gateway = $gateway_attempt;
        $txn['gateway']     = $gateway_attempt;
        $txn['gateway_latency_ms'] = $latency_ms;
        $txn['routing']['selected_gateway'] = $gateway_attempt;
        break; // Successful creation!

    } catch (Exception $e) {
        $latency_ms = round((hrtime(true) - $attempt_start) / 1_000_000, 2);
        SmartRouter::recordFailure($gateway_attempt, $e->getMessage(), $latency_ms);
        log_error("[initiate] {$gateway_attempt} failed for {$txn_id}: " . $e->getMessage());

        $txn['routing']['failovers'][] = [
            'gateway'    => $gateway_attempt,
            'error'      => $e->getMessage(),
            'latency_ms' => $latency_ms,
            'time'       => date('c'),
        ];

        $last_exception = $e;

        if (!$auto_cascade) {
            break;
        }
    }
}

if (!$successful_gateway) {
    log_error('[initiate] All gateway attempts failed for ' . $txn_id . ': ' . ($last_exception ? $last_exception->getMessage() : 'No gateway available'));
    json_error('Payment initiation failed: ' . ($last_exception ? $last_exception->getMessage() : 'All gateways unavailable'), 500);
}

// ── Save Transaction ──────────────────────────────────────────────────────────
txn_save($txn);

// ── Return Payment URL ────────────────────────────────────────────────────────
$payment_url = $config['pay_url'] . '/pay.php?txn=' . urlencode($txn_id);

json_response([
    'success'     => true,
    'txn_id'      => $txn_id,
    'payment_url' => $payment_url,
    'gateway'     => $successful_gateway,
    'amount'      => $amount,
    'currency'    => $txn['currency'],
    'auto_routed' => (bool)$txn['routing']['auto_routed'],
    'latency_ms'  => $txn['gateway_latency_ms'],
]);
