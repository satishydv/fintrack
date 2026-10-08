<div class="alert">
  <span>Spending alert: <?= count($risky_ips) ?> IP addresses need risk review, including <?= count($chargeback_txns) ?> chargeback-linked payments.</span>
  <a href="?section=risk">See details -></a>
</div>

<div class="dashboard-grid grid">
  <div>
    <div class="stat-grid">
      <div class="card card-pad">
        <div class="card-head"><div class="stat-label">Total Balance</div><div class="dots">...</div></div>
        <div class="stat-value"><?= money_inr($total_revenue) ?></div>
        <div class="stat-sub"><span><?= count($paid_txns) ?> successful payments</span><span class="delta good">Paid</span></div>
      </div>
      <div class="card card-pad">
        <div class="card-head"><div class="stat-label">Total Attempts</div><div class="dots">...</div></div>
        <div class="stat-value"><?= number_format($total_txns) ?></div>
        <div class="stat-sub"><span><?= count($pending_txns) ?> pending</span><span class="delta warn">Live</span></div>
      </div>
      <div class="card card-pad">
        <div class="card-head"><div class="stat-label">Failed Payments</div><div class="dots">...</div></div>
        <div class="stat-value"><?= number_format(count($failed_txns)) ?></div>
        <div class="stat-sub"><span><?= money_inr($failed_amount) ?> declined</span><span class="delta bad">Risk</span></div>
      </div>
    </div>

    <div class="card table-card">
      <div class="table-header">
        <div class="table-title">Cash Flow Overview</div>
        <div class="badge normal">Monthly</div>
      </div>
      <div class="chart">
        <div class="chart-lines"></div>
        <div class="bars">
          <span class="bar" style="height:<?= max(8, ($total_revenue / $max_amount) * 170) ?>px"></span>
          <span class="bar failed" style="height:<?= max(8, ($failed_amount / $max_amount) * 170) ?>px"></span>
          <span class="bar failed" style="height:<?= max(8, ($chargeback_amount / $max_amount) * 170) ?>px"></span>
          <span class="bar" style="height:<?= max(8, (count($paid_txns) / max(1, $total_txns)) * 170) ?>px"></span>
          <span class="bar failed" style="height:<?= max(8, (count($failed_txns) / max(1, $total_txns)) * 170) ?>px"></span>
          <span class="bar" style="height:<?= max(8, (count($pending_txns) / max(1, $total_txns)) * 170) ?>px"></span>
        </div>
        <div class="months"><span>Revenue</span><span>Failed</span><span>Disputes</span><span>Paid</span><span>Declined</span><span>Pending</span></div>
      </div>
    </div>
  </div>

  <div class="side-stack">
    <div class="risk-meter">
      <div>
        <div class="meter-label">Top IP Risk Score</div>
        <div class="meter-big"><?= $top_ip ? $top_ip['score'] : 0 ?></div>
      </div>
      <div>
        <div class="meter-label"><?= htmlspecialchars($top_ip['ip'] ?? 'No IP activity') ?></div>
        <div class="spend-bars">
          <?php for ($i = 1; $i <= 24; $i++): ?>
            <span class="<?= $top_ip && $i <= ceil($top_ip['score'] / 5) ? 'on' : '' ?>"></span>
          <?php endfor; ?>
        </div>
      </div>
    </div>
    <div class="card card-pad">
      <div class="card-head"><div class="card-title">Chargeback Tracking</div><a class="badge high" href="?section=risk">Open</a></div>
      <div class="mini-row"><span>Chargeback / disputes</span><strong><?= count($chargeback_txns) ?></strong></div>
      <div class="mini-row"><span>Linked IP addresses</span><strong><?= count(array_filter($ip_stats, fn($s) => $s['chargebacks'] > 0)) ?></strong></div>
      <div class="mini-row"><span>Disputed amount</span><strong><?= money_inr($chargeback_amount) ?></strong></div>
    </div>
    <div class="card card-pad">
      <div class="card-title">Gateway Revenue</div>
      <?php foreach ($gateway_totals as $gateway => $stats): ?>
        <div class="mini-row"><span><?= htmlspecialchars(ucfirst($gateway)) ?></span><strong><?= money_inr((float)$stats['amount']) ?></strong></div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/transactions.php'; ?>
