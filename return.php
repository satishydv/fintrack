<?php
/**
 * /return.php - handles redirect back from Razorpay, Cashfree, or PayU.
 */

require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/Razorpay.php';
require_once __DIR__ . '/lib/Cashfree.php';
require_once __DIR__ . '/lib/PayU.php';

$txn_id = trim($_GET['txn'] ?? $_POST['txn'] ?? '');

if (empty($txn_id)) {
    die('Missing transaction ID.');
}

$txn = txn_load($txn_id);
if (!$txn) {
    die('Transaction not found.');
}

if (txn_is_final($txn)) {
    $redirect_status = $txn['status'];
    goto do_redirect;
}

$gateway = $txn['gateway'];
$status = 'failed';
$gateway_txn_id = null;
$gateway_response = [];

try {
    if ($gateway === 'razorpay') {
        $rp = new Razorpay($config['razorpay']['key_id'], $config['razorpay']['key_secret']);

        $payment_id = $_POST['razorpay_payment_id'] ?? '';
        $order_id = $_POST['razorpay_order_id'] ?? $txn['gateway_order_id'];
        $signature = $_POST['razorpay_signature'] ?? '';

        if ($payment_id && $signature) {
            $valid = $rp->verifySignature($order_id, $payment_id, $signature);

            if ($valid) {
                $payment_details = $rp->fetchPayment($payment_id);
                $rp_status = strtolower($payment_details['status'] ?? '');
                $gateway_txn_id = $payment_id;
                $gateway_response = $payment_details;

                if ($rp_status === 'captured') {
                    $status = 'success';
                } elseif ($rp_status === 'authorized') {
                    $status = 'authorized';
                } else {
                    $status = 'failed';
                }
            } else {
                $status = 'failed';
                $gateway_response = ['error' => 'Invalid signature'];
            }
        } else {
            $status = 'failed';
            $gateway_response = ['error' => 'Missing payment_id or signature'];
        }
    } elseif ($gateway === 'cashfree') {
        $cf = new Cashfree($config['cashfree']['app_id'], $config['cashfree']['secret_key'], $config['cashfree']['base_url']);

        $cf_order_id = $_GET['order_id'] ?? $txn['gateway_order_id'];
        $order_status = $cf->getOrderStatus($cf_order_id);
        $gateway_response = $order_status;

        $cf_status = strtoupper($order_status['order_status'] ?? '');

        if ($cf_status === 'PAID') {
            $payments = $cf->getOrderPayments($cf_order_id);
            $gateway_response = array_merge($gateway_response, ['payments' => $payments]);

            foreach ($payments as $payment) {
                if (strtoupper($payment['payment_status'] ?? '') === 'SUCCESS') {
                    $status = 'success';
                    $gateway_txn_id = $payment['cf_payment_id'] ?? null;
                    $gateway_response['success_payment'] = $payment;
                    break;
                }
            }

            if ($status !== 'success') {
                $status = 'pending';
            }
        } elseif (in_array($cf_status, ['EXPIRED', 'CANCELLED', 'FAILED', 'USER_DROPPED'], true)) {
            $status = 'failed';
        } else {
            $status = 'pending';
        }
    } elseif ($gateway === 'payu') {
        $payu = new PayU(
            $config['payu']['key'],
            $config['payu']['salt'],
            $config['payu']['payment_url'],
            $config['payu']['verify_url']
        );

        $response = $_POST ?: $_GET;
        $payu_txnid = $response['txnid'] ?? '';
        $payu_status = strtolower((string)($response['status'] ?? ''));
        $gateway_txn_id = $response['mihpayid'] ?? null;
        $gateway_response = ['redirect' => $response];

        $hash_ok = $payu->verifyPaymentHash($response);
        $amount_ok = isset($response['amount']) && abs(((float)$response['amount']) - ((float)$txn['amount'])) < 0.01;

        if ($payu_txnid !== $txn['txn_id']) {
            $status = 'failed';
            $gateway_response['error'] = 'PayU txnid mismatch';
        } elseif (!$hash_ok) {
            $status = 'failed';
            $gateway_response['error'] = 'Invalid PayU hash';
        } elseif (!$amount_ok) {
            $status = 'failed';
            $gateway_response['error'] = 'PayU amount mismatch';
        } else {
            $verify_response = $payu->verifyPayment($txn['txn_id']);
            $verified_status = $payu->extractVerifiedStatus($verify_response, $txn['txn_id']);
            $gateway_response['verify_payment'] = $verify_response;

            if ($payu_status === 'success' && $verified_status === 'success') {
                $status = 'success';
            } elseif (in_array($payu_status, ['failure', 'failed', 'cancel', 'cancelled'], true) || in_array($verified_status, ['failure', 'failed', 'not found'], true)) {
                $status = 'failed';
            } else {
                $status = 'pending';
            }
        }
    }
} catch (Exception $e) {
    log_error('[return] ' . $e->getMessage() . ' | txn: ' . $txn_id);
    $status = 'failed';
    $gateway_response = ['exception' => $e->getMessage()];
}

$txn = with_payment_lock(function () use ($txn_id, $txn, $gateway, $status, $gateway_txn_id, $gateway_response) {
    $locked_txn = txn_load($txn_id);
    if (!$locked_txn || txn_is_final($locked_txn)) {
        return $locked_txn ?: $txn;
    }

    if ($status === 'success') {
        if ($gateway === 'razorpay') {
            $payment = $gateway_response;
            [$ok, $error] = validate_gateway_success($locked_txn, [
                'gateway' => 'razorpay',
                'gateway_order_id' => $payment['order_id'] ?? '',
                'payment_id' => $gateway_txn_id,
                'amount' => isset($payment['amount']) ? ((float)$payment['amount'] / 100) : null,
                'currency' => $payment['currency'] ?? '',
                'txn_id' => $payment['notes']['txn_id'] ?? '',
                'customer_email' => $payment['email'] ?? '',
                'customer_phone' => $payment['contact'] ?? '',
            ]);
        } elseif ($gateway === 'cashfree') {
            $payment = $gateway_response['success_payment'] ?? [];
            [$ok, $error] = validate_gateway_success($locked_txn, [
                'gateway' => 'cashfree',
                'gateway_order_id' => $payment['order_id'] ?? ($gateway_response['order_id'] ?? ''),
                'payment_id' => $gateway_txn_id,
                'amount' => $payment['payment_amount'] ?? ($payment['order_amount'] ?? null),
                'currency' => $payment['payment_currency'] ?? ($gateway_response['order_currency'] ?? ''),
                'customer_email' => $gateway_response['customer_details']['customer_email'] ?? '',
                'customer_phone' => $gateway_response['customer_details']['customer_phone'] ?? '',
            ]);
        } else {
            $redirect = $gateway_response['redirect'] ?? [];
            [$ok, $error] = validate_gateway_success($locked_txn, [
                'gateway' => 'payu',
                'gateway_order_id' => $redirect['txnid'] ?? '',
                'payment_id' => $gateway_txn_id,
                'amount' => $redirect['amount'] ?? null,
                'currency' => $locked_txn['currency'] ?? 'INR',
                'txn_id' => $redirect['udf2'] ?? '',
                'order_id' => $redirect['udf1'] ?? '',
                'customer_email' => $redirect['email'] ?? '',
                'customer_phone' => $redirect['phone'] ?? '',
            ]);
        }

        if (!$ok) {
            $gateway_response['validation_error'] = $error;
            return mark_non_success_status($locked_txn, 'validation_failed', $gateway_response, $gateway_txn_id);
        }

        return mark_gateway_success_and_notify($locked_txn, $gateway_txn_id, $gateway_response);
    }

    return mark_non_success_status($locked_txn, $status, $gateway_response, $gateway_txn_id);
});

$redirect_status = $status;
if (($txn['status'] ?? '') === 'paid') {
    $redirect_status = 'paid';
} elseif (($txn['status'] ?? '') === 'notify_failed') {
    $redirect_status = 'pending';
}

do_redirect:
$return_url = $txn['return_url'];
$separator = strpos($return_url, '?') !== false ? '&' : '?';
$final_url = $return_url
    . $separator
    . http_build_query([
        'txn_id' => $txn_id,
        'order_id' => $txn['order_id'],
        'status' => $redirect_status,
        'gateway' => $txn['gateway'],
        'amount' => $txn['amount'],
    ]);

header('Location: ' . $final_url);
exit;
