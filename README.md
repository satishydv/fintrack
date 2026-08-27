# 💳 Payment Gateway Aggregator
**Hosted at:** https://pay.yourdomain.com  
**Integrates with:** https://your-laravel-app.com (Laravel)  
**Supports:** Razorpay + Cashfree + PayU

---

## 📁 Project Structure

```
payment-gateway/
├── config.php               ← API keys & settings (keep secret)
├── index.php                ← Dashboard (password protected)
├── login.php                ← Dashboard login
├── pay.php                  ← Hosted payment page (shown to customer)
├── return.php               ← Post-payment redirect handler
├── webhook.php              ← Server-to-server webhooks
├── api/
│   ├── initiate.php         ← POST: your-laravel-app.com calls this to create payment
│   └── status.php           ← GET: your-laravel-app.com polls payment status
├── lib/
│   ├── helpers.php          ← Core utilities
│   ├── Razorpay.php         ← Razorpay API
│   └── Cashfree.php         ← Cashfree API v3
├── data/                    ← JSON transaction files (auto-created)
│   └── .htaccess            ← Blocks direct web access
└── .htaccess                ← Security + HTTPS redirect
```

---

## 🚀 Setup Steps

### 1. Upload to Server
Upload all files to `https://pay.yourdomain.com`

### 2. Set File Permissions
```bash
chmod 755 data/
chmod 644 data/.htaccess
```

### 3. Edit `config.php`
Change these values:
- `api_key` → Pick a strong shared secret (same key in your-laravel-app.com .env)
- `dashboard_password` → Your secure dashboard password

### 4. Register Webhooks in Gateways

**Razorpay Dashboard:**
- Go to Settings → Webhooks
- Add URL: `https://pay.yourdomain.com/webhook.php?gateway=razorpay`
- Events to subscribe: `payment.captured`, `payment.failed`, `payment.authorized`

**Cashfree Dashboard:**
- Go to Developers → Webhooks
- Add URL: `https://pay.yourdomain.com/webhook.php?gateway=cashfree`
- Events: `PAYMENT_SUCCESS`, `PAYMENT_FAILED`

### 5. Configure your-laravel-app.com (Laravel)
In your `.env`:
```env
PAYMENT_API_KEY=VARA567_CHANGE_THIS_SECRET_KEY_2024
PAYMENT_BASE_URL=https://pay.yourdomain.com
```

---

## 🔌 Laravel Integration (your-laravel-app.com)

### Step 1 — Initiate Payment

```php
// In your Laravel controller
$response = Http::withHeaders([
    'X-Api-Key'    => env('PAYMENT_API_KEY'),
    'Content-Type' => 'application/json',
])->post(env('PAYMENT_BASE_URL') . '/api/initiate.php', [
    'order_id'       => $order->id,
    'amount'         => $order->total,          // e.g. 499.00
    'currency'       => 'INR',
    'customer_name'  => $user->name,
    'customer_email' => $user->email,
    'customer_phone' => $user->phone,
    'gateway'        => 'razorpay',             // or 'cashfree' or 'payu'
    'return_url'     => route('payment.return'),
    'webhook_url'    => route('payment.webhook'),
    'description'    => 'Order #' . $order->id,
]);

$data = $response->json();

if ($data['success']) {
    // Store $data['txn_id'] in your orders table
    $order->update(['txn_id' => $data['txn_id']]);
    
    // Redirect customer to payment page
    return redirect($data['payment_url']);
}
```

### Step 2 — Handle Return (after payment)

```php
// Route: GET /payment/return
public function handleReturn(Request $request)
{
    $txn_id   = $request->get('txn_id');
    $status   = $request->get('status');   // 'paid' or 'failed'
    $order_id = $request->get('order_id');

    if ($status === 'paid') {
        // Update order status
        Order::where('id', $order_id)->update(['status' => 'paid']);
        return redirect()->route('order.success', $order_id);
    }

    return redirect()->route('order.failed', $order_id);
}
```

### Step 3 — Handle Webhook (server callback)

```php
// Route: POST /payment/webhook
public function handleWebhook(Request $request)
{
    // Verify signature
    $signature = $request->header('X-Webhook-Signature');
    $payload   = $request->getContent();
    $expected  = hash_hmac('sha256', $payload, env('PAYMENT_API_KEY'));

    if (!hash_equals($expected, $signature)) {
        return response()->json(['error' => 'Invalid signature'], 401);
    }

    $data   = $request->json()->all();
    $event  = $data['event'];   // 'payment.paid' or 'payment.failed'
    $status = $data['status'];  // 'paid' or 'failed'
    
    Order::where('id', $data['order_id'])->update([
        'payment_status' => $status,
        'gateway_txn_id' => $data['gateway_txn'],
        'paid_at'        => $data['paid_at'],
    ]);

    return response()->json(['ok' => true]);
}
```

### Step 4 — Check Status (optional polling)

```php
$status = Http::withHeaders([
    'X-Api-Key' => env('PAYMENT_API_KEY'),
])->get(env('PAYMENT_BASE_URL') . '/api/status.php', [
    'txn_id' => $txn_id,
])->json();

// $status['status'] → 'pending' | 'paid' | 'failed'
```

---

## 📊 Dashboard
- URL: `https://pay.yourdomain.com`
- Login with your `dashboard_password` from `config.php`
- Shows all transactions, revenue totals, gateway breakdown
- Click any transaction to view full details + raw gateway response

---

## 🔐 API Reference

### `POST /api/initiate.php`
**Headers:** `X-Api-Key: <secret>`, `Content-Type: application/json`

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| order_id | string | ✅ | Your order ID from your-laravel-app.com |
| amount | float | ✅ | Amount in INR |
| currency | string | | Default: INR |
| customer_name | string | ✅ | |
| customer_email | string | ✅ | |
| customer_phone | string | ✅ | 10-digit mobile |
| gateway | string | ✅ | `razorpay` or `cashfree` |
| return_url | string | ✅ | Redirect after payment |
| webhook_url | string | ✅ | Server callback URL |
| description | string | | Payment description |

**Response:**
```json
{
  "success": true,
  "txn_id": "TXN_ABC123_1704067200",
  "payment_url": "https://pay.yourdomain.com/pay.php?txn=TXN_ABC123_1704067200",
  "gateway": "razorpay",
  "amount": 499.00,
  "currency": "INR"
}
```

### `GET /api/status.php?txn_id=TXN_XXX`
**Headers:** `X-Api-Key: <secret>`

**Response:**
```json
{
  "success": true,
  "txn_id": "TXN_ABC123",
  "order_id": "ORD_123",
  "status": "paid",
  "gateway": "razorpay",
  "amount": 499.00,
  "gateway_txn": "pay_XXXXXXX",
  "paid_at": "2024-01-01T12:00:00+05:30"
}
```

### Webhook Payload (sent to your-laravel-app.com)
```json
{
  "event": "payment.paid",
  "txn_id": "TXN_ABC123",
  "order_id": "ORD_123",
  "gateway": "razorpay",
  "amount": 499.00,
  "currency": "INR",
  "status": "paid",
  "gateway_txn": "pay_XXXXXXX",
  "paid_at": "2024-01-01T12:00:00+05:30",
  "customer": { "name": "...", "email": "...", "phone": "..." }
}
```
**Header:** `X-Webhook-Signature: hmac_sha256(payload, api_key)`

---

## ⚠️ Important Notes

1. **PHP Version:** Requires PHP 8.0+
2. **cURL not required** — uses native PHP streams
3. **No database** — all data stored as JSON in `/data/`
4. **Backup `/data/` regularly**
5. The `/data/` folder must be writable: `chmod 755 data/`
6. Keep `config.php` outside web root if possible, or ensure `.htaccess` blocks it

---

## 🔄 Payment Flow

```
your-laravel-app.com                    pay.yourdomain.com           Gateway
    │                                   │                           │
    │ POST /api/initiate.php            │                           │
    │ {order_id, amount, gateway...} ──►│                           │
    │                                   │── Create Order ──────────►│
    │◄── {success, payment_url} ────────│◄── {order_id} ────────────│
    │                                   │                           │
    │── Redirect user to payment_url ──►│                           │
    │                                   │── Checkout page ─────────►│
    │                                   │◄── User pays ─────────────│
    │                                   │                           │
    │                                   │◄── POST /return.php ──────│ (redirect back)
    │                                   │── Verify + save JSON      │
    │                                   │── POST /webhook to vara567│
    │◄── Redirect to return_url ────────│                           │
    │                                   │                           │
    │◄──────────────── POST /webhook.php (server webhook) ──────────│
```
