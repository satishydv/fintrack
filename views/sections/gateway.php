<section class="analytics-hero">
  <div class="analytics-hero-content">
    <div>
      <div class="eyebrow"><span class="eyebrow-dot"></span>Gateway command center</div>
      <h1>Every payment rail, one clear signal.</h1>
      <p>Track gateway reliability, payment completion time, traffic, and failure patterns from your live transaction history.</p>
    </div>
    <div class="health-summary">
      <div class="health-summary-top"><span>Operational health</span><span><?= $active_gateway_count ?> active</span></div>
      <div class="health-orbit"><strong><?= $operational_gateway_count ?>/<?= max(1, $active_gateway_count) ?></strong></div>
      <div class="health-summary-copy"><?= $operational_gateway_count === $active_gateway_count && $active_gateway_count > 0 ? 'All active gateways operational' : 'One or more rails need attention' ?></div>
    </div>
  </div>
</section>

<div class="metric-grid">
  <div class="metric-card" style="--metric-color:#55e394;--metric-glow:rgba(85,227,148,.14)">
    <div class="metric-head"><span>Resolved success rate</span><span class="metric-icon"><svg viewBox="0 0 24 24"><path d="m5 13 4 4L19 7"/></svg></span></div>
    <div class="metric-value"><?= number_format($overall_success_rate, 1) ?>%</div>
    <div class="metric-foot"><strong><?= number_format(count($paid_txns)) ?> successful</strong> of <?= number_format($overall_resolved) ?> resolved payments</div>
  </div>
  <div class="metric-card" style="--metric-color:#4ddfe8;--metric-glow:rgba(77,223,232,.14)">
    <div class="metric-head"><span><?= $overall_avg_gateway_latency !== null ? 'Gateway API latency' : 'Avg. completion time' ?></span><span class="metric-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"/><path d="M12 8v5l3 2"/></svg></span></div>
    <div class="metric-value"><?= $overall_avg_gateway_latency !== null ? number_format($overall_avg_gateway_latency, 0) . ' ms' : ($overall_avg_completion !== null ? htmlspecialchars(format_duration_short($overall_avg_completion)) : '—') ?></div>
    <div class="metric-foot"><?php if ($overall_avg_gateway_latency !== null): ?>p95 <strong><?= number_format($overall_p95_gateway_latency, 0) ?> ms</strong> · order API round-trip<?php else: ?>Historical completion proxy · <strong>API latency collecting on new payments</strong><?php endif; ?></div>
  </div>
  <div class="metric-card" style="--metric-color:#8c85ff;--metric-glow:rgba(140,133,255,.16)">
    <div class="metric-head"><span>Total traffic</span><span class="metric-icon"><svg viewBox="0 0 24 24"><path d="M4 17h4V9H4zM10 17h4V5h-4zM16 17h4v-6h-4z"/></svg></span></div>
    <div class="metric-value"><?= number_format($total_txns) ?></div>
    <div class="metric-foot"><strong><?= number_format(count($pending_txns)) ?> pending</strong> · <?= number_format($total_txns ? count($pending_txns) / $total_txns * 100 : 0, 1) ?>% of attempts</div>
  </div>
  <div class="metric-card" style="--metric-color:#ff9b64;--metric-glow:rgba(255,155,100,.15)">
    <div class="metric-head"><span>Processed volume</span><span class="metric-icon"><svg viewBox="0 0 24 24"><path d="M5 8h14M7 4h10l2 4v11H5V8z"/><path d="M9 13h6"/></svg></span></div>
    <div class="metric-value"><?= money_inr($total_revenue) ?></div>
    <div class="metric-foot">Across <strong><?= number_format(count($paid_txns)) ?> captured payments</strong></div>
  </div>
</div>

<div class="analytics-layout">
  <section class="card analytics-panel">
    <div class="panel-header">
      <div><h2>Gateway performance</h2><p>Health uses the success rate of resolved payments.</p></div>
      <span class="badge normal">All time</span>
    </div>
    <div class="gateway-performance">
      <?php foreach ($gateway_analytics as $gateway => $stats): ?>
        <div class="gateway-row">
          <div class="gateway-identity">
            <div class="gateway-logo <?= htmlspecialchars($gateway) ?>"><?= htmlspecialchars(strtoupper(substr($gateway, 0, 1))) ?></div>
            <div class="gateway-name">
              <strong><?= htmlspecialchars($gateway) ?></strong>
              <span><i class="health-dot <?= htmlspecialchars($stats['health_class']) ?>"></i><?= htmlspecialchars($stats['health']) ?></span>
            </div>
          </div>
          <div class="gateway-stat">
            <label>Success rate</label>
            <strong><?= $stats['success_rate'] !== null ? number_format($stats['success_rate'], 1) . '%' : '—' ?></strong>
            <div class="rate-track"><div class="rate-fill <?= htmlspecialchars($stats['health_class']) ?>" style="width:<?= number_format((float)($stats['success_rate'] ?? 0), 1, '.', '') ?>%"></div></div>
          </div>
          <div class="gateway-stat"><label>Attempts</label><strong><?= number_format($stats['attempts']) ?></strong></div>
          <div class="gateway-stat"><label><?= $stats['avg_api_latency'] !== null ? 'API latency' : 'Avg. completion' ?></label><strong><?= $stats['avg_api_latency'] !== null ? number_format($stats['avg_api_latency'], 0) . ' ms' : ($stats['avg_completion'] !== null ? htmlspecialchars(format_duration_short($stats['avg_completion'])) : '—') ?></strong></div>
          <div class="gateway-stat"><label>Paid volume</label><strong><?= money_inr((float)$stats['paid_amount']) ?></strong></div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="card analytics-panel">
    <div class="panel-header">
      <div><h2>7-day traffic</h2><p>Latest activity window</p></div>
      <div class="legend"><span><i style="background:#54e58e"></i>Paid</span><span><i style="background:#ff7474"></i>Failed</span><span><i style="background:#f0d45d"></i>Pending</span></div>
    </div>
    <div class="traffic-chart">
      <?php foreach ($traffic_days as $day): ?>
        <div class="traffic-day">
          <strong><?= number_format($day['total']) ?></strong>
          <div class="traffic-stack">
            <div class="traffic-segment pending" style="height:<?= number_format($day['pending'] / $max_daily_traffic * 100, 2, '.', '') ?>%"></div>
            <div class="traffic-segment failed" style="height:<?= number_format($day['failed'] / $max_daily_traffic * 100, 2, '.', '') ?>%"></div>
            <div class="traffic-segment paid" style="height:<?= number_format($day['paid'] / $max_daily_traffic * 100, 2, '.', '') ?>%"></div>
          </div>
          <label><?= htmlspecialchars($day['label']) ?></label>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
</div>

<div class="analytics-layout">
  <section class="card analytics-panel">
    <div class="panel-header"><div><h2>Failure radar</h2><p>Most common decline signals from gateway responses</p></div><span class="badge high"><?= number_format($failure_event_count) ?> events</span></div>
    <div class="signal-list">
      <?php if (empty($top_failure_reasons)): ?>
        <div class="empty">No gateway failures recorded.</div>
      <?php else: ?>
        <?php foreach ($top_failure_reasons as $reason => $count): ?>
          <div class="signal-item">
            <div class="signal-top"><span title="<?= htmlspecialchars($reason) ?>"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $reason))) ?></span><strong><?= number_format($count) ?></strong></div>
            <div class="signal-track"><div class="signal-fill" style="width:<?= number_format($count / $max_failure_reason_count * 100, 1, '.', '') ?>%"></div></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </section>

  <section class="card analytics-panel">
    <div class="panel-header"><div><h2>Traffic allocation</h2><p>Share of all attempts by gateway</p></div></div>
    <div class="signal-list">
      <?php foreach ($gateway_analytics as $gateway => $stats): ?>
        <div class="signal-item">
          <div class="signal-top"><span style="text-transform:capitalize"><?= htmlspecialchars($gateway) ?></span><strong><?= number_format($stats['traffic_share'], 1) ?>%</strong></div>
          <div class="signal-track"><div class="signal-fill" style="width:<?= number_format($stats['traffic_share'], 1, '.', '') ?>%;background:linear-gradient(90deg,#4cded7,#7975ff)"></div></div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
</div>

<div class="ops-grid">
  <div class="ops-card">
    <div class="ops-card-head"><h3>Pending queue</h3><span class="ops-tag">15m+</span></div>
    <div class="ops-value"><?= number_format($stale_pending) ?></div>
    <p>Payments still pending after 15 minutes. Oldest is <?= $oldest_pending_age ? htmlspecialchars(format_duration_short($oldest_pending_age)) : 'not available' ?> old.</p>
  </div>
  <div class="ops-card">
    <div class="ops-card-head"><h3>Suggested primary</h3><span class="ops-tag">Smart route</span></div>
    <div class="ops-value" style="text-transform:capitalize"><?= htmlspecialchars($best_gateway['gateway'] ?? 'No signal') ?></div>
    <p><?= $best_gateway ? number_format($best_gateway['success_rate'], 1) . '% resolved success makes it the strongest current route.' : 'Resolve more payments to unlock routing guidance.' ?></p>
  </div>
  <div class="ops-card action">
    <div class="ops-card-head"><h3>Integrate a gateway</h3><span class="ops-tag">5 steps</span></div>
    <p>Use the implementation guide for initiation, redirects, verified webhooks, and status polling.</p>
    <a class="ops-link" href="?section=help">Open integration guide →</a>
  </div>
</div>
