<div class="alert">
  <span>Spending alert: <?= count($risky_ips) ?> IP addresses need risk review, including <?= count($chargeback_txns) ?> chargeback-linked payments.</span>
  <a href="?section=risk">Review risk activity <span aria-hidden="true">→</span></a>
</div>

<section class="ops-dashboard" aria-label="Payment operations overview">
  <?php
    $hourly_activity = array_fill(0, 24, ['paid' => 0.0, 'pending' => 0.0, 'failed' => 0.0]);
    foreach ($txns as $txn) {
      $timestamp = strtotime((string)($txn['created_at'] ?? ''));
      if ($timestamp === false) continue;
      $hour = (int)date('G', $timestamp);
      $status = strtolower((string)($txn['status'] ?? 'pending'));
      $bucket = $status === 'paid' ? 'paid' : (in_array($status, ['pending', 'gateway_paid_pending_notify'], true) ? 'pending' : 'failed');
      $hourly_activity[$hour][$bucket] += (float)($txn['amount'] ?? 0);
    }
    $hourly_max = max(1, ...array_map(fn($h) => array_sum($h), $hourly_activity));
    $hourly_ticks = max(1, ceil($hourly_max / 4));
    $hourly_axis_max = $hourly_ticks * 4;
    $pending_amount = array_sum(array_map(fn($t) => (float)($t['amount'] ?? 0), $pending_txns));
    $chart_total = $total_revenue + $failed_amount + $pending_amount;
  ?>
  <div class="ops-overview">
    <div class="ops-kpis">
      <article class="ops-kpi"><div class="ops-kpi-label"><i class="kpi-dot blue"></i>Total balance</div><div class="ops-kpi-value"><?= money_inr($total_revenue) ?></div><div class="ops-kpi-foot"><?= number_format(count($paid_txns)) ?> successful payments</div></article>
      <article class="ops-kpi"><div class="ops-kpi-label"><i class="kpi-dot green"></i>Total attempts</div><div class="ops-kpi-value"><?= number_format($total_txns) ?></div><div class="ops-kpi-foot"><?= number_format(count($pending_txns)) ?> pending</div></article>
      <article class="ops-kpi"><div class="ops-kpi-label"><i class="kpi-dot orange"></i>Success rate</div><div class="ops-kpi-value"><?= $total_txns ? number_format(count($paid_txns) / $total_txns * 100, 1) : '0.0' ?><span class="ops-kpi-unit">%</span></div><div class="ops-kpi-foot">Based on recorded attempts</div></article>
      <article class="ops-kpi"><div class="ops-kpi-label"><i class="kpi-dot red"></i>Failed payments</div><div class="ops-kpi-value"><?= number_format(count($failed_txns)) ?></div><div class="ops-kpi-foot"><?= money_inr($failed_amount) ?> declined</div></article>
    </div>

    <article class="ops-chart-panel">
      <header class="ops-panel-head"><div><h2>Transaction volume</h2><p>Amount by hour · all recorded transactions</p></div><button class="panel-menu" type="button" aria-label="Chart options">···</button></header>
      <div class="hourly-chart">
        <div class="hourly-y-axis"><span><?= money_inr($hourly_axis_max) ?></span><span><?= money_inr($hourly_ticks * 3) ?></span><span><?= money_inr($hourly_ticks * 2) ?></span><span><?= money_inr($hourly_ticks) ?></span><span>Rs 0</span></div>
        <div class="hourly-plot">
          <div class="hourly-grid"><i></i><i></i><i></i><i></i><i></i></div>
          <div class="hourly-bars">
            <?php foreach ($hourly_activity as $hour => $parts): $total = array_sum($parts); ?>
              <div class="hourly-slot" tabindex="0" aria-label="<?= sprintf('%02d:00', $hour) ?>, paid <?= money_inr($parts['paid']) ?>, pending <?= money_inr($parts['pending']) ?>, failed <?= money_inr($parts['failed']) ?>">
                <div class="hourly-tooltip"><strong><?= sprintf('%02d:00', $hour) ?></strong><span><i class="tip-paid"></i>Paid <b><?= money_inr($parts['paid']) ?></b></span><span><i class="tip-pending"></i>Pending <b><?= money_inr($parts['pending']) ?></b></span><span><i class="tip-failed"></i>Failed <b><?= money_inr($parts['failed']) ?></b></span></div>
                <div class="hourly-stack">
                  <i class="hour-paid" style="height:<?= $parts['paid'] / $hourly_axis_max * 100 ?>%"></i>
                  <i class="hour-pending" style="height:<?= $parts['pending'] / $hourly_axis_max * 100 ?>%"></i>
                  <i class="hour-failed" style="height:<?= $parts['failed'] / $hourly_axis_max * 100 ?>%"></i>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="hourly-x-axis"><?php for ($hour = 0; $hour < 24; $hour += 3): ?><span><?= sprintf('%02d:00', $hour) ?></span><?php endfor; ?></div>
        </div>
      </div>
      <footer class="ops-chart-legend"><span class="chart-total">Total <b><?= money_inr($chart_total) ?></b></span><span><i class="legend-blue"></i>Paid</span><span><i class="legend-pending"></i>Pending</span><span><i class="legend-purple"></i>Failed</span><span class="legend-more">···</span></footer>
    </article>
  </div>

  <div class="ops-feed-head"><div><h2>Transaction feed</h2><span class="feed-count"><?= number_format($total_txns) ?> total</span></div><span class="feed-status"><i></i>Live data</span></div>
  <?php require __DIR__ . '/transactions.php'; ?>

  <div class="ops-summary-row">
    <a class="ops-summary-card" href="?section=risk"><span>Top IP risk score</span><strong><?= $top_ip ? (int)$top_ip['score'] : 0 ?></strong><small><?= htmlspecialchars($top_ip['ip'] ?? 'No IP activity') ?> · Review risk activity →</small></a>
    <a class="ops-summary-card" href="?section=risk"><span>Chargeback tracking</span><strong><?= number_format(count($chargeback_txns)) ?></strong><small><?= count(array_filter($ip_stats, fn($s) => $s['chargebacks'] > 0)) ?> linked IP addresses · <?= money_inr($chargeback_amount) ?> disputed</small></a>
    <a class="ops-summary-card" href="?section=gateway"><span>Gateway revenue</span><strong><?= money_inr($total_revenue) ?></strong><small><?= count($gateway_totals) ?> configured payment gateways · View analytics →</small></a>
  </div>
</section>
