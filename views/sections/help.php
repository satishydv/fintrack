<section class="help-hero">
  <div class="eyebrow"><span class="eyebrow-dot"></span>Developer quickstart</div>
  <h1>Accept your first orchestrated payment.</h1>
  <p>Connect your server to Fintrack once, choose Razorpay, Cashfree, or PayU per request, and receive one consistent payment lifecycle.</p>
  <div class="help-actions">
    <button class="primary-btn" onclick="document.getElementById('step-create').scrollIntoView({behavior:'smooth'})">Start integration</button>
    <button class="secondary-btn" data-copy-text="<?= htmlspecialchars($base_url) ?>" onclick="copyText(this)">Copy base URL</button>
  </div>
</section>

<div class="help-layout">
  <div class="integration-steps">
    <section class="integration-step">
      <div class="step-number">01</div>
      <div class="step-content">
        <h2>Configure server credentials</h2>
        <p>Keep the shared API key on your backend only. Never expose it in browser JavaScript or a mobile app bundle.</p>
        <div class="env-strip"><code>PAYMENT_BASE_URL=<?= htmlspecialchars($base_url) ?><br>PAYMENT_API_KEY=YOUR_SHARED_SECRET</code><button class="copy-btn" data-copy-text="PAYMENT_BASE_URL=<?= htmlspecialchars($base_url) ?>&#10;PAYMENT_API_KEY=YOUR_SHARED_SECRET" onclick="copyText(this)">Copy</button></div>
      </div>
    </section>

    <section class="integration-step" id="step-create">
      <div class="step-number">02</div>
      <div class="step-content">
        <h2>Create a payment</h2>
        <p>Call the initiate endpoint from your server. Save the returned <span class="mono">txn_id</span>, then redirect the customer to <span class="mono">payment_url</span>.</p>
        <div class="endpoint-strip"><span class="method">POST</span><code><?= htmlspecialchars($base_url) ?>/api/initiate.php</code></div>
        <div class="code-wrap">
          <div class="code-label"><span>cURL · server-side</span><button class="copy-btn" data-copy-target="createPaymentCode" onclick="copyCode(this)">Copy code</button></div>
          <pre class="code-block" id="createPaymentCode">curl -X POST "<?= htmlspecialchars($base_url) ?>/api/initiate.php" \
  -H "X-Api-Key: YOUR_SHARED_SECRET" \
  -H "Content-Type: application/json" \
  -d '{
    "order_id": "ORD-1042",
    "amount": 499.00,
    "currency": "INR",
    "customer_name": "Aarav Mehta",
    "customer_email": "aarav@example.com",
    "customer_phone": "9876543210",
    "gateway": "razorpay",
    "return_url": "https://yourapp.com/payment/return",
    "webhook_url": "https://yourapp.com/api/payment/webhook",
    "description": "Order #1042"
  }'</pre>
        </div>
        <div class="inline-note">Supported gateway values: <strong>razorpay</strong>, <strong>cashfree</strong>, and <strong>payu</strong>. Amount must be greater than zero.</div>
      </div>
    </section>

    <section class="integration-step">
      <div class="step-number">03</div>
      <div class="step-content">
        <h2>Redirect to hosted checkout</h2>
        <p>A successful initiation returns a secure hosted URL. Redirect the customer there; Fintrack handles the gateway-specific checkout.</p>
        <div class="code-wrap">
          <div class="code-label"><span>Success response</span><button class="copy-btn" data-copy-target="successResponseCode" onclick="copyCode(this)">Copy</button></div>
          <pre class="code-block" id="successResponseCode">{
  "success": true,
  "txn_id": "TXN_ABC123_1704067200",
  "payment_url": "<?= htmlspecialchars($base_url) ?>/pay.php?txn=TXN_ABC123_1704067200",
  "gateway": "razorpay",
  "amount": 499,
  "currency": "INR"
}</pre>
        </div>
      </div>
    </section>

    <section class="integration-step">
      <div class="step-number">04</div>
      <div class="step-content">
        <h2>Verify webhook updates</h2>
        <p>Your webhook receives the authoritative server-to-server result. Verify <span class="mono">X-Webhook-Signature</span> with HMAC-SHA256 before updating the order.</p>
        <div class="code-wrap">
          <div class="code-label"><span>PHP · signature verification</span><button class="copy-btn" data-copy-target="webhookCode" onclick="copyCode(this)">Copy code</button></div>
          <pre class="code-block" id="webhookCode">&lt;?php
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
$expected = hash_hmac('sha256', $payload, getenv('PAYMENT_API_KEY'));

if (!hash_equals($expected, $signature)) {
    http_response_code(401);
    exit('Invalid signature');
}

$event = json_decode($payload, true);
// Use $event['order_id'] and $event['status'] idempotently.
http_response_code(200);</pre>
        </div>
      </div>
    </section>

    <section class="integration-step">
      <div class="step-number">05</div>
      <div class="step-content">
        <h2>Confirm status when needed</h2>
        <p>Poll by transaction ID as a fallback after the customer returns, or when a webhook is delayed. Trust a verified webhook or status response over query parameters.</p>
        <div class="endpoint-strip"><span class="method get">GET</span><code><?= htmlspecialchars($base_url) ?>/api/status.php?txn_id=TXN_ABC123</code><button class="copy-btn" data-copy-text="<?= htmlspecialchars($base_url) ?>/api/status.php?txn_id=TXN_ABC123" onclick="copyText(this)">Copy</button></div>
      </div>
    </section>
  </div>

  <aside class="help-aside">
    <section class="quick-card">
      <h3>Payment flow</h3>
      <p>The complete lifecycle at a glance.</p>
      <div class="flow-list">
        <div class="flow-item"><strong>Your server creates payment</strong>Fintrack returns a hosted URL.</div>
        <div class="flow-item"><strong>Customer completes checkout</strong>The selected gateway processes payment.</div>
        <div class="flow-item"><strong>Webhook confirms result</strong>Your server verifies and fulfills the order.</div>
        <div class="flow-item"><strong>Status API reconciles</strong>Use polling only as a fallback.</div>
      </div>
    </section>

    <section class="quick-card">
      <h3>Endpoint reference</h3>
      <p>One small API surface for every gateway.</p>
      <div class="endpoint-list">
        <div class="endpoint-mini"><strong><span class="method">POST</span>/api/initiate.php</strong><span>Create an orchestrated payment</span></div>
        <div class="endpoint-mini"><strong><span class="method get">GET</span>/api/status.php</strong><span>Retrieve the current payment state</span></div>
        <div class="endpoint-mini"><strong><span class="method">POST</span>Your webhook URL</strong><span>Receive signed lifecycle events</span></div>
      </div>
    </section>

    <section class="quick-card">
      <h3>Production checklist</h3>
      <p>Before sending live traffic.</p>
      <div class="check-list">
        <div class="check-item"><span class="check-icon">✓</span><span>Use HTTPS for return and webhook URLs.</span></div>
        <div class="check-item"><span class="check-icon">✓</span><span>Store the API key in server environment variables.</span></div>
        <div class="check-item"><span class="check-icon">✓</span><span>Verify webhook signatures before fulfillment.</span></div>
        <div class="check-item"><span class="check-icon">✓</span><span>Make webhook handling idempotent by transaction ID.</span></div>
        <div class="check-item"><span class="check-icon">✓</span><span>Persist both your order ID and Fintrack transaction ID.</span></div>
      </div>
    </section>
  </aside>
</div>
