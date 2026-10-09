<?php
  $top_candidate = $gateway_ranking[0] ?? null;
  $active_strategy = $routing_config['strategy'] ?? 'fastest';
  $is_active = (bool)($routing_config['enabled'] ?? true);
  $active_gateway_count = count($routing_config['active_gateways'] ?? []);
  $all_gateway_count = count(SmartRouter::ALL_GATEWAYS);
  $strategy_titles = [
    'fastest'         => 'Lowest Latency (Fastest)',
    'smart_composite' => 'Smart Composite Score',
    'success_rate'    => 'Highest Success Rate',
    'waterfall'       => 'Priority Waterfall',
  ];
  $chart_days = [];
  $chart_cursor = new DateTimeImmutable(date('Y-m-d', $latest_txn_timestamp ?: time()));
  while (count($chart_days) < 5) {
    $weekday = (int)$chart_cursor->format('N');
    if ($weekday <= 5) {
      $day_key = $chart_cursor->format('Y-m-d');
      $chart_days[$day_key] = ['label' => $chart_cursor->format('D'), 'paid' => 0, 'other' => 0];
    }
    $chart_cursor = $chart_cursor->modify('-1 day');
  }
  foreach (($txns ?? []) as $txn) {
    $day_key = date('Y-m-d', strtotime((string)($txn['created_at'] ?? 'now')));
    if (!isset($chart_days[$day_key])) continue;
    if (strtolower((string)($txn['status'] ?? 'pending')) === 'paid') $chart_days[$day_key]['paid']++;
    else $chart_days[$day_key]['other']++;
  }
  $chart_days = array_reverse($chart_days, true);
  $chart_max = max(1, ...array_values(array_map(fn($day) => max($day['paid'], $day['other']), $chart_days)));
  $chart_paid_total = array_sum(array_column($chart_days, 'paid'));
  $chart_other_total = array_sum(array_column($chart_days, 'other'));
  $leader_success = $top_candidate['success_rate'] ?? null;
  $leader_latency = $top_candidate['avg_latency_ms'] ?? null;
?>
<section class="routing-hero">
  <div class="routing-hero-content">
    <div class="routing-intro">
      <div class="eyebrow"><span class="eyebrow-dot <?= $is_active ? 'active' : 'off' ?>"></span>PAYMENT OPERATIONS <span class="eyebrow-separator">/</span> ROUTING</div>
      <h1>Smart gateway routing</h1>
      <p>Choose how payments are assigned and monitor gateway health in real time.</p>
    </div>

    <div class="routing-switch-box">
      <div class="switch-meta">
        <strong>Automatic Routing</strong>
        <span id="masterToggleStatusText"><?= $is_active ? 'Optimizing traffic dynamically' : 'Using explicit/manual gateway' ?></span>
      </div>
      <label class="toggle-switch">
        <input type="checkbox" id="masterRoutingToggle" <?= $is_active ? 'checked' : '' ?> onchange="quickToggleAutoRouting(this.checked)">
        <span class="toggle-slider"></span>
      </label>
    </div>
  </div>
</section>

<section class="routing-overview" aria-label="Routing overview">
  <article class="routing-metric">
    <div class="routing-metric-head"><span class="routing-metric-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 17 9 12l3 3 7-8"/><path d="M14 7h5v5"/></svg></span><span class="routing-metric-label">Routing mode</span></div>
    <strong><i class="routing-state-dot <?= $is_active ? 'on' : 'off' ?>"></i><?= $is_active ? 'Automatic' : 'Manual' ?></strong>
    <small><?= $is_active ? 'Traffic optimization enabled' : 'Using requested gateway' ?></small>
  </article>
  <article class="routing-metric">
    <div class="routing-metric-head"><span class="routing-metric-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 7h14M5 12h9M5 17h5"/><path d="m16 15 2 2 3-4"/></svg></span><span class="routing-metric-label">Active strategy</span></div>
    <strong><?= htmlspecialchars($strategy_titles[$active_strategy] ?? ucfirst($active_strategy)) ?></strong>
    <small>Applied to eligible payments</small>
  </article>
  <article class="routing-metric">
    <div class="routing-metric-head"><span class="routing-metric-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="4" width="7" height="7" rx="1.5"/><rect x="4" y="13" width="7" height="7" rx="1.5"/><rect x="13" y="13" width="7" height="7" rx="1.5"/></svg></span><span class="routing-metric-label">Gateway pool</span></div>
    <strong><?= $active_gateway_count ?><span class="routing-metric-total"> / <?= $all_gateway_count ?></span></strong>
    <small>Gateways available for routing</small>
  </article>
  <article class="routing-metric">
    <div class="routing-metric-head"><span class="routing-metric-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3 19 6v5c0 4.5-2.8 7.8-7 10-4.2-2.2-7-5.5-7-10V6l7-3Z"/><path d="m9 12 2 2 4-4"/></svg></span><span class="routing-metric-label">Circuit breakers</span></div>
    <strong class="<?= $tripped_circuits_count > 0 ? 'text-warning' : 'text-positive' ?>"><?= $tripped_circuits_count > 0 ? $tripped_circuits_count . ' isolated' : 'All healthy' ?></strong>
    <small><?= $tripped_circuits_count > 0 ? 'Gateway recovery in progress' : 'No gateways quarantined' ?></small>
  </article>
</section>

<div class="routing-layout">
  <!-- Left Column: Strategy & Pool Controls -->
  <div>
    <div class="card card-pad">
      <div class="card-head">
        <div>
          <div class="card-title">Routing strategy</div>
          <div class="sub">Select how traffic is distributed among available gateways.</div>
        </div>
        <span class="badge normal">Algorithm</span>
      </div>

      <div class="strategy-grid">
        <label class="strategy-option <?= $active_strategy === 'fastest' ? 'selected' : '' ?>" onclick="selectStrategyOption('fastest', this)">
          <div class="strategy-head"><span class="strategy-index">01</span><span>Lowest latency</span></div>
          <div class="strategy-desc">Directs each payment to whichever operational gateway currently has the lowest rolling API latency (ms).</div>
          <span class="strategy-badge">Recommended</span>
          <input type="radio" name="routingStrategy" value="fastest" <?= $active_strategy === 'fastest' ? 'checked' : '' ?>>
        </label>

        <label class="strategy-option <?= $active_strategy === 'smart_composite' ? 'selected' : '' ?>" onclick="selectStrategyOption('smart_composite', this)">
          <div class="strategy-head"><span class="strategy-index">02</span><span>Composite score</span></div>
          <div class="strategy-desc">Balances historical success rate (60%) with gateway latency (40%).</div>
          <span class="strategy-badge">Balanced</span>
          <input type="radio" name="routingStrategy" value="smart_composite" <?= $active_strategy === 'smart_composite' ? 'checked' : '' ?>>
        </label>

        <label class="strategy-option <?= $active_strategy === 'success_rate' ? 'selected' : '' ?>" onclick="selectStrategyOption('success_rate', this)">
          <div class="strategy-head"><span class="strategy-index">03</span><span>Highest success rate</span></div>
          <div class="strategy-desc">Favors gateways converting the highest % of resolved transactions. Uses latency as a tiebreaker.</div>
          <span class="strategy-badge">Conversion</span>
          <input type="radio" name="routingStrategy" value="success_rate" <?= $active_strategy === 'success_rate' ? 'checked' : '' ?>>
        </label>

        <label class="strategy-option <?= $active_strategy === 'waterfall' ? 'selected' : '' ?>" onclick="selectStrategyOption('waterfall', this)">
          <div class="strategy-head"><span class="strategy-index">04</span><span>Priority waterfall</span></div>
          <div class="strategy-desc">Enforces a strict priority sequence (Razorpay → Cashfree → PayU), bypassing any gateway under circuit trip.</div>
          <span class="strategy-badge">Sequential</span>
          <input type="radio" name="routingStrategy" value="waterfall" <?= $active_strategy === 'waterfall' ? 'checked' : '' ?>>
        </label>
      </div>
    </div>

    <div class="card card-pad" style="margin-top:14px">
      <div class="card-head">
        <div>
          <div class="card-title">Gateway allocation</div>
          <div class="sub">Enable or temporarily disable gateways from receiving routed traffic.</div>
        </div>
        <span class="badge watch">Active gateways</span>
      </div>

      <div class="pool-grid">
        <?php foreach (SmartRouter::ALL_GATEWAYS as $gw): ?>
          <?php $is_checked = in_array($gw, $routing_config['active_gateways'] ?? [], true); ?>
          <label class="pool-item">
            <input type="checkbox" class="gateway-pool-check" value="<?= htmlspecialchars($gw) ?>" <?= $is_checked ? 'checked' : '' ?>>
            <div class="gateway-logo <?= htmlspecialchars($gw) ?>" style="width:28px;height:28px;font-size:11px"><?= strtoupper(substr($gw, 0, 1)) ?></div>
            <div>
              <strong><?= htmlspecialchars(ucfirst($gw)) ?></strong>
              <div class="sub"><?= $is_checked ? 'In active pool' : 'Excluded' ?></div>
            </div>
          </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card card-pad" style="margin-top:14px">
      <div class="card-head">
        <div>
          <div class="card-title">Resilience & circuit breakers</div>
          <div class="sub">Automatically isolates failing gateways to protect checkout conversion.</div>
        </div>
        <span class="badge high">Zero-downtime</span>
      </div>

      <div class="param-grid">
        <div class="field">
          <label>Consecutive error trip threshold</label>
          <input type="number" id="cbFailureThreshold" min="1" max="10" value="<?= (int)($routing_config['circuit_breaker']['failure_threshold'] ?? 3) ?>" placeholder="3">
          <span class="sub" style="margin-top:4px;display:block">Errors in a row before gateway is quarantined.</span>
        </div>

        <div class="field">
          <label>Quarantine cooldown (seconds)</label>
          <input type="number" id="cbCooldownSeconds" min="10" max="3600" step="10" value="<?= (int)($routing_config['circuit_breaker']['cooldown_seconds'] ?? 300) ?>" placeholder="300">
          <span class="sub" style="margin-top:4px;display:block">Duration (e.g. 300s = 5m) before probing recovery.</span>
        </div>
      </div>

      <div style="display:grid;gap:10px;margin-top:14px;padding-top:14px;border-top:1px solid #23282f">
        <label style="display:flex;align-items:flex-start;gap:10px;font-size:11px;color:#dce2e9;cursor:pointer">
          <input type="checkbox" id="cbAutoCascade" <?= !empty($routing_config['auto_cascade']) ? 'checked' : '' ?> style="margin-top:2px;accent-color:#3be3ef">
          <div>
            <strong>Enable Auto-Cascade Failover (Zero-Downtime)</strong>
            <span class="sub" style="margin-top:2px;display:block">If the primary chosen gateway fails during order creation, automatically cascade to the next best candidate without failing the checkout.</span>
          </div>
        </label>

        <label style="display:flex;align-items:flex-start;gap:10px;font-size:11px;color:#dce2e9;cursor:pointer">
          <input type="checkbox" id="cbOverrideExplicit" <?= !empty($routing_config['override_explicit']) ? 'checked' : '' ?> style="margin-top:2px;accent-color:#3be3ef">
          <div>
            <strong>Global Override on Specific Gateway Requests</strong>
            <span class="sub" style="margin-top:2px;display:block">When checked, Smart Router optimizes all initiation requests even if the API caller requested a specific gateway name.</span>
          </div>
        </label>
      </div>

      <div style="display:flex;gap:10px;margin-top:18px;flex-wrap:wrap">
        <button class="primary-btn" style="height:38px;padding:0 18px;font-size:11px" onclick="saveRoutingSettings()">Save Routing Settings</button>
        <button class="secondary-btn" style="height:38px;padding:0 14px;font-size:11px" onclick="resetCircuitBreakers()">Reset Circuit Breakers</button>
      </div>
    </div>
  </div>

  <!-- Right Column: Live Leaderboard & Simulator -->
  <div>
    <div class="card card-pad">
      <div class="routing-chart-head">
        <div class="routing-chart-title"><span aria-hidden="true">⠿</span><div><div class="card-title">Gateway Activity</div><div class="sub">Recorded payment outcomes by weekday</div></div></div>
        <span class="routing-chart-period">Weekdays <span aria-hidden="true">⌄</span></span>
      </div>
      <div class="routing-chart" role="img" aria-label="Grouped bar chart of paid and pending or failed transactions for the last five weekdays">
        <div class="routing-chart-guide"></div>
        <div class="routing-chart-groups">
          <?php foreach ($chart_days as $day): ?>
            <?php
              $paid_height = $day['paid'] ? max(9, ($day['paid'] / $chart_max) * 100) : 3;
              $other_height = $day['other'] ? max(9, ($day['other'] / $chart_max) * 100) : 3;
            ?>
            <div class="routing-chart-group" title="<?= htmlspecialchars($day['label']) ?>: <?= (int)$day['paid'] ?> paid, <?= (int)$day['other'] ?> pending or failed">
              <div class="routing-chart-pair">
                <i class="paid" style="height:<?= number_format($paid_height, 1, '.', '') ?>%"></i>
                <i class="other" style="height:<?= number_format($other_height, 1, '.', '') ?>%"></i>
              </div>
              <span><?= htmlspecialchars($day['label']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="routing-chart-legend"><span><i class="paid"></i>Paid</span><span><i class="other"></i>Pending / failed</span></div>
      <div class="routing-chart-insight">
        <span class="routing-chart-insight-icon" aria-hidden="true">✳</span>
        <p><?php if ($top_candidate): ?><strong><?= htmlspecialchars(ucfirst((string)$top_candidate['gateway'])) ?></strong> leads the live ranking<?php if ($leader_success !== null): ?> with a <?= number_format((float)$leader_success, 1) ?>% success rate<?php endif; ?><?php if ($leader_latency !== null): ?> at <?= number_format((float)$leader_latency, 0) ?> ms average latency<?php endif; ?>.<?php else: ?>No gateway activity has been recorded for this period yet.<?php endif; ?> <span><?= number_format($chart_paid_total) ?> paid · <?= number_format($chart_other_total) ?> pending or failed</span></p>
      </div>
    </div>

    <div class="card card-pad" style="margin-top:14px">
      <div class="card-head">
        <div>
          <div class="card-title">Live Route Simulator</div>
          <div class="sub">Test how the algorithm will route a payment right now.</div>
        </div>
        <span class="badge normal">Sandbox</span>
      </div>

      <div class="simulator-form">
        <div class="field">
          <label>Requested gateway</label>
          <select id="simGatewaySelect">
            <option value="auto">auto (Smart Router decides)</option>
            <option value="razorpay">razorpay</option>
            <option value="cashfree">cashfree</option>
            <option value="payu">payu</option>
          </select>
        </div>

        <div class="field">
          <label>Amount (INR)</label>
          <input type="number" id="simAmount" value="500" placeholder="500">
        </div>

        <button class="pill-btn primary" style="width:100%;height:36px;justify-content:center" onclick="runRouteSimulation()">Run Route Simulation</button>
      </div>

      <div id="simOutput" class="sim-output"></div>
    </div>
  </div>
</div>
