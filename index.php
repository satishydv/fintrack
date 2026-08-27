<?php
session_start();
if (empty($_SESSION['dashboard_auth'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/lib/helpers.php';

if ($_GET['logout'] ?? false) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$section = $_GET['section'] ?? 'dashboard';
if (!in_array($section, ['dashboard', 'risk', 'transactions', 'gateway', 'reports', 'help'], true)) {
    $section = 'dashboard';
}

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

function money_inr(float $amount, int $decimals = 0): string {
    return 'Rs ' . number_format($amount, $decimals);
}

function txn_ip(array $txn): string {
    foreach (['ip_address', 'client_ip', 'customer_ip', 'request_ip'] as $key) {
        if (!empty($txn[$key])) return (string)$txn[$key];
    }
    return 'unknown';
}

function risk_score(array $stats): int {
    $score = 0;
    $score += min(45, $stats['chargebacks'] * 25);
    $score += min(30, $stats['failed'] * 6);
    $score += min(15, max(0, $stats['accounts'] - 1) * 5);
    $score += min(10, max(0, $stats['total'] - 5) * 2);
    return min(100, $score);
}

function risk_level(int $score): string {
    if ($score >= 70) return 'critical';
    if ($score >= 40) return 'high';
    if ($score >= 18) return 'watch';
    return 'normal';
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

function format_duration_short(float $seconds): string {
    if ($seconds < 60) return number_format($seconds, 1) . ' sec';
    if ($seconds < 3600) return number_format($seconds / 60, 1) . ' min';
    return number_format($seconds / 3600, 1) . ' hr';
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
    'dashboard' => 'Dashboard',
    'risk' => 'Risk & Fraud',
    'transactions' => 'Transactions',
    'gateway' => 'Gateway Analytics',
    'reports' => 'Reports',
    'help' => 'Help & Integration',
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Fintrack Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#090a0b;--panel:#111214;--panel2:#17191c;--sidebar:#0f1012;--line:#24262a;--line2:#30343a;--text:#f4f7fb;--muted:#8b929c;--soft:#c9d0d8;--cyan:#3be3ef;--green:#52d273;--amber:#e8c94d;--red:#ff6b6b;--blue:#7aa7ff;--active:#ef0750;--active2:#431921}
body{font-family:Inter,Arial,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;letter-spacing:0}
a{color:inherit;text-decoration:none}
.sidebar{width:240px;min-height:100vh;background:var(--sidebar);border-right:1px solid #1b1d20;padding:22px 16px;display:flex;flex-direction:column;gap:18px;flex-shrink:0}
.brand{display:flex;align-items:center;gap:10px}
.brand-mark{width:30px;height:30px;border-radius:8px;background:linear-gradient(135deg,#38f0e1,#4667ff);display:grid;place-items:center;font-weight:800;color:#071011}
.brand-name{font-size:15px;font-weight:700}
.profile{display:flex;align-items:center;gap:10px;padding:10px;background:linear-gradient(180deg,#1b1d20,#141518);border:1px solid var(--line2);border-radius:8px}
.avatar{width:34px;height:34px;border-radius:8px;background:#21262b;display:grid;place-items:center;color:var(--cyan);font-weight:700}
.profile strong{font-size:12px;display:block}.profile span{font-size:10px;color:var(--muted)}
.nav-label{font-size:10px;color:#6d737c;text-transform:uppercase;margin:4px 0 6px}
.nav{display:grid;gap:6px}.nav-item{display:flex;align-items:center;gap:10px;height:34px;padding:0 10px;border-radius:10px;color:#b3bac3;font-size:12px;border:1px solid transparent;white-space:nowrap}
.nav-item:hover{background:#1b1d20;border-color:#2b2f35;color:#fff}.nav-item.active{background:linear-gradient(90deg,var(--active),var(--active2));border-color:#772838;color:#fff;box-shadow:0 12px 28px rgba(239,7,80,.18)}.nav-item.active .nav-ico{color:#fff;background:rgba(255,255,255,.16);border-color:rgba(255,255,255,.24)}.nav-ico{width:21px;height:21px;border-radius:7px;display:grid;place-items:center;color:var(--cyan);background:rgba(59,227,239,.08);border:1px solid rgba(59,227,239,.12);flex:0 0 21px}.nav-ico svg{width:13px;height:13px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.support{margin-top:auto;display:grid;gap:8px;border-top:1px solid #1b1d20;padding-top:16px}.muted-link{font-size:12px;color:#b3bac3;padding:8px 10px;border-radius:6px}.muted-link:hover{background:#1b1d20;color:#fff}
.main{flex:1;padding:22px;overflow-x:hidden}.topbar{height:38px;display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}
.page-title{font-size:18px;font-weight:700}.tools{display:flex;align-items:center;gap:8px}.search{width:260px;background:#131416;border:1px solid #1f2226;border-radius:7px;color:#e8edf3;padding:9px 12px;font-size:12px;outline:none}.search:focus{border-color:#3a424a}
.icon-btn,.pill-btn{background:#15171a;border:1px solid #24282e;border-radius:7px;color:#d8dde4;height:32px;padding:0 11px;font-size:12px;display:inline-flex;align-items:center;gap:6px;cursor:pointer}.icon-btn{width:32px;justify-content:center;padding:0}.pill-btn:hover,.icon-btn:hover{border-color:#3a3f47;background:#1a1d20}
.alert{height:38px;border:1px solid #23272c;background:#141619;border-radius:7px;margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;padding:0 14px;color:#d7dde5;font-size:12px}.alert a{color:var(--cyan);font-weight:600}
.grid{display:grid;gap:14px}.dashboard-grid{grid-template-columns:minmax(0,1fr) 300px;align-items:start}.stat-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
.card{background:linear-gradient(180deg,#17191c,#121315);border:1px solid #24272c;border-radius:8px;overflow:hidden}.card-pad{padding:16px}.card-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}.card-title{font-size:13px;font-weight:700}.dots{color:#6f7780}
.stat-grid .card,.risk-grid .card{position:relative;min-height:126px;border-color:rgba(255,255,255,.2);box-shadow:inset 0 1px 0 rgba(255,255,255,.28),inset 0 -1px 0 rgba(255,255,255,.08),0 20px 55px rgba(0,0,0,.34);isolation:isolate}
.stat-grid .card::before,.risk-grid .card::before{content:'';position:absolute;inset:0;border-radius:8px;background:linear-gradient(135deg,rgba(255,255,255,.22),rgba(255,255,255,.06) 24%,transparent 48%),radial-gradient(circle at 18% 0,rgba(255,255,255,.24),transparent 28%);pointer-events:none;mix-blend-mode:screen;z-index:-1}
.stat-grid .card::after,.risk-grid .card::after{content:'';position:absolute;left:16px;right:16px;bottom:14px;height:6px;border-radius:999px;background:rgba(255,255,255,.14);box-shadow:0 0 18px rgba(255,255,255,.08)}
.stat-grid .card:nth-child(1){background:radial-gradient(circle at 82% 12%,rgba(137,76,255,.42),transparent 35%),linear-gradient(135deg,#30185d,#121315)}
.stat-grid .card:nth-child(1)::after{background:linear-gradient(90deg,#8b4dff 70%,rgba(255,255,255,.12) 70%)}
.stat-grid .card:nth-child(2){background:radial-gradient(circle at 82% 12%,rgba(255,111,39,.4),transparent 35%),linear-gradient(135deg,#4b2014,#121315)}
.stat-grid .card:nth-child(2)::after{background:linear-gradient(90deg,#ff7b22 55%,rgba(255,255,255,.12) 55%)}
.stat-grid .card:nth-child(3){background:radial-gradient(circle at 82% 12%,rgba(255,44,76,.4),transparent 35%),linear-gradient(135deg,#4a121f,#121315)}
.stat-grid .card:nth-child(3)::after{background:linear-gradient(90deg,#ff3156 48%,rgba(255,255,255,.12) 48%)}
.risk-grid .card:nth-child(1){background:radial-gradient(circle at 85% 12%,rgba(29,184,255,.4),transparent 34%),linear-gradient(135deg,#10325a,#121315)}
.risk-grid .card:nth-child(1)::after{background:linear-gradient(90deg,#2297ff 62%,rgba(255,255,255,.12) 62%)}
.risk-grid .card:nth-child(2){background:radial-gradient(circle at 85% 12%,rgba(255,42,107,.42),transparent 34%),linear-gradient(135deg,#541527,#121315)}
.risk-grid .card:nth-child(2)::after{background:linear-gradient(90deg,#ff2a6b 50%,rgba(255,255,255,.12) 50%)}
.risk-grid .card:nth-child(3){background:radial-gradient(circle at 85% 12%,rgba(145,255,40,.36),transparent 34%),linear-gradient(135deg,#1c450e,#121315)}
.risk-grid .card:nth-child(3)::after{background:linear-gradient(90deg,#79e51e 66%,rgba(255,255,255,.12) 66%)}
.risk-grid .card:nth-child(4){background:radial-gradient(circle at 85% 12%,rgba(16,218,190,.38),transparent 34%),linear-gradient(135deg,#0d493d,#121315)}
.risk-grid .card:nth-child(4)::after{background:linear-gradient(90deg,#10dabe 76%,rgba(255,255,255,.12) 76%)}
.stat-label{font-size:12px;color:#d8dde5;margin-bottom:12px}.stat-value{font-size:25px;font-weight:800;line-height:1}.stat-sub{margin-top:16px;font-size:11px;color:#c8d0d9;display:flex;justify-content:space-between;gap:10px;padding-bottom:14px}.delta{border-radius:4px;padding:3px 6px;font-size:10px;font-weight:700}.delta.good{background:#15351f;color:#62e283}.delta.bad{background:#3a171b;color:#ff7d7d}.delta.warn{background:#363015;color:#f0d867}
.report-hero{position:relative;margin-bottom:14px;padding:16px;border:1px solid #202a24;border-radius:12px;background:radial-gradient(circle at 0 0,rgba(111,255,68,.14),transparent 24%),radial-gradient(circle at 100% 20%,rgba(59,227,239,.12),transparent 24%),#070908;overflow:hidden}.report-hero::before{content:'';position:absolute;inset:-30px;background:repeating-linear-gradient(140deg,transparent 0 24px,rgba(124,255,66,.06) 25px,transparent 27px);opacity:.45;pointer-events:none}.report-grid{position:relative;z-index:1;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.report-token-card{position:relative;min-height:170px;padding:18px 16px 16px;border:1px solid #35443b;border-radius:12px;background:linear-gradient(180deg,#121512,#080a09);box-shadow:inset 0 1px 0 rgba(255,255,255,.12),0 18px 42px rgba(0,0,0,.4);clip-path:polygon(0 0,100% 0,100% 86%,88% 86%,82% 100%,18% 100%,12% 86%,0 86%)}.report-token-card.featured{background:linear-gradient(180deg,#cfff57,#a6f73f);border-color:#e2ff92;color:#071006;box-shadow:0 18px 44px rgba(174,255,63,.2),inset 0 1px 0 rgba(255,255,255,.55)}.report-token-card::after{content:'';position:absolute;inset:1px;border-radius:11px;background:linear-gradient(135deg,rgba(255,255,255,.14),transparent 35%);pointer-events:none}.report-token-label{font-size:11px;color:#d9e6da;margin-bottom:8px}.featured .report-token-label{color:#23300d}.report-token-value{font-size:25px;font-weight:800;line-height:1.05}.report-token-unit{font-size:9px;font-weight:800;color:#9cff65;margin-left:4px}.featured .report-token-unit{color:#23300d}.report-token-sub{margin-top:16px;font-size:11px;color:#a7b4aa;line-height:1.5}.featured .report-token-sub{color:#314111}.report-token-btn{position:absolute;left:16px;right:16px;bottom:24px;height:30px;border:0;border-radius:999px;background:linear-gradient(90deg,#7dff5d,#e8ff9c);color:#071006;font-size:9px;font-weight:900;letter-spacing:.04em;cursor:pointer}.featured .report-token-btn{background:#071006;color:#cfff57}.report-filter-panel{margin-top:14px}.report-box{background:#202124;border:1px solid #2c2f34;border-radius:18px;padding:18px 22px;box-shadow:inset 0 1px 0 rgba(255,255,255,.08)}.report-code{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:13px;line-height:1.8;color:#fff}.report-note{font-size:13px;color:#cfd6de;margin-top:12px}.filter-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.field label{display:block;font-size:11px;color:#8b929c;margin-bottom:6px}.field input,.field select{width:100%;height:38px;background:#0d0f11;border:1px solid #2a2e34;border-radius:7px;color:#e8edf3;padding:0 10px;font:inherit;font-size:12px;outline:none}.field input:focus,.field select:focus{border-color:#a7ff42}.report-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}.report-actions .pill-btn{height:36px}.report-actions .primary{background:linear-gradient(90deg,#7dff5d,#e8ff9c);border-color:#b6ff66;color:#071006;font-weight:900}
.chart{height:238px;padding:16px;position:relative;background:linear-gradient(180deg,#151719,#111315)}.chart-lines{position:absolute;inset:16px;background:repeating-linear-gradient(to top,transparent 0,transparent 44px,rgba(255,255,255,.05) 45px)}.bars{height:180px;display:flex;align-items:end;gap:12px;position:relative;z-index:1;margin-top:24px}.bar{flex:1;min-width:12px;border-radius:5px 5px 0 0;background:linear-gradient(180deg,var(--cyan),rgba(59,227,239,.08));box-shadow:0 0 26px rgba(59,227,239,.14)}.bar.failed{background:linear-gradient(180deg,#ff6b6b,rgba(255,107,107,.08))}.months{display:flex;gap:12px;color:#858c96;font-size:10px;position:relative;z-index:2;margin-top:8px}.months span{flex:1;text-align:center}
.side-stack{display:grid;gap:14px}.risk-meter{height:148px;border-radius:8px;background:radial-gradient(circle at 70% 15%,rgba(59,227,239,.28),transparent 36%),linear-gradient(135deg,#20252a,#111315);border:1px solid #293039;padding:16px;display:flex;flex-direction:column;justify-content:space-between}.meter-big{font-size:36px;font-weight:800}.meter-label{font-size:11px;color:#aeb6c0}.mini-row{display:flex;justify-content:space-between;font-size:12px;color:#cfd6de;margin-top:9px}.mini-row strong{color:#fff}
.spend-bars{display:flex;gap:4px;margin-top:10px}.spend-bars span{height:34px;flex:1;border-radius:3px;background:#30343a}.spend-bars span.on{background:linear-gradient(180deg,var(--cyan),#35b8c9)}
.table-card{margin-top:14px;overflow-x:auto}.table-header{height:48px;padding:0 14px;display:flex;align-items:center;justify-content:space-between;gap:12px;border-bottom:1px solid #24272c}.table-title{font-size:13px;font-weight:700;white-space:nowrap}table{width:100%;min-width:780px;border-collapse:collapse}th{height:34px;background:#1a1c1f;color:#8c949e;font-size:11px;font-weight:500;text-align:left;padding:0 14px;border-bottom:1px solid #24272c;white-space:nowrap}td{height:48px;padding:0 14px;border-bottom:1px solid #202328;font-size:12px;color:#dce2e9;vertical-align:middle}tr:hover td{background:#171a1d;cursor:pointer}.mono{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:11px}.sub{display:block;color:#7f8791;font-size:10px;margin-top:3px}.badge{display:inline-flex;align-items:center;border-radius:4px;padding:4px 8px;font-size:10px;font-weight:700;text-transform:capitalize;border:1px solid transparent}.badge.paid,.badge.normal{background:#15351f;color:#65df84;border-color:#245932}.badge.pending,.badge.watch{background:#373015;color:#ead45b;border-color:#5c5023}.badge.failed,.badge.validation_failed,.badge.notify_failed,.badge.high{background:#3a171b;color:#ff7d7d;border-color:#5b252c}.badge.chargeback,.badge.disputed,.badge.refunded,.badge.critical{background:#40151d;color:#ff9aa3;border-color:#73303b}.badge.razorpay{background:#152641;color:#8cb5ff;border-color:#27466e}.badge.cashfree{background:#12372b;color:#69dfb2;border-color:#215c49}.badge.payu{background:#2a2141;color:#c3a7ff;border-color:#41306d}
.risk-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:14px}.risk-layout{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:14px}.rule-list{display:grid;gap:10px}.rule{padding:12px;border:1px solid #252a30;border-radius:7px;background:#141619}.rule strong{font-size:12px}.rule p{font-size:11px;color:#8b929c;margin-top:5px;line-height:1.5}
.analytics-hero{position:relative;overflow:hidden;border:1px solid #23383b;border-radius:14px;padding:22px;margin-bottom:14px;background:radial-gradient(circle at 83% 12%,rgba(59,227,239,.2),transparent 27%),radial-gradient(circle at 4% 100%,rgba(94,85,255,.2),transparent 34%),linear-gradient(135deg,#101719,#0d0f12 58%,#11151a)}.analytics-hero::after{content:'';position:absolute;inset:0;background:linear-gradient(115deg,transparent 0 54%,rgba(255,255,255,.035) 54% 55%,transparent 55% 62%,rgba(255,255,255,.025) 62% 63%,transparent 63%);pointer-events:none}.analytics-hero-content{position:relative;z-index:1;display:flex;align-items:flex-start;justify-content:space-between;gap:28px}.eyebrow{display:flex;align-items:center;gap:7px;color:#8beef4;font-size:10px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;margin-bottom:10px}.eyebrow-dot{width:7px;height:7px;border-radius:50%;background:var(--green);box-shadow:0 0 12px var(--green)}.analytics-hero h1,.help-hero h1{font-size:25px;line-height:1.2;letter-spacing:-.04em}.analytics-hero p,.help-hero p{max-width:610px;margin-top:9px;color:#9da7b1;font-size:12px;line-height:1.65}.health-summary{min-width:205px;padding:14px;border:1px solid rgba(116,242,230,.18);border-radius:11px;background:rgba(7,12,14,.58);backdrop-filter:blur(10px)}.health-summary-top{display:flex;align-items:center;justify-content:space-between;color:#9aa5af;font-size:10px}.health-orbit{position:relative;width:58px;height:58px;margin:10px auto 7px;border-radius:50%;display:grid;place-items:center;background:conic-gradient(var(--cyan) <?= $active_gateway_count ? round(($operational_gateway_count / $active_gateway_count) * 100) : 0 ?>%,#252a2f 0);box-shadow:0 0 28px rgba(59,227,239,.12)}.health-orbit::before{content:'';position:absolute;inset:6px;background:#0e1316;border-radius:50%}.health-orbit strong{position:relative;font-size:15px}.health-summary-copy{text-align:center;font-size:10px;color:#98a2ac}.metric-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:14px}.metric-card{position:relative;min-height:128px;padding:16px;border:1px solid #242a30;border-radius:11px;background:linear-gradient(145deg,#171a1e,#101214);overflow:hidden}.metric-card::after{content:'';position:absolute;width:90px;height:90px;border-radius:50%;right:-38px;top:-38px;background:var(--metric-glow,rgba(59,227,239,.14));filter:blur(2px)}.metric-head{display:flex;align-items:center;justify-content:space-between;color:#9da6b0;font-size:11px}.metric-icon{width:29px;height:29px;border-radius:8px;display:grid;place-items:center;color:var(--metric-color,var(--cyan));background:color-mix(in srgb,var(--metric-color,var(--cyan)) 12%,transparent);border:1px solid color-mix(in srgb,var(--metric-color,var(--cyan)) 24%,transparent)}.metric-icon svg{width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}.metric-value{margin-top:12px;font-size:25px;font-weight:800;letter-spacing:-.04em}.metric-foot{margin-top:7px;color:#78828d;font-size:10px;line-height:1.4}.metric-foot strong{color:#b9c2cb;font-weight:600}.analytics-layout{display:grid;grid-template-columns:minmax(0,1.55fr) minmax(280px,.75fr);gap:14px;margin-bottom:14px}.analytics-panel{border-radius:11px}.panel-header{min-height:54px;padding:13px 16px;border-bottom:1px solid #25292e;display:flex;align-items:center;justify-content:space-between;gap:12px}.panel-header h2{font-size:13px}.panel-header p{font-size:10px;color:#7f8994;margin-top:4px}.legend{display:flex;align-items:center;gap:12px;color:#89939d;font-size:9px}.legend span{display:flex;align-items:center;gap:5px}.legend i{width:6px;height:6px;border-radius:50%;display:block}.gateway-performance{display:grid}.gateway-row{display:grid;grid-template-columns:minmax(120px,1.2fr) repeat(4,minmax(72px,.75fr));gap:12px;align-items:center;padding:14px 16px;border-bottom:1px solid #202429}.gateway-row:last-child{border-bottom:0}.gateway-identity{display:flex;align-items:center;gap:10px}.gateway-logo{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;font-size:13px;font-weight:800;border:1px solid currentColor}.gateway-logo.razorpay{color:#78a8ff;background:#13233e}.gateway-logo.cashfree{color:#62dca8;background:#102e25}.gateway-logo.payu{color:#c8a8ff;background:#271d3d}.gateway-name strong{font-size:12px;text-transform:capitalize}.gateway-name span{display:flex;align-items:center;gap:5px;margin-top:4px;color:#77818c;font-size:9px}.health-dot{width:6px;height:6px;border-radius:50%;display:inline-block}.health-dot.healthy{background:var(--green);box-shadow:0 0 8px var(--green)}.health-dot.watch{background:var(--amber);box-shadow:0 0 8px rgba(232,201,77,.7)}.health-dot.degraded{background:var(--red);box-shadow:0 0 8px rgba(255,107,107,.7)}.health-dot.neutral{background:#59616b}.gateway-stat label{display:block;color:#727c87;font-size:9px;margin-bottom:5px}.gateway-stat strong{font-size:12px}.rate-track{height:4px;border-radius:99px;background:#292d32;margin-top:6px;overflow:hidden}.rate-fill{height:100%;border-radius:inherit;background:linear-gradient(90deg,#37d89a,#7cf3ad)}.rate-fill.watch{background:linear-gradient(90deg,#ddb83b,#f0d867)}.rate-fill.degraded{background:linear-gradient(90deg,#df4453,#ff7777)}.traffic-chart{height:250px;padding:18px 16px 14px;display:flex;align-items:flex-end;gap:12px;background:linear-gradient(180deg,rgba(255,255,255,.012),transparent),repeating-linear-gradient(to top,transparent 0 49px,rgba(255,255,255,.045) 50px)}.traffic-day{flex:1;min-width:26px;height:100%;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;gap:7px}.traffic-stack{width:min(34px,100%);height:190px;display:flex;flex-direction:column;justify-content:flex-end;border-radius:5px 5px 2px 2px;overflow:hidden}.traffic-segment{width:100%;min-height:0}.traffic-segment.paid{background:linear-gradient(180deg,#54e58e,#25895b)}.traffic-segment.failed{background:linear-gradient(180deg,#ff7474,#8d333c)}.traffic-segment.pending{background:linear-gradient(180deg,#f0d45d,#806d2a)}.traffic-day label{font-size:9px;color:#77818c}.traffic-day strong{font-size:9px;color:#c8d0d8;font-weight:600}.signal-list{padding:6px 16px 15px}.signal-item{padding:12px 0;border-bottom:1px solid #202429}.signal-item:last-child{border-bottom:0}.signal-top{display:flex;align-items:center;justify-content:space-between;gap:10px;font-size:10px}.signal-top span{color:#c7ced6;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.signal-top strong{font-size:10px}.signal-track{height:5px;border-radius:99px;background:#252a2f;margin-top:8px;overflow:hidden}.signal-fill{height:100%;border-radius:inherit;background:linear-gradient(90deg,#ff555f,#ff9a66)}.ops-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.ops-card{padding:15px;border:1px solid #252a30;border-radius:10px;background:#121518}.ops-card-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}.ops-card h3{font-size:12px}.ops-card p{color:#838d98;font-size:10px;line-height:1.55}.ops-value{font-size:19px;font-weight:800;margin-bottom:5px}.ops-tag{font-size:9px;border-radius:99px;padding:4px 7px;background:#20272b;color:#aab5bf}.ops-card.action{border-color:#24433e;background:linear-gradient(135deg,rgba(48,208,174,.09),#121518)}.ops-link{display:inline-flex;align-items:center;gap:6px;margin-top:10px;color:#65e8d5;font-size:10px;font-weight:700}
.help-hero{position:relative;overflow:hidden;padding:24px;border:1px solid #29313b;border-radius:14px;background:radial-gradient(circle at 88% 10%,rgba(118,94,255,.24),transparent 30%),radial-gradient(circle at 15% 100%,rgba(59,227,239,.14),transparent 30%),#111419;margin-bottom:14px}.help-hero::after{content:'</>';position:absolute;right:35px;bottom:-22px;font-family:ui-monospace,monospace;font-size:110px;font-weight:800;color:rgba(255,255,255,.025);letter-spacing:-.18em}.help-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:16px}.primary-btn{height:34px;padding:0 13px;border:1px solid #77eae2;border-radius:7px;background:linear-gradient(90deg,#3ce6dd,#84f1bd);color:#07110f;font:inherit;font-size:10px;font-weight:800;cursor:pointer}.secondary-btn{height:34px;padding:0 13px;border:1px solid #343a43;border-radius:7px;background:#171a1f;color:#dce3e9;font:inherit;font-size:10px;font-weight:700;cursor:pointer}.help-layout{display:grid;grid-template-columns:minmax(0,1fr) 310px;gap:14px;align-items:start}.integration-steps{display:grid;gap:12px}.integration-step{display:grid;grid-template-columns:34px minmax(0,1fr);gap:12px;padding:17px;border:1px solid #252a31;border-radius:11px;background:linear-gradient(145deg,#16191d,#101214)}.step-number{width:30px;height:30px;border-radius:9px;display:grid;place-items:center;background:linear-gradient(135deg,#3ae3dd,#6d76ff);color:#071013;font-size:11px;font-weight:900;box-shadow:0 8px 24px rgba(59,227,239,.12)}.step-content h2{font-size:13px}.step-content>p{margin-top:6px;color:#89939d;font-size:10px;line-height:1.55}.env-strip,.endpoint-strip{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:12px;border:1px solid #293039;border-radius:8px;background:#0b0d10;padding:10px 11px}.env-strip code,.endpoint-strip code{color:#b8c5d1;font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:10px;word-break:break-all}.method{padding:4px 6px;border-radius:4px;color:#07100e;background:#62e3b2;font-size:8px;font-weight:900;flex:0 0 auto}.method.get{background:#77a9ff}.code-wrap{position:relative;margin-top:12px;border:1px solid #293039;border-radius:9px;background:#090b0d;overflow:hidden}.code-label{height:32px;padding:0 11px;border-bottom:1px solid #22272d;display:flex;align-items:center;justify-content:space-between;color:#79838e;font-size:9px}.copy-btn{border:0;background:transparent;color:#64e7dc;font:inherit;font-size:9px;font-weight:700;cursor:pointer}.copy-btn:hover{color:#a1fff8}.code-block{padding:13px;color:#bec9d3;font:10px/1.7 ui-monospace,SFMono-Regular,Consolas,monospace;white-space:pre-wrap;overflow:auto;tab-size:2}.code-block .code-accent{color:#65e7dc}.code-block .code-string{color:#b9e37b}.help-aside{display:grid;gap:14px;position:sticky;top:18px}.quick-card{padding:16px;border:1px solid #252a31;border-radius:11px;background:#13161a}.quick-card h3{font-size:12px}.quick-card>p{color:#7f8994;font-size:10px;line-height:1.55;margin-top:5px}.endpoint-list{display:grid;gap:9px;margin-top:13px}.endpoint-mini{padding:10px;border:1px solid #252b31;border-radius:8px;background:#0d0f12}.endpoint-mini strong{display:flex;align-items:center;gap:7px;font:10px ui-monospace,monospace}.endpoint-mini span{display:block;color:#747f8b;font-size:9px;margin-top:5px}.check-list{display:grid;gap:10px;margin-top:13px}.check-item{display:flex;align-items:flex-start;gap:8px;color:#aab3bd;font-size:10px;line-height:1.45}.check-icon{width:17px;height:17px;border-radius:50%;display:grid;place-items:center;flex:0 0 17px;background:#153a2b;color:#69e5a3;font-size:9px}.flow-list{display:grid;margin-top:13px}.flow-item{position:relative;padding:0 0 15px 25px;color:#aab3bd;font-size:10px}.flow-item:last-child{padding-bottom:0}.flow-item::before{content:'';position:absolute;left:7px;top:8px;bottom:-3px;width:1px;background:#2c343b}.flow-item:last-child::before{display:none}.flow-item::after{content:'';position:absolute;left:3px;top:4px;width:9px;height:9px;border-radius:50%;background:#4ee1d4;box-shadow:0 0 0 4px rgba(78,225,212,.1)}.flow-item strong{display:block;color:#e4e9ee;margin-bottom:3px}.inline-note{margin-top:11px;padding:10px 11px;border-left:2px solid #e7cb52;border-radius:0 6px 6px 0;background:rgba(231,203,82,.07);color:#b9af79;font-size:9px;line-height:1.55}
.empty{padding:44px;text-align:center;color:var(--muted);font-size:13px}.action-btn{border:1px solid #30343a;background:#1a1d20;color:#dce2e9;border-radius:6px;padding:6px 9px;font-size:11px;cursor:pointer}.action-btn:hover{border-color:#48505a}
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:50;display:none;align-items:center;justify-content:center;padding:20px}.modal{width:min(660px,100%);max-height:82vh;overflow:auto;background:#121416;border:1px solid #2a2e34;border-radius:10px}.modal-head{height:52px;border-bottom:1px solid #24272c;display:flex;align-items:center;justify-content:space-between;padding:0 16px}.modal-title{font-size:14px;font-weight:700}.modal-close{background:#1a1d20;border:1px solid #30343a;color:#dce2e9;border-radius:6px;width:30px;height:30px;cursor:pointer}.modal-body{padding:16px}.detail-row{display:grid;grid-template-columns:150px minmax(0,1fr);gap:16px;border-bottom:1px solid #202328;padding:10px 0;font-size:12px}.detail-key{color:#8b929c}.detail-val{word-break:break-word}.json-block{background:#090a0b;border:1px solid #24272c;border-radius:7px;padding:12px;margin-top:12px;color:#9aa3ad;font-size:11px;white-space:pre-wrap;max-height:220px;overflow:auto}.section-title{font-size:11px;text-transform:uppercase;color:#7f8791;margin:16px 0 8px}
@media(max-width:1060px){.dashboard-grid,.risk-layout{grid-template-columns:1fr}.report-grid,.metric-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.risk-grid{grid-template-columns:repeat(2,1fr)}.stat-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.analytics-layout,.help-layout{grid-template-columns:1fr}.help-aside{position:static;grid-template-columns:repeat(3,minmax(0,1fr))}.sidebar{width:210px}.search{width:190px}}
@media(max-width:860px){body{display:block}.sidebar{position:sticky;top:0;z-index:20;width:100%;min-height:0;border-right:0;border-bottom:1px solid #1b1d20;padding:14px;gap:12px}.brand{justify-content:space-between}.profile,.nav-label,.support{display:none}.nav{display:flex;gap:8px;overflow-x:auto;padding-bottom:2px;scrollbar-width:none}.nav::-webkit-scrollbar{display:none}.nav-item{height:36px;padding:0 14px;flex:0 0 auto}.main{padding:14px}.topbar{height:auto;align-items:flex-start;gap:10px}.tools{display:flex;flex-wrap:wrap;justify-content:flex-end}.search{width:min(100%,210px)}.alert{height:auto;align-items:flex-start;gap:10px;padding:12px;line-height:1.45}.stat-grid{grid-template-columns:1fr}.risk-grid{grid-template-columns:1fr 1fr}.chart{height:220px}.bars{height:160px}.side-stack{grid-template-columns:1fr}.table-header .search{display:none}.gateway-row{grid-template-columns:minmax(130px,1.2fr) repeat(2,minmax(75px,.7fr))}.gateway-row .gateway-stat:nth-last-child(-n+2){display:none}.ops-grid{grid-template-columns:1fr 1fr}.help-aside{grid-template-columns:1fr}.modal{max-height:90vh}.detail-row{grid-template-columns:1fr;gap:5px}}
@media(max-width:540px){.main{padding:12px}.page-title{font-size:17px}.tools{display:none}.risk-grid,.filter-grid,.report-grid,.metric-grid,.ops-grid{grid-template-columns:1fr}.card-pad{padding:14px}.stat-value{font-size:23px}.stat-sub{display:grid}.analytics-hero{padding:18px}.analytics-hero-content{display:block}.analytics-hero h1,.help-hero h1{font-size:21px}.health-summary{margin-top:16px;min-width:0}.health-orbit{margin:9px 0 6px}.health-summary-copy{text-align:left}.gateway-row{grid-template-columns:minmax(120px,1.2fr) minmax(78px,.7fr);padding:12px}.gateway-row .gateway-stat:nth-child(n+3){display:none}.traffic-chart{gap:7px;padding-left:10px;padding-right:10px}.traffic-stack{width:24px}.legend{display:none}.help-hero{padding:18px}.integration-step{grid-template-columns:1fr}.step-number{margin-bottom:2px}.env-strip,.endpoint-strip{align-items:flex-start;flex-direction:column}.chart{height:196px;padding:12px}.bars{height:135px;gap:8px;margin-top:22px}.months{gap:6px;font-size:9px}.risk-meter{height:132px}.meter-big{font-size:32px}.table-card{border-radius:8px;margin-left:-2px;margin-right:-2px}table{min-width:720px}th,td{padding:0 10px}.modal-overlay{padding:10px}.modal-body{padding:14px}.sidebar{padding:12px}.brand-name{font-size:14px}.brand-mark{width:28px;height:28px}.nav-item{font-size:11px;height:34px;padding:0 12px}.report-box{border-radius:14px;padding:16px}.report-code{font-size:12px}.report-token-card{min-height:155px}}
</style>
</head>
<body>
<aside class="sidebar">
  <div class="brand">
    <div class="brand-mark">F</div>
    <div class="brand-name">Fintrack</div>
  </div>
  <div class="profile">
    <div class="avatar">AD</div>
    <div><strong>Admin</strong><span>risk operations</span></div>
  </div>
  <div>
    <div class="nav-label">Main menu</div>
    <nav class="nav">
      <a class="nav-item <?= $section === 'dashboard' ? 'active' : '' ?>" href="index.php"><span class="nav-ico"><svg viewBox="0 0 24 24"><path d="M4 13.5 12 6l8 7.5"/><path d="M6.5 12.5V20h11v-7.5"/><path d="M10 20v-5h4v5"/></svg></span>Dashboard</a>
      <a class="nav-item <?= $section === 'risk' ? 'active' : '' ?>" href="?section=risk"><span class="nav-ico"><svg viewBox="0 0 24 24"><path d="M12 3.5 19 7v5.4c0 4-2.7 7.1-7 8.1-4.3-1-7-4.1-7-8.1V7l7-3.5Z"/><path d="M9.5 12.5 11.2 14l3.4-4"/></svg></span>Risk & Fraud</a>
      <a class="nav-item <?= $section === 'transactions' ? 'active' : '' ?>" href="?section=transactions"><span class="nav-ico"><svg viewBox="0 0 24 24"><path d="M7 7h10"/><path d="M7 12h7"/><path d="M7 17h10"/><path d="M4.5 4h15v16h-15z"/></svg></span>Transactions</a>
      <a class="nav-item <?= $section === 'gateway' ? 'active' : '' ?>" href="?section=gateway"><span class="nav-ico"><svg viewBox="0 0 24 24"><path d="M4 18V8"/><path d="M10 18V5"/><path d="M16 18v-7"/><path d="M21 18H3"/></svg></span>Gateway Analytics</a>
      <a class="nav-item <?= $section === 'reports' ? 'active' : '' ?>" href="?section=reports"><span class="nav-ico"><svg viewBox="0 0 24 24"><path d="M7 4h8l3 3v13H7z"/><path d="M15 4v4h4"/><path d="M9.5 13h5"/><path d="M9.5 17h4"/></svg></span>Reports</a>
      <a class="nav-item <?= $section === 'help' ? 'active' : '' ?>" href="?section=help"><span class="nav-ico"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M9.7 9a2.5 2.5 0 0 1 4.8 1c0 1.7-2.5 2-2.5 3.8"/><path d="M12 17.5h.01"/></svg></span>Help & Integration</a>
    </nav>
  </div>
  <div class="support">
    <a class="muted-link" href="#" style="opacity:.5;pointer-events:none">Settings</a>
    <a class="muted-link" href="?logout=1">Logout</a>
  </div>
</aside>

<main class="main">
  <div class="topbar">
    <div class="page-title"><?= htmlspecialchars($section_titles[$section]) ?></div>
    <div class="tools">
      <?php if (in_array($section, ['dashboard', 'transactions', 'reports'], true)): ?>
        <input class="search" type="text" placeholder="Search transactions..." id="globalSearch" onkeyup="filterTable(this.value)">
      <?php elseif ($section === 'gateway'): ?>
        <span class="badge normal">Live transaction data</span>
      <?php else: ?>
        <span class="badge watch">API v1</span>
      <?php endif; ?>
      <button class="icon-btn" onclick="location.reload()">R</button>
      <button class="pill-btn"><?= htmlspecialchars(date('d M Y')) ?></button>
    </div>
  </div>

  <?php if ($section === 'dashboard'): ?>
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
  <?php endif; ?>

  <?php if ($section === 'risk'): ?>
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
  <?php endif; ?>

  <?php if ($section === 'gateway'): ?>
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
  <?php endif; ?>

  <?php if ($section === 'help'): ?>
    <section class="help-hero">
      <div class="eyebrow"><span class="eyebrow-dot"></span>Developer quickstart</div>
      <h1>Accept your first orchestrated payment.</h1>
      <p>Connect your server to Fintrack once, choose Razorpay, Cashfree, or PayU per request, and receive one consistent payment lifecycle.</p>
      <div class="help-actions">
        <button class="primary-btn" onclick="document.getElementById('step-create').scrollIntoView({behavior:'smooth'})">Start integration</button>
        <button class="secondary-btn" data-copy-text="<?= htmlspecialchars($base_url) ?>" onclick="copyText(this)">Copy base URL</button>
      </div>
    </section>

    <div class="help-layout">
      <div class="integration-steps">
        <section class="integration-step">
          <div class="step-number">01</div>
          <div class="step-content">
            <h2>Configure server credentials</h2>
            <p>Keep the shared API key on your backend only. Never expose it in browser JavaScript or a mobile app bundle.</p>
            <div class="env-strip"><code>PAYMENT_BASE_URL=<?= htmlspecialchars($base_url) ?><br>PAYMENT_API_KEY=YOUR_SHARED_SECRET</code><button class="copy-btn" data-copy-text="PAYMENT_BASE_URL=<?= htmlspecialchars($base_url) ?>&#10;PAYMENT_API_KEY=YOUR_SHARED_SECRET" onclick="copyText(this)">Copy</button></div>
          </div>
        </section>

        <section class="integration-step" id="step-create">
          <div class="step-number">02</div>
          <div class="step-content">
            <h2>Create a payment</h2>
            <p>Call the initiate endpoint from your server. Save the returned <span class="mono">txn_id</span>, then redirect the customer to <span class="mono">payment_url</span>.</p>
            <div class="endpoint-strip"><span class="method">POST</span><code><?= htmlspecialchars($base_url) ?>/api/initiate.php</code></div>
            <div class="code-wrap">
              <div class="code-label"><span>cURL · server-side</span><button class="copy-btn" data-copy-target="createPaymentCode" onclick="copyCode(this)">Copy code</button></div>
              <pre class="code-block" id="createPaymentCode">curl -X POST "<?= htmlspecialchars($base_url) ?>/api/initiate.php" \
  -H "X-Api-Key: YOUR_SHARED_SECRET" \
  -H "Content-Type: application/json" \
  -d '{
    "order_id": "ORD-1042",
    "amount": 499.00,
    "currency": "INR",
    "customer_name": "Aarav Mehta",
    "customer_email": "aarav@example.com",
    "customer_phone": "9876543210",
    "gateway": "razorpay",
    "return_url": "https://yourapp.com/payment/return",
    "webhook_url": "https://yourapp.com/api/payment/webhook",
    "description": "Order #1042"
  }'</pre>
            </div>
            <div class="inline-note">Supported gateway values: <strong>razorpay</strong>, <strong>cashfree</strong>, and <strong>payu</strong>. Amount must be greater than zero.</div>
          </div>
        </section>

        <section class="integration-step">
          <div class="step-number">03</div>
          <div class="step-content">
            <h2>Redirect to hosted checkout</h2>
            <p>A successful initiation returns a secure hosted URL. Redirect the customer there; Fintrack handles the gateway-specific checkout.</p>
            <div class="code-wrap">
              <div class="code-label"><span>Success response</span><button class="copy-btn" data-copy-target="successResponseCode" onclick="copyCode(this)">Copy</button></div>
              <pre class="code-block" id="successResponseCode">{
  "success": true,
  "txn_id": "TXN_ABC123_1704067200",
  "payment_url": "<?= htmlspecialchars($base_url) ?>/pay.php?txn=TXN_ABC123_1704067200",
  "gateway": "razorpay",
  "amount": 499,
  "currency": "INR"
}</pre>
            </div>
          </div>
        </section>

        <section class="integration-step">
          <div class="step-number">04</div>
          <div class="step-content">
            <h2>Verify webhook updates</h2>
            <p>Your webhook receives the authoritative server-to-server result. Verify <span class="mono">X-Webhook-Signature</span> with HMAC-SHA256 before updating the order.</p>
            <div class="code-wrap">
              <div class="code-label"><span>PHP · signature verification</span><button class="copy-btn" data-copy-target="webhookCode" onclick="copyCode(this)">Copy code</button></div>
              <pre class="code-block" id="webhookCode">&lt;?php
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
$expected = hash_hmac('sha256', $payload, getenv('PAYMENT_API_KEY'));

if (!hash_equals($expected, $signature)) {
    http_response_code(401);
    exit('Invalid signature');
}

$event = json_decode($payload, true);
// Use $event['order_id'] and $event['status'] idempotently.
http_response_code(200);</pre>
            </div>
          </div>
        </section>

        <section class="integration-step">
          <div class="step-number">05</div>
          <div class="step-content">
            <h2>Confirm status when needed</h2>
            <p>Poll by transaction ID as a fallback after the customer returns, or when a webhook is delayed. Trust a verified webhook or status response over query parameters.</p>
            <div class="endpoint-strip"><span class="method get">GET</span><code><?= htmlspecialchars($base_url) ?>/api/status.php?txn_id=TXN_ABC123</code><button class="copy-btn" data-copy-text="<?= htmlspecialchars($base_url) ?>/api/status.php?txn_id=TXN_ABC123" onclick="copyText(this)">Copy</button></div>
          </div>
        </section>
      </div>

      <aside class="help-aside">
        <section class="quick-card">
          <h3>Payment flow</h3>
          <p>The complete lifecycle at a glance.</p>
          <div class="flow-list">
            <div class="flow-item"><strong>Your server creates payment</strong>Fintrack returns a hosted URL.</div>
            <div class="flow-item"><strong>Customer completes checkout</strong>The selected gateway processes payment.</div>
            <div class="flow-item"><strong>Webhook confirms result</strong>Your server verifies and fulfills the order.</div>
            <div class="flow-item"><strong>Status API reconciles</strong>Use polling only as a fallback.</div>
          </div>
        </section>

        <section class="quick-card">
          <h3>Endpoint reference</h3>
          <p>One small API surface for every gateway.</p>
          <div class="endpoint-list">
            <div class="endpoint-mini"><strong><span class="method">POST</span>/api/initiate.php</strong><span>Create an orchestrated payment</span></div>
            <div class="endpoint-mini"><strong><span class="method get">GET</span>/api/status.php</strong><span>Retrieve the current payment state</span></div>
            <div class="endpoint-mini"><strong><span class="method">POST</span>Your webhook URL</strong><span>Receive signed lifecycle events</span></div>
          </div>
        </section>

        <section class="quick-card">
          <h3>Production checklist</h3>
          <p>Before sending live traffic.</p>
          <div class="check-list">
            <div class="check-item"><span class="check-icon">✓</span><span>Use HTTPS for return and webhook URLs.</span></div>
            <div class="check-item"><span class="check-icon">✓</span><span>Store the API key in server environment variables.</span></div>
            <div class="check-item"><span class="check-icon">✓</span><span>Verify webhook signatures before fulfillment.</span></div>
            <div class="check-item"><span class="check-icon">✓</span><span>Make webhook handling idempotent by transaction ID.</span></div>
            <div class="check-item"><span class="check-icon">✓</span><span>Persist both your order ID and Fintrack transaction ID.</span></div>
          </div>
        </section>
      </aside>
    </div>
  <?php endif; ?>

  <?php if ($section === 'reports'): ?>
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
  <?php endif; ?>

  <?php if ($section === 'transactions' || $section === 'dashboard'): ?>
    <div class="card table-card">
      <div class="table-header">
        <div class="table-title"><?= $section === 'dashboard' ? 'Transaction History' : 'All Transactions' ?></div>
        <input class="search" type="text" placeholder="Search order / customer..." onkeyup="filterTable(this.value)">
      </div>
      <?php if (empty($txns)): ?>
        <div class="empty">No transactions yet.</div>
      <?php else: ?>
      <table id="txnTable">
        <thead>
          <tr><th>Transaction ID</th><th>Description</th><th>Customer</th><th>Amount</th><th>Gateway</th><th>IP</th><th>Status</th><th>Date</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach (($section === 'dashboard' ? $recent_txns : $txns) as $t): ?>
          <tr onclick="viewTxn('<?= htmlspecialchars($t['txn_id']) ?>')">
            <td class="mono"><?= htmlspecialchars(substr($t['txn_id'] ?? '', 0, 18)) ?>...</td>
            <td><?= htmlspecialchars($t['description'] ?? $t['order_id'] ?? 'Payment') ?><span class="sub"><?= htmlspecialchars($t['order_id'] ?? '') ?></span></td>
            <td><?= htmlspecialchars($t['customer_name'] ?? '-') ?><span class="sub"><?= htmlspecialchars($t['customer_email'] ?? '-') ?></span></td>
            <td class="mono"><?= money_inr((float)($t['amount'] ?? 0), 2) ?></td>
            <td><span class="badge <?= htmlspecialchars($t['gateway'] ?? '') ?>"><?= htmlspecialchars($t['gateway'] ?? '-') ?></span></td>
            <td class="mono"><?= htmlspecialchars(txn_ip($t)) ?></td>
            <td><span class="badge <?= htmlspecialchars($t['status'] ?? '') ?>"><?= htmlspecialchars($t['status'] ?? '-') ?></span></td>
            <td><span class="sub"><?= htmlspecialchars(format_app_datetime($t['created_at'] ?? null)) ?></span></td>
            <td><button class="action-btn" onclick="event.stopPropagation();viewTxn('<?= htmlspecialchars($t['txn_id']) ?>')">View</button></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</main>

<div class="modal-overlay" id="modal" onclick="closeModal(event)">
  <div class="modal" onclick="event.stopPropagation()">
    <div class="modal-head">
      <div class="modal-title">Transaction Detail</div>
      <button class="modal-close" onclick="closeModal()">x</button>
    </div>
    <div class="modal-body" id="modalBody"></div>
  </div>
</div>

<script>
const allTxns = <?= json_encode($txns_for_js, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

function filterTable(value) {
  const q = (value || '').toLowerCase();
  document.querySelectorAll('#txnTable tbody tr').forEach(row => {
    row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
  });
}

function reportFilteredTxns() {
  const from = document.getElementById('reportFrom')?.value || '';
  const to = document.getElementById('reportTo')?.value || '';
  const gateway = (document.getElementById('reportGateway')?.value || '').toLowerCase();
  const minAmount = parseFloat(document.getElementById('reportMinAmount')?.value || '0') || 0;
  const user = (document.getElementById('reportUser')?.value || '').toLowerCase();
  const status = (document.getElementById('reportStatus')?.value || '').toLowerCase();

  return allTxns.filter(t => {
    const created = String(t.created_at || '').slice(0, 10);
    const hayUser = `${t.customer_name || ''} ${t.customer_email || ''} ${t.customer_phone || ''}`.toLowerCase();
    if (from && created < from) return false;
    if (to && created > to) return false;
    if (gateway && String(t.gateway || '').toLowerCase() !== gateway) return false;
    if (status && String(t.status || '').toLowerCase() !== status) return false;
    if (Number(t.amount || 0) < minAmount) return false;
    if (user && !hayUser.includes(user)) return false;
    return true;
  });
}

function applyReportFilters() {
  const filteredIds = new Set(reportFilteredTxns().map(t => t.txn_id));
  document.querySelectorAll('#reportTable tbody tr').forEach(row => {
    const button = row.querySelector('button[onclick*="viewTxn"]');
    const match = button?.getAttribute('onclick')?.match(/viewTxn\('([^']+)'\)/);
    row.style.display = match && filteredIds.has(match[1]) ? '' : 'none';
  });
  const count = document.getElementById('reportCount');
  if (count) count.textContent = `${filteredIds.size} rows`;
}

function clearReportFilters() {
  ['reportFrom','reportTo','reportGateway','reportMinAmount','reportUser','reportStatus'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.value = '';
  });
  applyReportFilters();
}

function exportReportsCsv() {
  const rows = reportFilteredTxns();
  const headers = ['txn_id','order_id','customer_name','customer_email','customer_phone','amount','currency','gateway','status','ip_address','created_at'];
  const csv = [headers.join(',')].concat(rows.map(t => headers.map(key => {
    const value = key === 'ip_address' ? (t.ip_display || '') : (t[key] ?? '');
    return `"${String(value).replace(/"/g, '""')}"`;
  }).join(','))).join('\n');
  const blob = new Blob([csv], {type: 'text/csv;charset=utf-8;'});
  const link = document.createElement('a');
  link.href = URL.createObjectURL(blob);
  link.download = `fintrack-report-${new Date().toISOString().slice(0, 10)}.csv`;
  document.body.appendChild(link);
  link.click();
  URL.revokeObjectURL(link.href);
  link.remove();
}

async function writeClipboard(textValue, button) {
  const original = button.textContent;
  try {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(textValue);
    } else {
      const input = document.createElement('textarea');
      input.value = textValue;
      input.style.position = 'fixed';
      input.style.opacity = '0';
      document.body.appendChild(input);
      input.select();
      document.execCommand('copy');
      input.remove();
    }
    button.textContent = 'Copied!';
  } catch (error) {
    button.textContent = 'Copy failed';
  }
  setTimeout(() => { button.textContent = original; }, 1600);
}

function copyCode(button) {
  const target = document.getElementById(button.dataset.copyTarget);
  if (target) writeClipboard(target.textContent.trim(), button);
}

function copyText(button) {
  writeClipboard(button.dataset.copyText || '', button);
}

function safe(value) {
  return String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
}

function viewTxn(txnId) {
  const t = allTxns.find(x => x.txn_id === txnId);
  if (!t) return;

  const rows = [
    ['Transaction ID', safe(t.txn_id)],
    ['Order ID', safe(t.order_id)],
    ['Amount', `Rs ${Number(t.amount || 0).toFixed(2)} ${safe(t.currency)}`],
    ['Status', `<span class="badge ${safe(t.status)}">${safe(t.status)}</span>`],
    ['Gateway', `<span class="badge ${safe(t.gateway)}">${safe(t.gateway)}</span>`],
    ['IP Address', safe(t.ip_display || 'unknown')],
    ['Request IP', safe(t.request_ip || 'unknown')],
    ['Gateway Order', safe(t.gateway_order_id || '-')],
    ['Gateway Txn', safe(t.gateway_txn_id || '-')],
    ['Customer', safe(t.customer_name)],
    ['Email', safe(t.customer_email)],
    ['Phone', safe(t.customer_phone)],
    ['Description', safe(t.description)],
    ['Created', safe(t.created_at_display || t.created_at)],
    ['Paid At', safe(t.paid_at_display || '-')],
  ];

  let html = rows.map(([k, v]) => `<div class="detail-row"><div class="detail-key">${k}</div><div class="detail-val">${v}</div></div>`).join('');
  if (t.gateway_response) {
    html += '<div class="section-title">Gateway Response</div>';
    html += `<div class="json-block">${safe(JSON.stringify(t.gateway_response, null, 2))}</div>`;
  }

  document.getElementById('modalBody').innerHTML = html;
  document.getElementById('modal').style.display = 'flex';
}

function closeModal(e) {
  if (!e || e.target === document.getElementById('modal')) {
    document.getElementById('modal').style.display = 'none';
  }
}

document.addEventListener('keydown', e => {
  if (e.key === 'Escape') closeModal();
});
</script>
</body>
</html>
