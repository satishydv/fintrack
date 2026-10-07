<?php
/**
 * Smart Routing Engine for Payment Gateway Aggregator
 *
 * Capabilities:
 * - Dynamic gateway selection based on lowest latency, highest success rate, smart composite score, or waterfall priority
 * - Live circuit breaker to detect downtime and skip failing gateways
 * - Automatic real-time cascading / failover if order creation fails
 * - Persistent configuration in data/routing_config.json
 * - Runtime health & circuit breaker state in data/gateway_circuit_state.json
 */

require_once __DIR__ . '/helpers.php';

class SmartRouter {

    public const STRATEGY_FASTEST    = 'fastest';
    public const STRATEGY_SUCCESS    = 'success_rate';
    public const STRATEGY_COMPOSITE  = 'smart_composite';
    public const STRATEGY_WATERFALL  = 'waterfall';

    public const ALL_GATEWAYS = ['razorpay', 'cashfree', 'payu'];

    /**
     * Absolute path to the persistent routing config file.
     */
    public static function getConfigPath(): string {
        global $config;
        return ($config['data_dir'] ?? (__DIR__ . '/../data')) . '/routing_config.json';
    }

    /**
     * Absolute path to the circuit breaker runtime state file.
     */
    public static function getStatePath(): string {
        global $config;
        return ($config['data_dir'] ?? (__DIR__ . '/../data')) . '/gateway_circuit_state.json';
    }

    /**
     * Default routing configuration.
     */
    public static function getDefaultConfig(): array {
        return [
            'enabled'            => true,                   // Master switch: ON by default or toggleable
            'strategy'           => self::STRATEGY_FASTEST, // 'fastest', 'success_rate', 'smart_composite', 'waterfall'
            'active_gateways'    => self::ALL_GATEWAYS,     // Enabled gateways in routing pool
            'waterfall_priority' => self::ALL_GATEWAYS,     // Priority sequence for waterfall
            'override_explicit'  => false,                  // If true, auto-route even if client requests a specific gateway
            'auto_cascade'       => true,                   // Try next best gateway if creation fails
            'fallback_gateway'   => 'razorpay',             // Emergency fallback gateway
            'circuit_breaker'    => [
                'enabled'           => true,
                'failure_threshold' => 3,                   // Consecutive failures to trip circuit
                'cooldown_seconds'  => 300,                 // 5 minutes cooldown before recovery probe
            ],
            'updated_at'         => date('c'),
        ];
    }

    /**
     * Merge incoming configuration over base defaults without corrupting indexed arrays.
     */
    public static function mergeConfig(array $base, array $incoming): array {
        $result = $base;
        foreach ($incoming as $key => $val) {
            if ($key === 'active_gateways' || $key === 'waterfall_priority') {
                if (is_array($val)) {
                    $result[$key] = array_values($val);
                }
            } elseif ($key === 'circuit_breaker' && is_array($val) && is_array($result['circuit_breaker'] ?? null)) {
                $result['circuit_breaker'] = array_merge($result['circuit_breaker'], $val);
            } else {
                $result[$key] = $val;
            }
        }
        return $result;
    }

    /**
     * Load current configuration, merged with defaults.
     */
    public static function getConfig(): array {
        $path = self::getConfigPath();
        $defaults = self::getDefaultConfig();

        if (!file_exists($path)) {
            self::saveConfig($defaults);
            return $defaults;
        }

        $raw = @file_get_contents($path);
        $data = json_decode((string)$raw, true);

        if (!is_array($data)) {
            return $defaults;
        }

        return self::mergeConfig($defaults, $data);
    }

    /**
     * Save configuration to JSON.
     */
    public static function saveConfig(array $newConfig): bool {
        $path = self::getConfigPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $defaults = self::getDefaultConfig();
        $existing = [];
        if (file_exists($path)) {
            $raw = @file_get_contents($path);
            $parsed = json_decode((string)$raw, true);
            if (is_array($parsed)) {
                $existing = $parsed;
            }
        }

        $merged = self::mergeConfig($defaults, $existing);
        $merged = self::mergeConfig($merged, $newConfig);
        $merged['updated_at'] = date('c');

        return file_put_contents($path, json_encode($merged, JSON_PRETTY_PRINT)) !== false;
    }

    /**
     * Load circuit breaker state.
     */
    public static function getState(): array {
        $path = self::getStatePath();
        if (!file_exists($path)) {
            return [];
        }

        $data = json_decode((string)@file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    /**
     * Save circuit breaker state.
     */
    public static function saveState(array $state): bool {
        $path = self::getStatePath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        return file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT)) !== false;
    }

    /**
     * Record an API creation success for a gateway.
     * Clears any active circuit breaker trips and resets consecutive failures.
     */
    public static function recordSuccess(string $gateway, float $latencyMs): void {
        $state = self::getState();
        $now = time();

        $state[$gateway] = [
            'consecutive_failures' => 0,
            'is_tripped'           => false,
            'tripped_until'        => 0,
            'last_success_at'      => $now,
            'last_latency_ms'      => round($latencyMs, 2),
            'last_error'           => null,
        ];

        self::saveState($state);
    }

    /**
     * Record an API creation or processing failure for a gateway.
     * Increments consecutive failure count and trips the circuit breaker if threshold is reached.
     */
    public static function recordFailure(string $gateway, string $errorMessage, ?float $latencyMs = null): void {
        $config = self::getConfig();
        $threshold = (int)($config['circuit_breaker']['failure_threshold'] ?? 3);
        $cooldown = (int)($config['circuit_breaker']['cooldown_seconds'] ?? 300);
        $cbEnabled = (bool)($config['circuit_breaker']['enabled'] ?? true);

        $state = self::getState();
        $cur = $state[$gateway] ?? [];
        $failures = (int)($cur['consecutive_failures'] ?? 0) + 1;
        $now = time();

        $tripped = false;
        $trippedUntil = (int)($cur['tripped_until'] ?? 0);

        if ($cbEnabled && $failures >= $threshold) {
            $tripped = true;
            $trippedUntil = $now + $cooldown;
        }

        $state[$gateway] = [
            'consecutive_failures' => $failures,
            'is_tripped'           => $tripped,
            'tripped_until'        => $trippedUntil,
            'last_failure_at'      => $now,
            'last_latency_ms'      => $latencyMs !== null ? round($latencyMs, 2) : ($cur['last_latency_ms'] ?? null),
            'last_error'           => mb_substr($errorMessage, 0, 255),
        ];

        self::saveState($state);
    }

    /**
     * Manually reset circuit breaker trips for a specific gateway or all gateways.
     */
    public static function resetCircuitBreaker(?string $gateway = null): void {
        $state = self::getState();

        if ($gateway !== null) {
            unset($state[$gateway]);
        } else {
            $state = [];
        }

        self::saveState($state);
    }

    /**
     * Gather comprehensive performance metrics for all supported gateways.
     * Combines rolling transaction history and live circuit breaker state.
     */
    public static function getGatewayMetrics(): array {
        $config = self::getConfig();
        $state = self::getState();
        $txns = txn_list();
        $now = time();

        $metrics = [];
        foreach (self::ALL_GATEWAYS as $gw) {
            $metrics[$gw] = [
                'gateway'              => $gw,
                'attempts'             => 0,
                'paid'                 => 0,
                'failed'               => 0,
                'pending'              => 0,
                'latencies'            => [],
                'avg_latency_ms'       => null,
                'p95_latency_ms'       => null,
                'success_rate'         => null,
                'consecutive_failures' => 0,
                'circuit_status'       => 'healthy', // 'healthy', 'degraded', 'tripped'
                'cooldown_remaining'   => 0,
                'last_error'           => null,
                'last_activity'        => null,
            ];
        }

        // Analyze rolling transaction history (up to last 100 transactions)
        $sample = array_slice($txns, 0, 100);
        foreach ($sample as $t) {
            $gw = strtolower((string)($t['gateway'] ?? ''));
            if (!isset($metrics[$gw])) continue;

            $status = strtolower((string)($t['status'] ?? 'pending'));
            $metrics[$gw]['attempts']++;

            if ($status === 'paid') {
                $metrics[$gw]['paid']++;
            } elseif (in_array($status, ['failed', 'validation_failed', 'notify_failed'], true)) {
                $metrics[$gw]['failed']++;
            } else {
                $metrics[$gw]['pending']++;
            }

            if (isset($t['gateway_latency_ms']) && is_numeric($t['gateway_latency_ms']) && (float)$t['gateway_latency_ms'] > 0) {
                $metrics[$gw]['latencies'][] = (float)$t['gateway_latency_ms'];
            }

            if (!$metrics[$gw]['last_activity'] && !empty($t['created_at'])) {
                $metrics[$gw]['last_activity'] = $t['created_at'];
            }
        }

        // Apply circuit state and compute aggregations
        foreach ($metrics as $gw => &$m) {
            $resolved = $m['paid'] + $m['failed'];
            $m['success_rate'] = $resolved > 0 ? round(($m['paid'] / $resolved) * 100, 1) : null;

            if (!empty($m['latencies'])) {
                sort($m['latencies']);
                $cnt = count($m['latencies']);
                $m['avg_latency_ms'] = round(array_sum($m['latencies']) / $cnt, 1);
                $p95Index = max(0, (int)ceil($cnt * 0.95) - 1);
                $m['p95_latency_ms'] = round($m['latencies'][$p95Index], 1);
            } else {
                // Baseline default if no transactions exist yet
                $m['avg_latency_ms'] = 250.0;
                $m['p95_latency_ms'] = 350.0;
            }

            $gwState = $state[$gw] ?? [];
            $m['consecutive_failures'] = (int)($gwState['consecutive_failures'] ?? 0);
            $m['last_error'] = $gwState['last_error'] ?? null;

            $trippedUntil = (int)($gwState['tripped_until'] ?? 0);
            if ($trippedUntil > $now) {
                $m['circuit_status'] = 'tripped';
                $m['cooldown_remaining'] = $trippedUntil - $now;
            } elseif ($m['consecutive_failures'] >= 2) {
                $m['circuit_status'] = 'degraded';
            } elseif ($m['success_rate'] !== null && $m['success_rate'] < 70 && $m['attempts'] >= 3) {
                $m['circuit_status'] = 'degraded';
            } else {
                $m['circuit_status'] = 'healthy';
            }
        }
        unset($m);

        return $metrics;
    }

    /**
     * Rank candidate gateways according to configured strategy, filtering out inactive or tripped gateways.
     */
    public static function rankGateways(?string $strategy = null, ?array $activeGateways = null): array {
        $config = self::getConfig();
        $strategy = $strategy ?: ($config['strategy'] ?? self::STRATEGY_FASTEST);
        $activeGateways = $activeGateways ?: ($config['active_gateways'] ?? self::ALL_GATEWAYS);
        $waterfallPriority = $config['waterfall_priority'] ?? self::ALL_GATEWAYS;
        $cbEnabled = (bool)($config['circuit_breaker']['enabled'] ?? true);

        $metrics = self::getGatewayMetrics();

        // Filter for active gateways only
        $pool = [];
        foreach ($activeGateways as $gw) {
            $gw = strtolower(trim($gw));
            if (isset($metrics[$gw])) {
                $pool[$gw] = $metrics[$gw];
            }
        }

        // If no active gateways configured, fallback to all
        if (empty($pool)) {
            $pool = $metrics;
        }

        // Categorize into operational vs tripped
        $healthy = [];
        $tripped = [];

        foreach ($pool as $gw => $m) {
            if ($cbEnabled && $m['circuit_status'] === 'tripped') {
                $tripped[$gw] = $m;
            } else {
                $healthy[$gw] = $m;
            }
        }

        // If ALL active gateways are tripped (catastrophe), allow all to prevent total outage
        if (empty($healthy)) {
            $healthy = $tripped;
            $tripped = [];
        }

        // Score and rank healthy gateways
        $ranked = [];
        foreach ($healthy as $gw => $m) {
            $score = 0;
            $reason = '';
            $avgLat = (float)($m['avg_latency_ms'] ?? 250);
            $sr = $m['success_rate'] !== null ? (float)$m['success_rate'] : 95.0;

            if ($strategy === self::STRATEGY_FASTEST) {
                // Lower latency is better (invert into score)
                $score = max(0, 10000 - $avgLat);
                $reason = "Fastest API response ({$avgLat} ms)";
            } elseif ($strategy === self::STRATEGY_SUCCESS) {
                // Higher success rate is better, with latency as tiebreaker
                $score = ($sr * 100) + max(0, 100 - ($avgLat / 20));
                $reason = "Highest success rate ({$sr}%)";
            } elseif ($strategy === self::STRATEGY_COMPOSITE) {
                // 60% success rate + 40% speed
                $speedScore = max(0, min(100, 100 - ($avgLat / 15)));
                $composite = round(($sr * 0.60) + ($speedScore * 0.40), 1);
                $score = $composite;
                $reason = "Smart Composite score {$composite}/100 ({$avgLat}ms, {$sr}% SR)";
            } elseif ($strategy === self::STRATEGY_WATERFALL) {
                // Highest index in waterfall gets highest score
                $priorityIndex = array_search($gw, $waterfallPriority, true);
                $priorityScore = $priorityIndex !== false ? (count($waterfallPriority) - $priorityIndex) * 100 : 0;
                $score = $priorityScore + max(0, 50 - ($avgLat / 40));
                $reason = "Waterfall Priority #" . ($priorityIndex !== false ? ($priorityIndex + 1) : '?');
            }

            $ranked[] = [
                'gateway'        => $gw,
                'score'          => $score,
                'avg_latency_ms' => $avgLat,
                'success_rate'   => $m['success_rate'],
                'circuit_status' => $m['circuit_status'],
                'reason'         => $reason,
                'tripped'        => false,
            ];
        }

        // Sort descending by score
        usort($ranked, fn($a, $b) => $b['score'] <=> $a['score']);

        // Append tripped gateways as lowest-priority fallback candidates
        foreach ($tripped as $gw => $m) {
            $remain = $m['cooldown_remaining'];
            $ranked[] = [
                'gateway'        => $gw,
                'score'          => -1000 - $remain,
                'avg_latency_ms' => (float)($m['avg_latency_ms'] ?? 250),
                'success_rate'   => $m['success_rate'],
                'circuit_status' => 'tripped',
                'reason'         => "Circuit breaker tripped ({$m['consecutive_failures']} consecutive errors, {$remain}s cooldown remaining)",
                'tripped'        => true,
            ];
        }

        return $ranked;
    }

    /**
     * Resolve final routing decision for an incoming payment request.
     *
     * @param string|null $requestedGateway Gateway requested in API payload (e.g. 'razorpay', 'auto', or null)
     * @return array Routing decision structure
     */
    public static function resolveRouting(?string $requestedGateway = null): array {
        $config = self::getConfig();
        $isGlobalEnabled = (bool)($config['enabled'] ?? true);
        $strategy = $config['strategy'] ?? self::STRATEGY_FASTEST;
        $overrideExplicit = (bool)($config['override_explicit'] ?? false);
        $autoCascade = (bool)($config['auto_cascade'] ?? true);
        $fallback = $config['fallback_gateway'] ?? 'razorpay';

        $req = strtolower(trim((string)$requestedGateway));

        // Determine if auto-routing should execute
        $shouldAutoRoute = false;
        if ($isGlobalEnabled) {
            if ($req === '' || $req === 'auto' || $overrideExplicit) {
                $shouldAutoRoute = true;
            }
        } else {
            // Global auto routing is OFF, but client explicitly asked for 'auto'
            if ($req === 'auto') {
                $shouldAutoRoute = true;
            }
        }

        if ($shouldAutoRoute) {
            $ranked = self::rankGateways($strategy, $config['active_gateways'] ?? self::ALL_GATEWAYS);
            $candidateGateways = array_column($ranked, 'gateway');
            $primaryChoice = $candidateGateways[0] ?? $fallback;

            return [
                'auto_routed'      => true,
                'strategy'         => $strategy,
                'primary_gateway'  => $primaryChoice,
                'ranked_candidates'=> $ranked,
                'ranked_list'      => $candidateGateways,
                'auto_cascade'     => $autoCascade,
                'selection_reason' => $ranked[0]['reason'] ?? 'Optimal gateway selected by Smart Router',
            ];
        }

        // Direct explicit routing (auto-routing disabled or specific gateway requested)
        $chosen = in_array($req, self::ALL_GATEWAYS, true) ? $req : $fallback;

        // In manual mode, we can still provide waterfall failovers if auto_cascade is on
        $remaining = array_values(array_diff(self::ALL_GATEWAYS, [$chosen]));
        $cascadeList = array_merge([$chosen], $remaining);

        return [
            'auto_routed'      => false,
            'strategy'         => 'explicit',
            'primary_gateway'  => $chosen,
            'ranked_candidates'=> [
                [
                    'gateway'        => $chosen,
                    'score'          => 100,
                    'avg_latency_ms' => null,
                    'success_rate'   => null,
                    'circuit_status' => 'manual',
                    'reason'         => "Explicitly requested by merchant ({$chosen})",
                    'tripped'        => false,
                ]
            ],
            'ranked_list'      => $autoCascade ? $cascadeList : [$chosen],
            'auto_cascade'     => $autoCascade,
            'selection_reason' => "Direct assignment to {$chosen}",
        ];
    }
}
