<?php
/**
 * Hosted Payment Page
 * URL: /pay.php?txn=TXN_XXXXX
 * Customer is redirected here by the merchant.
 */

require_once __DIR__ . '/lib/helpers.php';

$txn_id = trim($_GET['txn'] ?? '');
if (empty($txn_id)) {
    http_response_code(400);
    die('Invalid payment link.');
}

$txn = txn_load($txn_id);
if (!$txn) {
    http_response_code(404);
    die('Payment link not found or expired.');
}

$payment_page_ip = client_ip_address();
if ($payment_page_ip !== 'unknown') {
    $ip_update = [
        'ip_address' => $payment_page_ip,
        'payment_page_ip' => $payment_page_ip,
        'payment_page_seen_at' => date('c'),
    ];

    if (!empty($txn['ip_address']) && empty($txn['initiation_ip_address']) && $txn['ip_address'] !== $payment_page_ip) {
        $ip_update['initiation_ip_address'] = $txn['ip_address'];
    }

    if (($txn['ip_address'] ?? '') !== $payment_page_ip || ($txn['payment_page_ip'] ?? '') !== $payment_page_ip) {
        txn_update($txn_id, $ip_update);
        $txn = array_merge($txn, $ip_update);
    }
}

if ($txn['status'] === 'paid') {
    header('Location: ' . $txn['return_url'] . '?txn_id=' . $txn_id . '&status=paid&order_id=' . urlencode($txn['order_id']));
    exit;
}

if ($txn['status'] === 'failed') {
    header('Location: ' . $txn['return_url'] . '?txn_id=' . $txn_id . '&status=failed&order_id=' . urlencode($txn['order_id']));
    exit;
}

$gateway = $txn['gateway'];
$amount = $txn['amount'];
$currency = $txn['currency'];
$pay_url = $config['pay_url'];
$return_cb = $pay_url . '/return.php?txn=' . urlencode($txn_id);
$request_timestamp = strtotime($txn['created_at'] ?? '') ?: time();
$request_date = date('M j, Y', $request_timestamp);
$gateway_name = ucfirst($gateway);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#111418">
<title>Deposit Details - <?= $currency === 'INR' ? 'Rs ' : htmlspecialchars($currency . ' ') ?><?= number_format($amount, 2) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<?php if ($gateway === 'razorpay'): ?>
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<?php elseif ($gateway === 'cashfree'): ?>
<script src="https://sdk.cashfree.com/js/v3/cashfree.js"></script>
<?php endif; ?>
<style>
  *, *::before, *::after {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
  }

  :root {
    --page: #111418;
    --surface: #ffffff;
    --panel: #f5f6f8;
    --line: #e3e6eb;
    --text: #171920;
    --muted: #666b75;
    --blue: #7892ff;
    --blue-dark: #627df4;
    --green: #15976c;
    --warning: #a96b00;
    --danger: #c53c4d;
  }

  body {
    min-height: 100vh;
    min-height: 100svh;
    padding: 18px;
    display: grid;
    place-items: center;
    background:
      radial-gradient(circle at 50% -15%, rgba(120, 146, 255, .16), transparent 36%),
      var(--page);
    color: var(--text);
    font-family: Inter, Arial, sans-serif;
  }

  .payment-card {
    width: min(100%, 392px);
    padding: 22px 20px 18px;
    overflow: hidden;
    background: var(--surface);
    border: 1px solid rgba(255, 255, 255, .65);
    border-radius: 20px;
    box-shadow: 0 24px 70px rgba(0, 0, 0, .38);
  }

  .page-heading {
    font-size: 22px;
    line-height: 1.2;
    font-weight: 700;
    letter-spacing: 0;
  }

  .page-subtitle {
    margin-top: 4px;
    color: var(--muted);
    font-size: 12px;
    line-height: 1.45;
  }

  .details-panel {
    margin-top: 18px;
    padding: 10px 14px;
    background: var(--panel);
    border: 1px solid #f0f1f3;
    border-radius: 14px;
  }

  .detail-row {
    min-height: 39px;
    display: grid;
    grid-template-columns: minmax(90px, .8fr) minmax(0, 1.4fr);
    align-items: center;
    gap: 16px;
    border-bottom: 1px solid var(--line);
  }

  .detail-row:last-child {
    border-bottom: 0;
  }

  .detail-label {
    color: #555b66;
    font-size: 11px;
    line-height: 1.35;
  }

  .detail-value {
    min-width: 0;
    color: var(--text);
    font-size: 12px;
    font-weight: 500;
    line-height: 1.35;
    text-align: right;
    overflow-wrap: anywhere;
  }

  .amount-value {
    font-size: 16px;
    font-weight: 700;
  }

  .gateway-value,
  .status-value {
    display: inline-flex;
    align-items: center;
    justify-content: flex-end;
    gap: 7px;
  }

  .gateway-mark {
    width: 19px;
    height: 19px;
    flex: 0 0 19px;
    display: inline-grid;
    place-items: center;
    border-radius: 50%;
    background: var(--blue);
    color: #fff;
    font-size: 10px;
    font-weight: 700;
  }

  .status-dot {
    width: 7px;
    height: 7px;
    flex: 0 0 7px;
    border-radius: 50%;
    background: #efa719;
    box-shadow: 0 0 0 3px rgba(239, 167, 25, .14);
  }

  .notice {
    margin: 17px 2px 0;
    display: grid;
    grid-template-columns: 16px 1fr;
    gap: 8px;
    color: var(--muted);
    font-size: 11px;
    line-height: 1.5;
  }

  .notice-icon {
    width: 13px;
    height: 13px;
    margin-top: 1px;
    display: inline-grid;
    place-items: center;
    border: 1.5px solid #616771;
    border-radius: 50%;
    color: #555b66;
    font-size: 8px;
    font-weight: 700;
  }

  .alert {
    margin-top: 14px;
    padding: 10px 12px;
    border: 1px solid rgba(197, 60, 77, .18);
    border-radius: 10px;
    background: rgba(197, 60, 77, .08);
    color: var(--danger);
    font-size: 11px;
    line-height: 1.45;
  }

  .pay-btn {
    width: 100%;
    min-height: 47px;
    margin-top: 18px;
    padding: 12px 18px;
    border: 1px solid #5f78ef;
    border-radius: 999px;
    background: linear-gradient(180deg, #9db9ff 0%, #7899ff 48%, #6b7ff0 100%);
    box-shadow:
      inset 0 1px 0 rgba(255, 255, 255, .7),
      0 8px 20px rgba(83, 108, 224, .24);
    color: #fff;
    font: 600 14px/1 Inter, Arial, sans-serif;
    text-shadow: 0 1px 2px rgba(43, 61, 145, .3);
    cursor: pointer;
    transition: transform .18s ease, box-shadow .18s ease, filter .18s ease;
  }

  .pay-btn:hover {
    transform: translateY(-1px);
    filter: saturate(1.05);
    box-shadow:
      inset 0 1px 0 rgba(255, 255, 255, .75),
      0 11px 24px rgba(83, 108, 224, .3);
  }

  .pay-btn:active {
    transform: translateY(0);
  }

  .pay-btn:focus-visible {
    outline: 3px solid rgba(98, 125, 244, .3);
    outline-offset: 3px;
  }

  .pay-btn:disabled {
    cursor: wait;
    filter: grayscale(.15);
    opacity: .72;
    transform: none;
  }

  .spinner {
    display: none;
    width: 17px;
    height: 17px;
    margin: 0 auto;
    border: 2px solid rgba(255, 255, 255, .4);
    border-top-color: #fff;
    border-radius: 50%;
    animation: spin .7s linear infinite;
  }

  .pay-btn.loading .btn-text {
    display: none;
  }

  .pay-btn.loading .spinner {
    display: block;
  }

  .secure-note {
    margin-top: 10px;
    color: #8b9098;
    font-size: 9px;
    line-height: 1.4;
    text-align: center;
  }

  @keyframes spin {
    to { transform: rotate(360deg); }
  }

  @media (max-width: 480px) {
    body {
      padding: 0;
      align-items: stretch;
      background: var(--surface);
    }

    .payment-card {
      width: 100%;
      min-height: 100svh;
      padding: 24px 18px 16px;
      display: flex;
      flex-direction: column;
      border: 0;
      border-radius: 0;
      box-shadow: none;
    }

    .notice {
      margin-top: 18px;
    }

    .payment-actions {
      margin-top: auto;
      padding-top: 14px;
    }

    .pay-btn {
      margin-top: 0;
    }
  }

  @media (max-height: 620px) and (max-width: 480px) {
    .payment-card {
      padding-top: 16px;
    }

    .details-panel {
      margin-top: 12px;
    }

    .detail-row {
      min-height: 35px;
    }

    .notice {
      margin-top: 12px;
    }

    .payment-actions {
      padding-top: 10px;
    }
  }
</style>
</head>
<body>

<main class="payment-card">
  <header>
    <h1 class="page-heading">Deposit Details</h1>
    <p class="page-subtitle">Review your payment information</p>
  </header>

  <section class="details-panel" aria-label="Payment details">
    <div class="detail-row">
      <span class="detail-label">Amount</span>
      <span class="detail-value amount-value"><?= $currency === 'INR' ? '&#8377;' : htmlspecialchars($currency . ' ') ?><?= number_format($amount, 2) ?></span>
    </div>
    <div class="detail-row">
      <span class="detail-label">Request date</span>
      <span class="detail-value"><?= htmlspecialchars($request_date) ?></span>
    </div>
    <div class="detail-row">
      <span class="detail-label">For</span>
      <span class="detail-value"><?= htmlspecialchars($txn['description']) ?></span>
    </div>
    <div class="detail-row">
      <span class="detail-label">Customer</span>
      <span class="detail-value"><?= htmlspecialchars($txn['customer_name']) ?></span>
    </div>
    <div class="detail-row">
      <span class="detail-label">Gateway</span>
      <span class="detail-value gateway-value">
        <span class="gateway-mark" aria-hidden="true"><?= htmlspecialchars(strtoupper(substr($gateway_name, 0, 1))) ?></span>
        <?= htmlspecialchars($gateway_name) ?>
      </span>
    </div>
    <div class="detail-row">
      <span class="detail-label">Order ID</span>
      <span class="detail-value"><?= htmlspecialchars($txn['order_id']) ?></span>
    </div>
    <div class="detail-row">
      <span class="detail-label">Status</span>
      <span class="detail-value status-value">
        <span class="status-dot" aria-hidden="true"></span>
        Awaiting payment
      </span>
    </div>
  </section>

  <div id="error-box" class="alert" role="alert" style="display:none;"></div>

  <p class="notice">
    <span class="notice-icon" aria-hidden="true">i</span>
    <span>Payments are securely processed by <?= htmlspecialchars($gateway_name) ?>. For larger transactions, additional verification may be required.</span>
  </p>

  <div class="payment-actions">
    <?php if ($gateway === 'payu'): ?>
    <form id="payuForm" method="POST" action="<?= htmlspecialchars($txn['payu_payment_url'] ?? '') ?>" style="display:none;">
      <?php foreach (($txn['payu_form_fields'] ?? []) as $field => $value): ?>
        <input type="hidden" name="<?= htmlspecialchars($field) ?>" value="<?= htmlspecialchars((string)$value) ?>">
      <?php endforeach; ?>
    </form>
    <?php endif; ?>

    <button class="pay-btn" id="payBtn" type="button" onclick="initiatePayment()">
      <span class="btn-text">Continue to Pay <?= $currency === 'INR' ? '&#8377;' : htmlspecialchars($currency . ' ') ?><?= number_format($amount, 2) ?></span>
      <span class="spinner" aria-hidden="true"></span>
    </button>
    <p class="secure-note">Secure checkout powered by <?= htmlspecialchars($gateway_name) ?></p>
  </div>
</main>

<script>
const TXN_ID = <?= json_encode($txn_id) ?>;
const GATEWAY = <?= json_encode($gateway) ?>;
const RETURN_CB = <?= json_encode($return_cb) ?>;
<?php if ($gateway === 'razorpay'): ?>
const RZP_KEY = <?= json_encode($config['razorpay']['key_id']) ?>;
const RZP_ORDER_ID = <?= json_encode($txn['gateway_order_id']) ?>;
const RZP_AMOUNT = <?= (int)($amount * 100) ?>;
const CUSTOMER_NAME = <?= json_encode($txn['customer_name']) ?>;
const CUSTOMER_EMAIL = <?= json_encode($txn['customer_email']) ?>;
const CUSTOMER_PHONE = <?= json_encode($txn['customer_phone']) ?>;
<?php elseif ($gateway === 'cashfree'): ?>
const CF_SESSION_ID = <?= json_encode($txn['cf_payment_session_id'] ?? '') ?>;
const CF_PAYMENT_LINK = <?= json_encode($txn['cf_payment_link'] ?? '') ?>;
<?php elseif ($gateway === 'payu'): ?>
const PAYU_READY = <?= !empty($txn['payu_payment_url']) && !empty($txn['payu_form_fields']) ? 'true' : 'false' ?>;
<?php endif; ?>

function showError(message) {
  const errorBox = document.getElementById('error-box');
  errorBox.textContent = message;
  errorBox.style.display = 'block';
}

function setLoading(isLoading) {
  const button = document.getElementById('payBtn');
  button.disabled = isLoading;
  button.classList.toggle('loading', isLoading);
}

function initiatePayment() {
  setLoading(true);

  <?php if ($gateway === 'razorpay'): ?>
  const options = {
    key: RZP_KEY,
    amount: RZP_AMOUNT,
    currency: <?= json_encode($currency) ?>,
    order_id: RZP_ORDER_ID,
    name: 'Fintrack',
    description: <?= json_encode($txn['description']) ?>,
    prefill: {
      name: CUSTOMER_NAME,
      email: CUSTOMER_EMAIL,
      contact: CUSTOMER_PHONE,
    },
    theme: { color: '#6b7ff0' },
    handler: function(response) {
      const form = document.createElement('form');
      form.method = 'POST';
      form.action = RETURN_CB;
      const fields = {
        razorpay_payment_id: response.razorpay_payment_id,
        razorpay_order_id: response.razorpay_order_id,
        razorpay_signature: response.razorpay_signature,
      };

      for (const [key, value] of Object.entries(fields)) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = key;
        input.value = value;
        form.appendChild(input);
      }

      document.body.appendChild(form);
      form.submit();
    },
    modal: {
      ondismiss: function() {
        setLoading(false);
      }
    }
  };

  const razorpay = new Razorpay(options);
  razorpay.on('payment.failed', function(response) {
    setLoading(false);
    showError('Payment failed: ' + (response.error.description || 'Unknown error'));
  });
  razorpay.open();

  <?php elseif ($gateway === 'cashfree'): ?>
  if (CF_PAYMENT_LINK) {
    window.location.href = CF_PAYMENT_LINK;
  } else if (CF_SESSION_ID) {
    const cashfree = Cashfree({ mode: 'production' });
    cashfree.checkout({
      paymentSessionId: CF_SESSION_ID,
      redirectTarget: '_self',
    }).then(function(result) {
      if (result.error) {
        setLoading(false);
        showError(result.error.message || 'Payment failed');
      }
    });
  } else {
    setLoading(false);
    showError('Payment session not available. Please contact support.');
  }
  <?php elseif ($gateway === 'payu'): ?>
  const form = document.getElementById('payuForm');
  if (PAYU_READY && form) {
    form.submit();
  } else {
    setLoading(false);
    showError('PayU payment data not available. Please contact support.');
  }
  <?php endif; ?>
}
</script>
</body>
</html>
