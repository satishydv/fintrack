<div class="report-hero">
  <div class="report-grid">
    <div class="report-token-card">
      <div class="report-token-label">New Users</div>
      <div class="report-token-value"><?= number_format($new_users) ?><span class="report-token-unit">USERS</span></div>
      <div class="report-token-sub">First-time customers in your transaction history.</div>
      <button class="report-token-btn" onclick="document.getElementById('reportUser').focus()">FILTER USERS</button>
    </div>

    <div class="report-token-card featured">
      <div class="report-token-label">Returning Users</div>
      <div class="report-token-value"><?= number_format($returning_users) ?><span class="report-token-unit">USERS</span></div>
      <div class="report-token-sub">Repeat activity by the same email, phone, or name.</div>
      <button class="report-token-btn" onclick="exportReportsCsv()">EXPORT TOKENS</button>
    </div>

    <div class="report-token-card">
      <div class="report-token-label">Paid Revenue</div>
      <div class="report-token-value"><?= money_inr($total_revenue) ?></div>
      <div class="report-token-sub">Rewards rise as successful payments grow.</div>
      <button class="report-token-btn" onclick="document.getElementById('reportStatus').value='paid';applyReportFilters()">VIEW PAID</button>
    </div>

    <div class="report-token-card">
      <div class="report-token-label">Total Reports</div>
      <div class="report-token-value"><?= number_format($total_txns) ?><span class="report-token-unit">ROWS</span></div>
      <div class="report-token-sub">All available transaction rows for filtering.</div>
      <button class="report-token-btn" onclick="clearReportFilters()">CLEAR FILTERS</button>
    </div>
  </div>
</div>

<div class="card card-pad report-filter-panel">
  <div class="card-head">
    <div>
      <div class="card-title">Transaction Filters</div>
      <div class="sub">Filter by date, gateway, amount, user, and status.</div>
    </div>
    <button class="pill-btn primary" onclick="exportReportsCsv()">Export CSV</button>
  </div>
  <div class="filter-grid">
    <div class="field"><label>From date</label><input type="date" id="reportFrom" oninput="applyReportFilters()"></div>
    <div class="field"><label>To date</label><input type="date" id="reportTo" oninput="applyReportFilters()"></div>
    <div class="field"><label>Gateway</label><select id="reportGateway" onchange="applyReportFilters()"><option value="">All gateways</option><option value="razorpay">Razorpay</option><option value="cashfree">Cashfree</option><option value="payu">PayU</option></select></div>
    <div class="field"><label>Minimum amount</label><input type="number" id="reportMinAmount" placeholder="0" min="0" step="1" oninput="applyReportFilters()"></div>
    <div class="field"><label>User</label><input type="text" id="reportUser" placeholder="Name, email, phone" oninput="applyReportFilters()"></div>
    <div class="field"><label>Status</label><select id="reportStatus" onchange="applyReportFilters()"><option value="">All statuses</option><option value="paid">Paid</option><option value="pending">Pending</option><option value="failed">Failed</option><option value="validation_failed">Validation failed</option><option value="notify_failed">Notify failed</option><option value="chargeback">Chargeback</option><option value="refunded">Refunded</option></select></div>
  </div>
  <div class="report-actions">
    <button class="pill-btn" onclick="clearReportFilters()">Clear filters</button>
    <span class="badge watch" id="reportCount"><?= number_format($total_txns) ?> rows</span>
  </div>
</div>

<div class="card table-card">
  <div class="table-header">
    <div class="table-title">Filtered Transactions</div>
  </div>
  <?php if (empty($txns)): ?>
    <div class="empty">No transactions yet.</div>
  <?php else: ?>
  <table id="reportTable">
    <thead>
      <tr><th>Transaction ID</th><th>User</th><th>Amount</th><th>Gateway</th><th>Status</th><th>Date</th><th>IP</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($txns as $t): ?>
      <tr onclick="viewTxn('<?= htmlspecialchars($t['txn_id']) ?>')">
        <td class="mono"><?= htmlspecialchars(substr($t['txn_id'] ?? '', 0, 18)) ?>...</td>
        <td><?= htmlspecialchars($t['customer_name'] ?? '-') ?><span class="sub"><?= htmlspecialchars($t['customer_email'] ?? '-') ?></span></td>
        <td class="mono"><?= money_inr((float)($t['amount'] ?? 0), 2) ?></td>
        <td><span class="badge <?= htmlspecialchars($t['gateway'] ?? '') ?>"><?= htmlspecialchars($t['gateway'] ?? '-') ?></span></td>
        <td><span class="badge <?= htmlspecialchars($t['status'] ?? '') ?>"><?= htmlspecialchars($t['status'] ?? '-') ?></span></td>
        <td><span class="sub"><?= htmlspecialchars(format_app_datetime($t['created_at'] ?? null)) ?></span></td>
        <td class="mono"><?= htmlspecialchars(txn_ip($t)) ?></td>
        <td><button class="action-btn" onclick="event.stopPropagation();viewTxn('<?= htmlspecialchars($t['txn_id']) ?>')">View</button></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
