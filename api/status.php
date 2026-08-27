<?php
/**
 * GET /api/status.php?txn_id=TXN_XXXXX
 * Called by vara567.com to check payment status.
 *
 * Headers: X-Api-Key: <shared_secret>
 *
 * Response:
 * {
 *   "success":      true,
 *   "txn_id":       "TXN_XXXXX",
 *   "order_id":     "ORD_123",
 *   "status":       "paid",          // pending | paid | failed | refunded
 *   "gateway":      "razorpay",
 *   "amount":       499.00,
 *   "currency":     "INR",
 *   "gateway_txn":  "pay_XXXXXX",
 *   "paid_at":      "2024-01-01T12:00:00+05:30"
 * }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://vara567.com');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Api-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../lib/helpers.php';

require_api_auth();

$txn_id = trim($_GET['txn_id'] ?? '');
if (empty($txn_id)) {
    json_error('Missing txn_id parameter');
}

$txn = txn_load($txn_id);
if (!$txn) {
    json_error('Transaction not found', 404);
}

json_response([
    'success'       => true,
    'txn_id'        => $txn['txn_id'],
    'order_id'      => $txn['order_id'],
    'status'        => $txn['status'],
    'gateway'       => $txn['gateway'],
    'amount'        => $txn['amount'],
    'currency'      => $txn['currency'],
    'gateway_txn'   => $txn['gateway_txn_id'] ?? null,
    'gateway_order' => $txn['gateway_order_id'] ?? null,
    'customer'      => [
        'name'  => $txn['customer_name'],
        'email' => $txn['customer_email'],
        'phone' => $txn['customer_phone'],
    ],
    'paid_at'       => $txn['paid_at'] ?? null,
    'created_at'    => $txn['created_at'],
    'updated_at'    => $txn['updated_at'],
]);
