<?php
$is_dashboard_view = ($section === 'dashboard');
$display_txns = $is_dashboard_view ? ($recent_txns ?? array_slice($txns ?? [], 0, 10)) : ($txns ?? []);
?>
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
