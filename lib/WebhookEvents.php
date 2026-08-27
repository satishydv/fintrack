<?php

function webhook_first_value(array $values): string {
    foreach ($values as $value) {
        if ($value !== null && $value !== '') {
            return (string)$value;
        }
    }

    return '';
}

function cashfree_webhook_event(array $payload): array {
    $event = strtoupper((string)($payload['type'] ?? ''));
    $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
    $order = is_array($data['order'] ?? null) ? $data['order'] : [];
    $payment = is_array($data['payment'] ?? null) ? $data['payment'] : [];
    $order_details = is_array($data['order_details'] ?? null) ? $data['order_details'] : [];
    $dispute = is_array($data['dispute'] ?? null) ? $data['dispute'] : [];
    $refund = is_array($data['refund'] ?? null)
        ? $data['refund']
        : (is_array($data['auto_refund'] ?? null) ? $data['auto_refund'] : []);

    $order_id = webhook_first_value([
        $order['order_id'] ?? null,
        $order_details['order_id'] ?? null,
        $payment['order_id'] ?? null,
        $refund['order_id'] ?? null,
    ]);
    $payment_id = webhook_first_value([
        $payment['cf_payment_id'] ?? null,
        $order_details['cf_payment_id'] ?? null,
        $refund['cf_payment_id'] ?? null,
    ]);

    $is_dispute = str_contains($event, 'DISPUTE') || str_contains($event, 'CHARGEBACK');
    $is_refund = str_contains($event, 'REFUND');
    $payment_status = strtoupper(webhook_first_value([
        $payment['payment_status'] ?? null,
        $order['order_status'] ?? null,
    ]));
    $refund_status = strtoupper((string)($refund['refund_status'] ?? ''));

    $status = match (true) {
        $is_dispute => 'chargeback',
        $is_refund && $refund_status === 'SUCCESS' => 'refunded',
        $is_refund => null,
        $payment_status === 'SUCCESS' => 'success',
        in_array($payment_status, ['FAILED', 'CANCELLED', 'EXPIRED', 'USER_DROPPED'], true) => 'failed',
        in_array($payment_status, ['PENDING', 'ACTIVE', 'PAID'], true) => 'pending',
        default => null,
    };

    $risk_details = [];
    if ($is_dispute) {
        $risk_details = [
            'provider' => 'cashfree',
            'type' => 'chargeback',
            'event' => $event,
            'id' => $dispute['dispute_id'] ?? null,
            'status' => $dispute['dispute_status'] ?? null,
            'amount' => isset($dispute['dispute_amount']) ? (float)$dispute['dispute_amount'] : null,
            'currency' => $dispute['dispute_amount_currency'] ?? ($order_details['payment_currency'] ?? null),
            'reason' => $dispute['reason_description'] ?? null,
            'respond_by' => $dispute['respond_by'] ?? null,
        ];
    } elseif ($status === 'refunded') {
        $risk_details = [
            'provider' => 'cashfree',
            'type' => 'refund',
            'event' => $event,
            'id' => $refund['cf_refund_id'] ?? ($refund['refund_id'] ?? null),
            'status' => $refund_status,
            'amount' => isset($refund['refund_amount']) ? (float)$refund['refund_amount'] : null,
            'currency' => $refund['refund_currency'] ?? null,
            'reason' => $refund['refund_reason'] ?? ($refund['status_description'] ?? null),
        ];
    }

    return [
        'event' => $event,
        'status' => $status,
        'order_id' => $order_id,
        'payment_id' => $payment_id,
        'order' => $order,
        'payment' => $payment,
        'risk_details' => array_filter($risk_details, static fn($value) => $value !== null && $value !== ''),
    ];
}

function razorpay_webhook_event(array $payload): array {
    $event = strtolower((string)($payload['event'] ?? ''));
    $payment = is_array($payload['payload']['payment']['entity'] ?? null)
        ? $payload['payload']['payment']['entity']
        : [];
    $dispute = is_array($payload['payload']['dispute']['entity'] ?? null)
        ? $payload['payload']['dispute']['entity']
        : [];
    $refund = is_array($payload['payload']['refund']['entity'] ?? null)
        ? $payload['payload']['refund']['entity']
        : [];

    $payment_id = webhook_first_value([
        $payment['id'] ?? null,
        $dispute['payment_id'] ?? null,
        $refund['payment_id'] ?? null,
    ]);
    $is_dispute = str_contains($event, 'dispute') || str_contains($event, 'chargeback');
    $refund_processed = $event === 'refund.processed'
        || $event === 'payment.refunded'
        || (str_contains($event, 'refund') && strtolower((string)($refund['status'] ?? '')) === 'processed');

    $status = match (true) {
        $is_dispute => 'chargeback',
        $refund_processed => 'refunded',
        $event === 'payment.captured' && ($payment['status'] ?? '') === 'captured' => 'success',
        $event === 'payment.authorized' || ($payment['status'] ?? '') === 'authorized' => 'authorized',
        $event === 'payment.failed' => 'failed',
        default => null,
    };

    $risk_details = [];
    if ($is_dispute) {
        $risk_details = [
            'provider' => 'razorpay',
            'type' => 'chargeback',
            'event' => $event,
            'id' => $dispute['id'] ?? null,
            'status' => $dispute['status'] ?? null,
            'amount' => isset($dispute['amount']) ? ((float)$dispute['amount'] / 100) : null,
            'currency' => $dispute['currency'] ?? null,
            'reason' => $dispute['reason_code'] ?? null,
            'respond_by' => $dispute['respond_by'] ?? null,
        ];
    } elseif ($status === 'refunded') {
        $risk_details = [
            'provider' => 'razorpay',
            'type' => 'refund',
            'event' => $event,
            'id' => $refund['id'] ?? null,
            'status' => $refund['status'] ?? null,
            'amount' => isset($refund['amount']) ? ((float)$refund['amount'] / 100) : null,
            'currency' => $refund['currency'] ?? null,
        ];
    }

    return [
        'event' => $event,
        'status' => $status,
        'order_id' => (string)($payment['order_id'] ?? ''),
        'payment_id' => $payment_id,
        'payment' => $payment,
        'risk_details' => array_filter($risk_details, static fn($value) => $value !== null && $value !== ''),
    ];
}

function payu_chargeback_webhook_event(array $payload): ?array {
    if (strtolower((string)($payload['type'] ?? '')) !== 'payments'
        || strtolower((string)($payload['event'] ?? '')) !== 'dispute') {
        return null;
    }

    return [
        'status' => 'chargeback',
        'payment_id' => (string)($payload['txn_id'] ?? ''),
        'risk_details' => array_filter([
            'provider' => 'payu',
            'type' => 'chargeback',
            'event' => 'dispute',
            'id' => $payload['cb_id'] ?? null,
            'status' => $payload['cb_status'] ?? null,
            'amount' => isset($payload['cb_amount']) ? (float)$payload['cb_amount'] : null,
            'reason' => $payload['reason_code'] ?? null,
            'respond_by' => $payload['due_date'] ?? null,
        ], static fn($value) => $value !== null && $value !== ''),
    ];
}
