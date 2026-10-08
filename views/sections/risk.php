<div class="risk-grid">
  <div class="card card-pad"><div class="stat-label">Risky IPs</div><div class="stat-value"><?= count($risky_ips) ?></div><div class="stat-sub"><span>Score 18+</span><span class="delta warn">Watch</span></div></div>
  <div class="card card-pad"><div class="stat-label">Chargebacks</div><div class="stat-value"><?= count($chargeback_txns) ?></div><div class="stat-sub"><span><?= money_inr($chargeback_amount) ?></span><span class="delta bad">Track</span></div></div>
  <div class="card card-pad"><div class="stat-label">Failed Attempts</div><div class="stat-value"><?= count($failed_txns) ?></div><div class="stat-sub"><span>Grouped by IP</span><span class="delta bad">Rules</span></div></div>
  <div class="card card-pad"><div class="stat-label">Known IPs</div><div class="stat-value"><?= count($ip_stats) ?></div><div class="stat-sub"><span>New payments only store IP</span><span class="delta good">Live</span></div></div>
</div>

<div class="risk-layout">
  <div class="card">
    <div class="table-header">
      <div class="table-title">IP Chargeback & Suspicious Activity</div>
      <button class="pill-btn" onclick="location.reload()">Refresh</button>
    </div>
    <?php if (empty($ip_stats)): ?>
      <div class="empty">No IP activity recorded yet.</div>
    <?php else: ?>
    <table id="txnTable">
      <thead><tr><th>IP Address</th><th>Score</th><th>Attempts</th><th>Failed</th><th>Chargebacks</th><th>Accounts</th><th>Last Seen</th><th>Reason</th></tr></thead>
      <tbody>
      <?php foreach ($ip_stats as $ip): ?>
        <tr>
          <td class="mono"><?= htmlspecialchars($ip['ip']) ?></td>
          <td><span class="badge <?= htmlspecialchars($ip['level']) ?>"><?= $ip['score'] ?> / <?= htmlspecialchars($ip['level']) ?></span></td>
          <td><?= number_format($ip['total']) ?></td>
          <td><?= number_format($ip['failed']) ?></td>
          <td><?= number_format($ip['chargebacks']) ?></td>
          <td><?= number_format($ip['accounts']) ?></td>
          <td><span class="sub"><?= htmlspecialchars(format_app_datetime($ip['last_seen'] ?? null)) ?></span></td>
          <td><?= htmlspecialchars(implode(', ', $ip['reasons'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <div class="card card-pad">
    <div class="card-title">Risk Rules</div>
    <div class="rule-list" style="margin-top:12px">
      <div class="rule"><strong>Same IP, many accounts</strong><p>Flags one IP when it appears across 3 or more customer identities.</p></div>
      <div class="rule"><strong>Repeated failed payments</strong><p>Raises risk when an IP has 3 or more failed, validation-failed, or notify-failed transactions.</p></div>
      <div class="rule"><strong>Chargeback-prone IP</strong><p>Groups chargeback, dispute, refund, and dispute-like gateway responses by IP address.</p></div>
      <div class="rule"><strong>High payment velocity</strong><p>Adds extra score when one IP creates 8 or more payment attempts.</p></div>
    </div>
  </div>
</div>
