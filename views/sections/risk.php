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
      <div class="table-tools">
        <input class="search" id="riskSearch" type="text" placeholder="⌕  Search IP / reason..." oninput="filterRiskTable()" aria-label="Search risk activity">
        <button class="table-tool-btn risk-filter-toggle" type="button" onclick="toggleRiskFilters()" aria-expanded="false" aria-controls="riskFilters" title="Show filters" aria-label="Show risk filters"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h9M17 7h3M4 17h3M11 17h9"/><circle cx="15" cy="7" r="2"/><circle cx="9" cy="17" r="2"/></svg></button>
        <button class="table-tool-btn export-btn" type="button" onclick="exportVisibleRiskRows()"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v11M8 10l4 4 4-4"/><path d="M5 14v5h14v-5"/></svg>Export Data</button>
        <button class="table-tool-btn" type="button" onclick="location.reload()" title="Refresh risk data" aria-label="Refresh risk data">↻</button>
      </div>
    </div>
    <div class="table-filters risk-filters" id="riskFilters" hidden>
      <label>Risk level <select id="riskLevelFilter" onchange="filterRiskTable()"><option value="">All levels</option><option value="critical">Critical</option><option value="high">High</option><option value="watch">Watch</option><option value="normal">Normal</option></select></label>
      <button class="clear-filters" type="button" onclick="clearRiskFilters()">Clear filters</button>
    </div>
    <?php if (empty($ip_stats)): ?>
      <div class="empty">No IP activity recorded yet.</div>
    <?php else: ?>
    <?php $max_ip_attempts = max(1, ...array_map(fn($ip) => (int)$ip['total'], $ip_stats)); ?>
    <table id="riskTable">
      <thead><tr><th>IP Address</th><th>Score</th><th>Attempts</th><th>Failed</th><th>Chargebacks</th><th>Accounts</th><th>Last Seen</th><th>Reason</th></tr></thead>
      <tbody>
      <?php foreach ($ip_stats as $ip): ?>
        <tr>
          <td class="mono"><?= htmlspecialchars($ip['ip']) ?></td>
          <td><div class="risk-score-cell"><span class="badge <?= htmlspecialchars($ip['level']) ?>"><?= $ip['score'] ?> / <?= htmlspecialchars($ip['level']) ?></span><span class="risk-score-track"><i class="<?= htmlspecialchars($ip['level']) ?>" style="width:<?= min(100, (int)$ip['score']) ?>%"></i></span></div></td>
          <td><div class="attempts-cell"><span class="attempt-bars" aria-hidden="true"><?php foreach ([35, 52, 68, 84, 100] as $bar_height): ?><i style="height:<?= max(3, (int)($bar_height * min(1, $ip['total'] / $max_ip_attempts))) ?>%"></i><?php endforeach; ?></span><strong><?= number_format($ip['total']) ?></strong></div></td>
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
