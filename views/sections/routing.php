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
  <article class="routing-metric"><span class="routing-metric-label">Routing mode</span><strong><i class="routing-state-dot <?= $is_active ? 'on' : 'off' ?>"></i><?= $is_active ? 'Automatic' : 'Manual' ?></strong><small><?= $is_active ? 'Traffic optimization enabled' : 'Using requested gateway' ?></small></article>
  <article class="routing-metric"><span class="routing-metric-label">Active strategy</span><strong><?= htmlspecialchars($strategy_titles[$active_strategy] ?? ucfirst($active_strategy)) ?></strong><small>Applied to eligible payments</small></article>
  <article class="routing-metric"><span class="routing-metric-label">Gateway pool</span><strong><?= $active_gateway_count ?><span class="routing-metric-total"> / <?= $all_gateway_count ?></span></strong><small>Gateways available for routing</small></article>
  <article class="routing-metric"><span class="routing-metric-label">Circuit breakers</span><strong class="<?= $tripped_circuits_count > 0 ? 'text-warning' : 'text-positive' ?>"><?= $tripped_circuits_count > 0 ? $tripped_circuits_count . ' isolated' : 'All healthy' ?></strong><small><?= $tripped_circuits_count > 0 ? 'Gateway recovery in progress' : 'No gateways quarantined' ?></small></article>
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
      <div class="card-head">
        <div>
          <div class="card-title">Real-Time Gateway Leaderboard</div>
          <div class="sub">Current ranking based on live traffic & latency.</div>
        </div>
        <span class="badge <?= $is_active ? 'paid' : 'watch' ?>"><?= $is_active ? 'Live ranking' : 'Preview' ?></span>
      </div>

      <div class="leaderboard-list" id="leaderboardContainer">
        <?php foreach ($gateway_ranking as $idx => $r): ?>
          <?php
            $gw = $r['gateway'];
            $m = $gateway_metrics[$gw] ?? [];
            $is_tripped = $r['circuit_status'] === 'tripped';
            $is_degraded = $r['circuit_status'] === 'degraded';
          ?>
          <div class="leaderboard-row" style="<?= $is_tripped ? 'border-color:#5b252c;background:#181214' : '' ?>">
            <div class="leaderboard-top">
              <div class="leaderboard-ident">
                <span class="rank-num r<?= $idx + 1 ?>">#<?= $idx + 1 ?></span>
                <div class="gateway-logo <?= htmlspecialchars($gw) ?>" style="width:28px;height:28px;font-size:11px"><?= strtoupper(substr($gw, 0, 1)) ?></div>
                <div>
                  <strong style="font-size:12px;text-transform:capitalize"><?= htmlspecialchars($gw) ?></strong>
                  <span class="sub">Score: <?= is_numeric($r['score']) ? number_format((float)$r['score'], 1) : $r['score'] ?></span>
                </div>
              </div>

              <div>
                <?php if ($is_tripped): ?>
                  <span class="badge high">Quarantined (<?= $m['cooldown_remaining'] ?? 0 ?>s)</span>
                <?php elseif ($is_degraded): ?>
                  <span class="badge watch">Monitoring</span>
                <?php else: ?>
                  <span class="badge paid">Healthy</span>
                <?php endif; ?>
              </div>
            </div>

            <div class="leaderboard-bars">
              <div>
                <div>Response Speed: <strong style="color:#fff"><?= number_format((float)($r['avg_latency_ms'] ?? 250), 0) ?> ms</strong></div>
                <div class="mini-bar-track">
                  <div class="mini-bar-fill latency" style="width:<?= max(10, min(100, 100 - (($r['avg_latency_ms'] ?? 250) / 10))) ?>%"></div>
                </div>
              </div>
              <div>
                <div>Success Rate: <strong style="color:#fff"><?= $r['success_rate'] !== null ? number_format((float)$r['success_rate'], 1) . '%' : '—' ?></strong></div>
                <div class="mini-bar-track">
                  <div class="mini-bar-fill" style="width:<?= number_format((float)($r['success_rate'] ?? 95), 1, '.', '') ?>%"></div>
                </div>
              </div>
            </div>

            <div class="leaderboard-reason">
              <?= htmlspecialchars($r['reason'] ?? 'Active route candidate') ?>
            </div>
          </div>
        <?php endforeach; ?>
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
