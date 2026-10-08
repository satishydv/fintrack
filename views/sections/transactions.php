<?php
$is_dashboard_view = ($section === 'dashboard');
$display_txns = $is_dashboard_view ? ($recent_txns ?? array_slice($txns ?? [], 0, 10)) : ($txns ?? []);
?>
<div class="card table-card">
  <div class="table-header">
    <div class="table-title"><?= $is_dashboard_view ? 'Transaction History' : 'All Transactions' ?></div>
    <input class="search" type="text" placeholder="Search order / customer..." onkeyup="filterTable(this.value)">
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
        <td class="mono"><?= money_inr((float)($t['amount'] ?? 0), 2) ?></td>
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
