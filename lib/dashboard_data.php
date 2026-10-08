<?php
/**
 * FinTrack Dashboard - Data Provider & Calculation Layer
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/SmartRouter.php';

global $config;

$section = $section ?? ($_GET['section'] ?? 'dashboard');
if (!in_array($section, ['dashboard', 'risk', 'transactions', 'gateway', 'routing', 'reports', 'help', 'components'], true)) {
    $section = 'dashboard';
}

$routing_config = SmartRouter::getConfig();
$gateway_metrics = SmartRouter::getGatewayMetrics();
$gateway_ranking = SmartRouter::rankGateways();
$tripped_circuits_count = count(array_filter($gateway_metrics, fn($m) => $m['circuit_status'] === 'tripped'));

$txns = txn_list();
$total_txns = count($txns);
$paid_txns = array_values(array_filter($txns, fn($t) => ($t['status'] ?? '') === 'paid'));
$pending_txns = array_values(array_filter($txns, fn($t) => ($t['status'] ?? '') === 'pending'));
$failed_txns = array_values(array_filter($txns, fn($t) => in_array(($t['status'] ?? ''), ['failed', 'validation_failed', 'notify_failed'], true)));
$chargeback_txns = array_values(array_filter($txns, function ($t) {
    $status = strtolower((string)($t['status'] ?? ''));
    $response = strtolower(json_encode($t['gateway_response'] ?? []));
    return in_array($status, ['chargeback', 'disputed', 'refunded'], true)
        || str_contains($response, 'chargeback')
        || str_contains($response, 'dispute');
}));
$total_revenue = array_sum(array_map(fn($t) => (float)($t['amount'] ?? 0), $paid_txns));
$failed_amount = array_sum(array_map(fn($t) => (float)($t['amount'] ?? 0), $failed_txns));
$chargeback_amount = array_sum(array_map(
    fn($t) => (float)($t['risk_details']['amount'] ?? $t['amount'] ?? 0),
    $chargeback_txns
));

if (!function_exists('money_inr')) {
    function money_inr(float $amount, int $decimals = 0): string {
        return 'Rs ' . number_format($amount, $decimals);
    }
}

if (!function_exists('txn_ip')) {
    function txn_ip(array $txn): string {
        foreach (['ip_address', 'client_ip', 'customer_ip', 'request_ip'] as $key) {
            if (!empty($txn[$key])) return (string)$txn[$key];
        }
        return 'unknown';
    }
}

if (!function_exists('risk_score')) {
    function risk_score(array $stats): int {
        $score = 0;
        $score += min(45, $stats['chargebacks'] * 25);
        $score += min(30, $stats['failed'] * 6);
        $score += min(15, max(0, $stats['accounts'] - 1) * 5);
        $score += min(10, max(0, $stats['total'] - 5) * 2);
        return min(100, $score);
    }
}

if (!function_exists('risk_level')) {
    function risk_level(int $score): string {
        if ($score >= 70) return 'critical';
        if ($score >= 40) return 'high';
        if ($score >= 18) return 'watch';
        return 'normal';
    }
}

$ip_stats = [];
foreach ($txns as $txn) {
    $ip = txn_ip($txn);
    if (!isset($ip_stats[$ip])) {
        $ip_stats[$ip] = [
            'ip' => $ip,
            'total' => 0,
            'paid' => 0,
            'failed' => 0,
            'pending' => 0,
            'chargebacks' => 0,
            'amount' => 0.0,
            'accounts_map' => [],
            'last_seen' => $txn['created_at'] ?? '',
            'reasons' => [],
        ];
    }

    $status = strtolower((string)($txn['status'] ?? ''));
    $ip_stats[$ip]['total']++;
    $ip_stats[$ip]['amount'] += (float)($txn['amount'] ?? 0);
    $ip_stats[$ip]['accounts_map'][$txn['customer_email'] ?? $txn['customer_phone'] ?? $txn['customer_name'] ?? 'unknown'] = true;
    if (strtotime($txn['created_at'] ?? '') > strtotime($ip_stats[$ip]['last_seen'] ?: '1970-01-01')) {
        $ip_stats[$ip]['last_seen'] = $txn['created_at'] ?? '';
    }
    if ($status === 'paid') $ip_stats[$ip]['paid']++;
    if ($status === 'pending') $ip_stats[$ip]['pending']++;
    if (in_array($status, ['failed', 'validation_failed', 'notify_failed'], true)) $ip_stats[$ip]['failed']++;
    if (in_array($txn, $chargeback_txns, true)) $ip_stats[$ip]['chargebacks']++;
}

foreach ($ip_stats as &$stats) {
    $stats['accounts'] = count($stats['accounts_map']);
    unset($stats['accounts_map']);
    if ($stats['chargebacks'] > 0) $stats['reasons'][] = $stats['chargebacks'] . ' chargeback/refund dispute';
    if ($stats['failed'] >= 3) $stats['reasons'][] = $stats['failed'] . ' failed attempts';
    if ($stats['accounts'] >= 3) $stats['reasons'][] = $stats['accounts'] . ' customer identities';
    if ($stats['total'] >= 8) $stats['reasons'][] = $stats['total'] . ' payment attempts';
    if (empty($stats['reasons'])) $stats['reasons'][] = 'Normal activity';
    $stats['score'] = risk_score($stats);
    $stats['level'] = risk_level($stats['score']);
}
unset($stats);

usort($ip_stats, fn($a, $b) => $b['score'] <=> $a['score'] ?: strtotime($b['last_seen']) <=> strtotime($a['last_seen']));
$risky_ips = array_values(array_filter($ip_stats, fn($s) => $s['score'] >= 18));
$top_ip = $ip_stats[0] ?? null;

$gateway_totals = [];
foreach ($paid_txns as $txn) {
    $gateway = strtolower((string)($txn['gateway'] ?? 'unknown'));
    $gateway_totals[$gateway]['count'] = ($gateway_totals[$gateway]['count'] ?? 0) + 1;
    $gateway_totals[$gateway]['amount'] = ($gateway_totals[$gateway]['amount'] ?? 0) + (float)($txn['amount'] ?? 0);
}

if (!function_exists('format_duration_short')) {
    function format_duration_short(float $seconds): string {
        if ($seconds < 60) return number_format($seconds, 1) . ' sec';
        if ($seconds < 3600) return number_format($seconds / 60, 1) . ' min';
        return number_format($seconds / 3600, 1) . ' hr';
    }
}

$supported_gateways = ['razorpay', 'cashfree', 'payu'];
$gateway_analytics = [];
foreach ($supported_gateways as $gateway) {
    $gateway_analytics[$gateway] = [
        'gateway' => $gateway,
        'attempts' => 0,
        'paid' => 0,
        'pending' => 0,
        'failed' => 0,
        'resolved' => 0,
        'paid_amount' => 0.0,
        'durations' => [],
        'api_latencies' => [],
        'last_activity' => null,
        'failure_reasons' => [],
    ];
}

$latest_txn_timestamp = 0;
foreach ($txns as $txn) {
    $gateway = strtolower((string)($txn['gateway'] ?? 'unknown'));
    if (!isset($gateway_analytics[$gateway])) {
        $gateway_analytics[$gateway] = [
            'gateway' => $gateway, 'attempts' => 0, 'paid' => 0, 'pending' => 0,
            'failed' => 0, 'resolved' => 0, 'paid_amount' => 0.0, 'durations' => [],
            'api_latencies' => [],
            'last_activity' => null, 'failure_reasons' => [],
        ];
    }

    $status = strtolower((string)($txn['status'] ?? 'pending'));
    $created_ts = strtotime((string)($txn['created_at'] ?? '')) ?: 0;
    $latest_txn_timestamp = max($latest_txn_timestamp, $created_ts);
    $stats =& $gateway_analytics[$gateway];
    $stats['attempts']++;
    if (isset($txn['gateway_latency_ms']) && is_numeric($txn['gateway_latency_ms']) && (float)$txn['gateway_latency_ms'] >= 0) {
        $stats['api_latencies'][] = (float)$txn['gateway_latency_ms'];
    }

    if ($created_ts > (strtotime((string)($stats['last_activity'] ?? '')) ?: 0)) {
        $stats['last_activity'] = $txn['created_at'] ?? null;
    }

    if ($status === 'paid') {
        $stats['paid']++;
        $stats['resolved']++;
        $stats['paid_amount'] += (float)($txn['amount'] ?? 0);
    } elseif ($status === 'pending' || $status === 'gateway_paid_pending_notify') {
        $stats['pending']++;
    } else {
        $stats['failed']++;
        $stats['resolved']++;

        $response = $txn['gateway_response'] ?? [];
        $reason = $response['error_reason']
            ?? $response['payload']['payment']['entity']['error_reason']
            ?? $response['error_description']
            ?? $response['payload']['payment']['entity']['error_description']
            ?? str_replace('_', ' ', $status);
        $reason = trim((string)$reason) ?: 'Unspecified gateway failure';
        $stats['failure_reasons'][$reason] = ($stats['failure_reasons'][$reason] ?? 0) + 1;
    }

    if ($status !== 'pending' && $status !== 'gateway_paid_pending_notify' && $created_ts > 0) {
        $completed_at = $status === 'paid' ? ($txn['paid_at'] ?? $txn['updated_at'] ?? null) : ($txn['updated_at'] ?? null);
        $completed_ts = strtotime((string)$completed_at) ?: 0;
        if ($completed_ts >= $created_ts) {
            $stats['durations'][] = $completed_ts - $created_ts;
        }
    }
    unset($stats);
}

$all_completion_durations = [];
$all_gateway_latencies = [];
$all_failure_reasons = [];
$active_gateway_count = 0;
$operational_gateway_count = 0;
foreach ($gateway_analytics as &$stats) {
    $stats['success_rate'] = $stats['resolved'] > 0 ? ($stats['paid'] / $stats['resolved']) * 100 : null;
    $stats['traffic_share'] = $total_txns > 0 ? ($stats['attempts'] / $total_txns) * 100 : 0;
    $stats['average_value'] = $stats['paid'] > 0 ? $stats['paid_amount'] / $stats['paid'] : 0;

    sort($stats['durations']);
    $duration_count = count($stats['durations']);
    $stats['avg_completion'] = $duration_count ? array_sum($stats['durations']) / $duration_count : null;
    $stats['p95_completion'] = $duration_count ? $stats['durations'][max(0, (int)ceil($duration_count * 0.95) - 1)] : null;
    $all_completion_durations = array_merge($all_completion_durations, $stats['durations']);
    sort($stats['api_latencies']);
    $latency_count = count($stats['api_latencies']);
    $stats['avg_api_latency'] = $latency_count ? array_sum($stats['api_latencies']) / $latency_count : null;
    $stats['p95_api_latency'] = $latency_count ? $stats['api_latencies'][max(0, (int)ceil($latency_count * 0.95) - 1)] : null;
    $all_gateway_latencies = array_merge($all_gateway_latencies, $stats['api_latencies']);

    if ($stats['attempts'] === 0) {
        $stats['health'] = 'No traffic';
        $stats['health_class'] = 'neutral';
    } elseif ($stats['resolved'] === 0) {
        $stats['health'] = 'Observing';
        $stats['health_class'] = 'watch';
        $active_gateway_count++;
    } elseif ($stats['success_rate'] >= 95) {
        $stats['health'] = 'Healthy';
        $stats['health_class'] = 'healthy';
        $active_gateway_count++;
        $operational_gateway_count++;
    } elseif ($stats['success_rate'] >= 85) {
        $stats['health'] = 'Monitoring';
        $stats['health_class'] = 'watch';
        $active_gateway_count++;
        $operational_gateway_count++;
    } else {
        $stats['health'] = 'Degraded';
        $stats['health_class'] = 'degraded';
        $active_gateway_count++;
    }

    foreach ($stats['failure_reasons'] as $reason => $count) {
        $all_failure_reasons[$reason] = ($all_failure_reasons[$reason] ?? 0) + $count;
    }
}
unset($stats);

sort($all_completion_durations);
$completion_count = count($all_completion_durations);
$overall_avg_completion = $completion_count ? array_sum($all_completion_durations) / $completion_count : null;
$overall_p95_completion = $completion_count ? $all_completion_durations[max(0, (int)ceil($completion_count * 0.95) - 1)] : null;
$gateway_latency_count = count($all_gateway_latencies);
sort($all_gateway_latencies);
$overall_avg_gateway_latency = $gateway_latency_count ? array_sum($all_gateway_latencies) / $gateway_latency_count : null;
$overall_p95_gateway_latency = $gateway_latency_count ? $all_gateway_latencies[max(0, (int)ceil($gateway_latency_count * 0.95) - 1)] : null;
$overall_resolved = count($paid_txns) + count($failed_txns) + count($chargeback_txns);
$overall_success_rate = $overall_resolved > 0 ? count($paid_txns) / $overall_resolved * 100 : 0;

arsort($all_failure_reasons);
$top_failure_reasons = array_slice($all_failure_reasons, 0, 4, true);
$failure_event_count = array_sum($all_failure_reasons);
$max_failure_reason_count = max(1, ...array_values($top_failure_reasons ?: [1]));

$stale_pending = 0;
$oldest_pending_age = 0;
$now_ts = time();
foreach ($pending_txns as $txn) {
    $created_ts = strtotime((string)($txn['created_at'] ?? '')) ?: $now_ts;
    $age = max(0, $now_ts - $created_ts);
    if ($age >= 900) $stale_pending++;
    $oldest_pending_age = max($oldest_pending_age, $age);
}

$traffic_days = [];
$traffic_anchor = $latest_txn_timestamp ?: time();
for ($offset = 6; $offset >= 0; $offset--) {
    $day_ts = strtotime('-' . $offset . ' days', $traffic_anchor);
    $key = date('Y-m-d', $day_ts);
    $traffic_days[$key] = ['label' => date('D', $day_ts), 'paid' => 0, 'failed' => 0, 'pending' => 0, 'total' => 0];
}
foreach ($txns as $txn) {
    $key = date('Y-m-d', strtotime((string)($txn['created_at'] ?? 'now')));
    if (!isset($traffic_days[$key])) continue;
    $status = strtolower((string)($txn['status'] ?? 'pending'));
    $bucket = $status === 'paid' ? 'paid' : ($status === 'pending' ? 'pending' : 'failed');
    $traffic_days[$key][$bucket]++;
    $traffic_days[$key]['total']++;
}
$max_daily_traffic = max(1, ...array_column($traffic_days, 'total'));

$best_gateway = null;
foreach ($gateway_analytics as $stats) {
    if ($stats['attempts'] === 0 || $stats['success_rate'] === null) continue;
    if ($best_gateway === null || $stats['success_rate'] > $best_gateway['success_rate']) {
        $best_gateway = $stats;
    }
}

$base_url = rtrim((string)($config['pay_url'] ?? ''), '/');
$section_titles = [
    'dashboard'    => 'Dashboard',
    'risk'         => 'Risk & Fraud',
    'transactions' => 'Transactions',
    'gateway'      => 'Gateway Analytics',
    'routing'      => 'Smart Routing Control Center',
    'reports'      => 'Reports',
    'help'         => 'Help & Integration',
    'components'   => 'Components',
];

$recent_txns = array_slice($txns, 0, 10);
$max_amount = max(1, $total_revenue, $failed_amount, $chargeback_amount);

$seen_users = [];
$new_users = 0;
$returning_users = 0;
foreach (array_reverse($txns) as $txn) {
    $user_key = strtolower(trim((string)($txn['customer_email'] ?? $txn['customer_phone'] ?? $txn['customer_name'] ?? 'unknown')));
    if ($user_key === '' || $user_key === 'unknown') {
        continue;
    }
    if (isset($seen_users[$user_key])) {
        $returning_users++;
    } else {
        $seen_users[$user_key] = true;
        $new_users++;
    }
}

$txns_for_js = array_map(function ($txn) {
    $txn['created_at_display'] = format_app_datetime($txn['created_at'] ?? null);
    $txn['updated_at_display'] = format_app_datetime($txn['updated_at'] ?? null);
    $txn['paid_at_display'] = format_app_datetime($txn['paid_at'] ?? null);
    $txn['ip_display'] = txn_ip($txn);
    return $txn;
}, $txns);
