<?php
$is_dashboard_view = ($section === 'dashboard');
$display_txns = $is_dashboard_view ? ($recent_txns ?? array_slice($txns ?? [], 0, 10)) : ($txns ?? []);
$refund_txns = array_values(array_filter($txns ?? [], fn($t) => strtolower((string)($t['status'] ?? '')) === 'refunded'));
$dispute_txns = array_values(array_filter($txns ?? [], fn($t) => in_array(strtolower((string)($t['status'] ?? '')), ['chargeback', 'disputed'], true)));
$refund_amount = array_sum(array_map(fn($t) => (float)($t['amount'] ?? 0), $refund_txns));
$dispute_amount = array_sum(array_map(fn($t) => (float)($t['amount'] ?? 0), $dispute_txns));
$status_counts = ['paid' => 0, 'pending' => 0, 'failed' => 0, 'review' => 0];
foreach (($txns ?? []) as $txn) {
  $status = strtolower((string)($txn['status'] ?? 'pending'));
  $bucket = in_array($status, ['refunded', 'chargeback', 'disputed'], true) ? 'review' : (isset($status_counts[$status]) ? $status : 'failed');
  $status_counts[$bucket]++;
}
$status_total = max(1, array_sum($status_counts));
$status_angles = [];
$angle = 0;
foreach ($status_counts as $key => $count) {
  $next_angle = $angle + ($count / $status_total * 360);
  $status_angles[] = "var(--txn-{$key}) {$angle}deg {$next_angle}deg";
  $angle = $next_angle;
}
$customer_count = count($seen_users ?? []);
?>
<?php if (!$is_dashboard_view): ?>
<section class="txn-insights" aria-label="Transaction insights">
  <article class="txn-insight-summary">
    <div class="txn-insight-values">
      <div class="txn-insight-value-card">
        <div class="txn-insight-label"><i class="refund-dot"></i>Refunds</div>
        <strong><?= money_inr($refund_amount) ?></strong>
        <span><?= number_format(count($refund_txns)) ?> refunded transactions</span>
      </div>
      <div class="txn-insight-value-card">
        <div class="txn-insight-label"><i class="chargeback-dot"></i>Chargebacks</div>
        <strong><?= money_inr($dispute_amount) ?></strong>
        <span><?= number_format(count($dispute_txns)) ?> disputed transactions</span>
      </div>
    </div>
    <div class="txn-status-visual">
      <div class="txn-status-donut" style="--txn-donut:conic-gradient(<?= implode(', ', $status_angles) ?>)">
        <div><strong><?= number_format(count($txns ?? [])) ?></strong><span>transactions</span></div>
      </div>
      <div class="txn-status-legend">
        <span><i class="paid-dot"></i>Paid <?= number_format(($status_counts['paid'] / $status_total) * 100) ?>%</span>
        <span><i class="pending-dot"></i>Pending <?= number_format(($status_counts['pending'] / $status_total) * 100) ?>%</span>
        <span><i class="failed-dot"></i>Failed <?= number_format(($status_counts['failed'] / $status_total) * 100) ?>%</span>
        <span><i class="chargeback-dot"></i>Review <?= number_format(($status_counts['review'] / $status_total) * 100) ?>%</span>
      </div>
    </div>
  </article>
  <article class="txn-customer-card">
    <div class="txn-customer-art" aria-hidden="true">
      <div class="txn-customer-art-top"><span>Customer mix</span><b><?= number_format($customer_count) ?></b><small>unique customers</small></div>
      <div class="txn-customer-bars"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div>
    </div>
    <div class="txn-customer-copy">
      <span class="txn-customer-tag">Customers</span>
      <h2>Customer insight</h2>
      <p>Track new and returning customers across your recorded transactions.</p>
      <a href="?section=reports" aria-label="Open customer reports">View reports <span aria-hidden="true">→</span></a>
    </div>
  </article>
</section>
<?php endif; ?>
<div class="card table-card">
  <div class="table-header">
    <div class="table-title"><?= $is_dashboard_view ? 'Transaction History' : 'All Transactions' ?></div>
    <div class="table-tools">
      <input class="search" id="txnSearch" type="text" placeholder="⌕  Search order / customer..." oninput="filterTable()" aria-label="Search transactions">
      <button class="table-tool-btn filter-toggle" type="button" onclick="toggleTxnFilters()" aria-expanded="false" aria-controls="txnFilters" title="Show filters" aria-label="Show transaction filters"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h9M17 7h3M4 17h3M11 17h9"/><circle cx="15" cy="7" r="2"/><circle cx="9" cy="17" r="2"/></svg></button>
      <button class="table-tool-btn export-btn" type="button" onclick="exportVisibleTransactions()"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v11M8 10l4 4 4-4"/><path d="M5 14v5h14v-5"/></svg>Export Data</button>
    </div>
  </div>
  <?php
    $table_statuses = array_values(array_unique(array_map(fn($t) => strtolower((string)($t['status'] ?? 'unknown')), $display_txns)));
    $table_gateways = array_values(array_unique(array_map(fn($t) => strtolower((string)($t['gateway'] ?? 'unknown')), $display_txns)));
    $table_max_amount = max(1, ...array_map(fn($t) => (float)($t['amount'] ?? 0), $display_txns));
    sort($table_statuses); sort($table_gateways);
  ?>
  <div class="table-filters" id="txnFilters" hidden>
    <label>Status <select id="txnStatusFilter" onchange="filterTable()"><option value="">All statuses</option><?php foreach ($table_statuses as $status): ?><option value="<?= htmlspecialchars($status) ?>"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $status))) ?></option><?php endforeach; ?></select></label>
    <label>Gateway <select id="txnGatewayFilter" onchange="filterTable()"><option value="">All gateways</option><?php foreach ($table_gateways as $gateway): ?><option value="<?= htmlspecialchars($gateway) ?>"><?= htmlspecialchars(ucfirst($gateway)) ?></option><?php endforeach; ?></select></label>
    <button class="clear-filters" type="button" onclick="clearTxnFilters()">Clear filters</button>
  </div>
  <?php if (empty($display_txns)): ?>
    <div class="empty">No transactions yet.</div>
  <?php else: ?>
  <table id="txnTable">
    <thead>
      <tr><th>Transaction ID</th><th>Description</th><th>Customer</th><th>Amount</th><th>Gateway</th><th>IP</th><th>Status</th><th>Date</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($display_txns as $t): ?>
      <tr onclick="viewTxn('<?= htmlspecialchars($t['txn_id']) ?>')">
        <td class="mono"><?= htmlspecialchars(substr($t['txn_id'] ?? '', 0, 18)) ?>...</td>
        <td><?= htmlspecialchars($t['description'] ?? $t['order_id'] ?? 'Payment') ?><span class="sub"><?= htmlspecialchars($t['order_id'] ?? '') ?></span></td>
        <td><?= htmlspecialchars($t['customer_name'] ?? '-') ?><span class="sub"><?= htmlspecialchars($t['customer_email'] ?? '-') ?></span></td>
        <td class="txn-amount-cell"><strong><?= money_inr((float)($t['amount'] ?? 0), 2) ?></strong><span class="txn-amount-track"><i class="<?= htmlspecialchars($t['status'] ?? 'pending') ?>" style="width:<?= min(100, (float)($t['amount'] ?? 0) / $table_max_amount * 100) ?>%"></i></span></td>
        <td><span class="badge <?= htmlspecialchars($t['gateway'] ?? '') ?>"><?= htmlspecialchars($t['gateway'] ?? '-') ?></span></td>
        <td class="mono"><?= htmlspecialchars(txn_ip($t)) ?></td>
        <td><span class="badge <?= htmlspecialchars($t['status'] ?? '') ?>"><?= htmlspecialchars($t['status'] ?? '-') ?></span></td>
        <td><span class="sub"><?= htmlspecialchars(format_app_datetime($t['created_at'] ?? null)) ?></span></td>
        <td><button class="action-btn" onclick="event.stopPropagation();viewTxn('<?= htmlspecialchars($t['txn_id']) ?>')">View</button></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
