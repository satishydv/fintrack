<?php
/**
 * /webhook.php — Server-to-server webhooks from Razorpay and Cashfree
 *
 * Razorpay Webhook URL:  https://pay.rudhvedinfotech.com/webhook.php?gateway=razorpay
 * Cashfree Webhook URL:  https://pay.rudhvedinfotech.com/webhook.php?gateway=cashfree
 *
 * Register these in your Razorpay & Cashfree dashboards.
 * Razorpay  → Settings → Webhooks → Add webhook URL
 * Cashfree  → Developers → Webhooks → Add endpoint
 */

require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/Razorpay.php';
require_once __DIR__ . '/lib/Cashfree.php';
require_once __DIR__ . '/lib/PayU.php';
require_once __DIR__ . '/lib/WebhookEvents.php';

header('Content-Type: application/json');

$gateway = strtolower(trim($_GET['gateway'] ?? ''));
$raw     = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!in_array($gateway, ['razorpay', 'cashfree', 'payu'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid gateway']);
    exit;
}

// ── Razorpay Webhook ──────────────────────────────────────────────────────────
if ($gateway === 'razorpay') {
    $signature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';

    $rp = new Razorpay($config['razorpay']['key_id'], $config['razorpay']['key_secret']);

    $webhook_secret = $config['razorpay']['webhook_secret'] ?? '';
    if (empty($webhook_secret) || str_starts_with($webhook_secret, 'ADD_') || !$rp->verifyWebhook($raw, $signature, $webhook_secret)) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid webhook signature']);
        exit;
    }

    $webhook_event = razorpay_webhook_event($payload ?? []);
    $event = $webhook_event['event'];
    $payment = $webhook_event['payment'];
    $order_id = $webhook_event['order_id'];
    $payment_id = $webhook_event['payment_id'];
    $new_status = $webhook_event['status'];
    $risk_details = $webhook_event['risk_details'];

    if ($order_id === '' && $payment_id === '') {
        http_response_code(200);
        echo json_encode(['ok' => true, 'note' => 'No order or payment reference in payload']);
        exit;
    }

    $txn = txn_find_by_gateway_reference('razorpay', $order_id, $payment_id);

    if (!$txn) {
        http_response_code(200);
        echo json_encode(['ok' => true, 'note' => 'Transaction not found for Razorpay reference']);
        exit;
    }

    // Already finalized — don't process again
    if (txn_is_final($txn) && !in_array($new_status, ['chargeback', 'refunded'], true)) {
        http_response_code(200);
        echo json_encode(['ok' => true, 'note' => 'Already processed']);
        exit;
    }

    if ($new_status) {
        with_payment_lock(function () use ($txn, $new_status, $payment_id, $payment, $payload, $risk_details) {
            $locked_txn = txn_load($txn['txn_id']);
            if (!$locked_txn || (txn_is_final($locked_txn) && !in_array($new_status, ['chargeback', 'refunded'], true))) return;

            if (in_array($new_status, ['chargeback', 'refunded'], true)) {
                mark_risk_status($locked_txn, $new_status, $payload, $payment_id, $risk_details);
                return;
            }

            if ($new_status === 'success') {
                [$ok, $error] = validate_gateway_success($locked_txn, [
                    'gateway'          => 'razorpay',
                    'gateway_order_id' => $payment['order_id'] ?? '',
                    'payment_id'       => $payment_id,
                    'amount'           => isset($payment['amount']) ? ((float)$payment['amount'] / 100) : null,
                    'currency'         => $payment['currency'] ?? '',
                    'txn_id'           => $payment['notes']['txn_id'] ?? '',
                    'customer_email'   => $payment['email'] ?? '',
                    'customer_phone'   => $payment['contact'] ?? '',
                ]);

                if (!$ok) {
                    $payload['validation_error'] = $error;
                    mark_non_success_status($locked_txn, 'validation_failed', $payload, $payment_id);
                    return;
                }

                mark_gateway_success_and_notify($locked_txn, $payment_id, $payload);
                return;
            }

            mark_non_success_status($locked_txn, $new_status, $payload, $payment_id);
        });
    }

    http_response_code(200);
    echo json_encode(['ok' => true]);
    exit;
}

// ── Cashfree Webhook ──────────────────────────────────────────────────────────
if ($gateway === 'cashfree') {
    $timestamp = $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? '';
    $signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE']  ?? '';

    $cf = new Cashfree($config['cashfree']['app_id'], $config['cashfree']['secret_key'], $config['cashfree']['base_url']);

    if (!$timestamp || !$signature) {
        http_response_code(401);
        echo json_encode(['error' => 'Missing webhook signature headers']);
        exit;
    }

    if (!$cf->verifyWebhook($timestamp, $raw, $signature)) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid webhook signature']);
        exit;
    }

    $webhook_event = cashfree_webhook_event($payload ?? []);
    $event = $webhook_event['event'];
    $order = $webhook_event['order'];
    $payment = $webhook_event['payment'];
    $cf_order_id = $webhook_event['order_id'];
    $cf_payment_id = $webhook_event['payment_id'];
    $new_status = $webhook_event['status'];
    $risk_details = $webhook_event['risk_details'];

    if ($cf_order_id === '' && $cf_payment_id === '') {
        http_response_code(200);
        echo json_encode(['ok' => true, 'note' => 'No order or payment reference in payload']);
        exit;
    }

    $txn = $cf_order_id !== '' ? txn_load($cf_order_id) : null;
    if (!$txn) {
        $txn = txn_find_by_gateway_reference('cashfree', $cf_order_id, $cf_payment_id);
    }

    if (!$txn) {
        http_response_code(200);
        echo json_encode(['ok' => true, 'note' => 'Transaction not found for Cashfree reference']);
        exit;
    }

    if (txn_is_final($txn) && !in_array($new_status, ['chargeback', 'refunded'], true)) {
        http_response_code(200);
        echo json_encode(['ok' => true, 'note' => 'Already processed']);
        exit;
    }

    if ($new_status) {
        with_payment_lock(function () use ($txn, $new_status, $cf_payment_id, $order, $payment, $payload, $risk_details) {
            $locked_txn = txn_load($txn['txn_id']);
            if (!$locked_txn || (txn_is_final($locked_txn) && !in_array($new_status, ['chargeback', 'refunded'], true))) return;

            if (in_array($new_status, ['chargeback', 'refunded'], true)) {
                mark_risk_status($locked_txn, $new_status, $payload, $cf_payment_id, $risk_details);
                return;
            }

            if ($new_status === 'success') {
                [$ok, $error] = validate_gateway_success($locked_txn, [
                    'gateway'          => 'cashfree',
                    'gateway_order_id' => $order['order_id'] ?? ($payment['order_id'] ?? ''),
                    'payment_id'       => $cf_payment_id,
                    'amount'           => $payment['payment_amount'] ?? ($order['order_amount'] ?? null),
                    'currency'         => $payment['payment_currency'] ?? ($order['order_currency'] ?? ''),
                    'customer_email'   => $order['customer_details']['customer_email'] ?? ($payload['data']['customer_details']['customer_email'] ?? ''),
                    'customer_phone'   => $order['customer_details']['customer_phone'] ?? ($payload['data']['customer_details']['customer_phone'] ?? ''),
                ]);

                if (!$ok) {
                    $payload['validation_error'] = $error;
                    mark_non_success_status($locked_txn, 'validation_failed', $payload, $cf_payment_id);
                    return;
                }

                mark_gateway_success_and_notify($locked_txn, $cf_payment_id, $payload);
                return;
            }

            mark_non_success_status($locked_txn, $new_status, $payload, $cf_payment_id);
        });
    }

    http_response_code(200);
    echo json_encode(['ok' => true]);
    exit;
}

// PayU callback/webhook. Configure PayU to POST the regular transaction response
// fields here if you want a server-side notification in addition to surl/furl.
if ($gateway === 'payu') {
    $payu = new PayU(
        $config['payu']['key'],
        $config['payu']['salt'],
        $config['payu']['payment_url'],
        $config['payu']['verify_url']
    );

    $chargeback_event = payu_chargeback_webhook_event($payload ?? []);
    if ($chargeback_event) {
        $signature = $_SERVER['HTTP_X_PAYU_DISPUTE_WEBHOOK_SIGNATURE_V2']
            ?? $_SERVER['HTTP_X_PAYU_DISPUTE_WEBHOOK_SIGNATURE_V1']
            ?? '';
        if (!$payu->verifyChargebackWebhook($payload, $signature)) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid PayU chargeback webhook signature']);
            exit;
        }

        $gateway_txn_id = $chargeback_event['payment_id'];
        $txn = txn_find_by_gateway_reference('payu', '', $gateway_txn_id);
        if (!$txn) {
            http_response_code(200);
            echo json_encode(['ok' => true, 'note' => 'Transaction not found for PayU reference']);
            exit;
        }

        with_payment_lock(function () use ($txn, $gateway_txn_id, $payload, $chargeback_event) {
            $locked_txn = txn_load($txn['txn_id']);
            if (!$locked_txn) return;

            mark_risk_status(
                $locked_txn,
                'chargeback',
                $payload,
                $gateway_txn_id,
                $chargeback_event['risk_details']
            );
        });

        http_response_code(200);
        echo json_encode(['ok' => true]);
        exit;
    }

    $response = $_POST ?: $_GET;

    if (!$response && $payload) {
        $response = $payload;
    }

    $payu_txnid = $response['txnid'] ?? '';
    if (empty($payu_txnid)) {
        http_response_code(200);
        echo json_encode(['ok' => true, 'note' => 'No txnid in payload']);
        exit;
    }

    $txn = txn_load($payu_txnid);
    if (!$txn) {
        http_response_code(200);
        echo json_encode(['ok' => true, 'note' => 'Transaction not found: ' . $payu_txnid]);
        exit;
    }

    if (txn_is_final($txn)) {
        http_response_code(200);
        echo json_encode(['ok' => true, 'note' => 'Already processed']);
        exit;
    }

    $hash_ok = $payu->verifyPaymentHash($response);
    $amount_ok = isset($response['amount']) && abs(((float)$response['amount']) - ((float)$txn['amount'])) < 0.01;
    $payu_status = strtolower((string)($response['status'] ?? ''));
    $gateway_txn_id = $response['mihpayid'] ?? null;

    if (!$hash_ok || !$amount_ok) {
        http_response_code(401);
        echo json_encode(['error' => !$hash_ok ? 'Invalid PayU hash' : 'PayU amount mismatch']);
        exit;
    }

    $verify_response = $payu->verifyPayment($payu_txnid);
    $verified_status = $payu->extractVerifiedStatus($verify_response, $payu_txnid);

    $new_status = match(true) {
        $payu_status === 'success' && $verified_status === 'success' => 'success',
        in_array($payu_status, ['failure', 'failed', 'cancel', 'cancelled']) || in_array($verified_status, ['failure', 'failed', 'not found']) => 'failed',
        default => null,
    };

    if ($new_status) {
        with_payment_lock(function () use ($txn, $new_status, $gateway_txn_id, $response, $verify_response) {
            $locked_txn = txn_load($txn['txn_id']);
            if (!$locked_txn || txn_is_final($locked_txn)) return;

            $gateway_response = [
                'callback'       => $response,
                'verify_payment' => $verify_response,
            ];

            if ($new_status === 'success') {
                [$ok, $error] = validate_gateway_success($locked_txn, [
                    'gateway'          => 'payu',
                    'gateway_order_id' => $response['txnid'] ?? '',
                    'payment_id'       => $gateway_txn_id,
                    'amount'           => $response['amount'] ?? null,
                    'currency'         => $locked_txn['currency'] ?? 'INR',
                    'txn_id'           => $response['udf2'] ?? '',
                    'order_id'         => $response['udf1'] ?? '',
                    'customer_email'   => $response['email'] ?? '',
                    'customer_phone'   => $response['phone'] ?? '',
                ]);

                if (!$ok) {
                    $gateway_response['validation_error'] = $error;
                    mark_non_success_status($locked_txn, 'validation_failed', $gateway_response, $gateway_txn_id);
                    return;
                }

                mark_gateway_success_and_notify($locked_txn, $gateway_txn_id, $gateway_response);
                return;
            }

            mark_non_success_status($locked_txn, $new_status, $gateway_response, $gateway_txn_id);
        });
    }

    http_response_code(200);
    echo json_encode(['ok' => true]);
    exit;
}
