<?php
/**
 * Test Suite for Smart Routing Engine
 */

require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/SmartRouter.php';

// Setup temporary test directory
$testDir = sys_get_temp_dir() . '/fintrack_routing_test_' . uniqid();
mkdir($testDir, 0777, true);
$config['data_dir'] = $testDir;

function test_assert(bool $cond, string $msg): void {
    if (!$cond) {
        throw new Exception("TEST FAILED: " . $msg);
    }
    echo "  [PASS] " . $msg . PHP_EOL;
}

echo "=== 1. Testing Default Configuration ===" . PHP_EOL;
$cfg = SmartRouter::getConfig();
test_assert(isset($cfg['enabled']), "Config has 'enabled' key");
test_assert(isset($cfg['strategy']), "Config has 'strategy' key");
test_assert(is_array($cfg['active_gateways']), "Config has 'active_gateways' array");
test_assert($cfg['circuit_breaker']['failure_threshold'] === 3, "Default failure threshold is 3");

echo "=== 2. Testing Configuration Updates ===" . PHP_EOL;
$saved = SmartRouter::saveConfig([
    'enabled'  => true,
    'strategy' => SmartRouter::STRATEGY_FASTEST,
    'active_gateways' => ['cashfree', 'payu'],
]);
test_assert($saved, "Configuration saved successfully");
$updatedCfg = SmartRouter::getConfig();
test_assert($updatedCfg['enabled'] === true, "Config enabled is true");
test_assert($updatedCfg['active_gateways'] === ['cashfree', 'payu'], "Active gateways updated");

echo "=== 3. Testing Latency Recording & Ranking ===" . PHP_EOL;
// Restore all 3 gateways
SmartRouter::saveConfig([
    'enabled' => true,
    'strategy' => SmartRouter::STRATEGY_FASTEST,
    'active_gateways' => ['razorpay', 'cashfree', 'payu'],
]);

// Create dummy transactions to test latency calculation
$now = time();
$sampleTxns = [
    // Cashfree: very fast (120ms)
    ['txn_id' => 'TXN_CF1', 'gateway' => 'cashfree', 'amount' => 100, 'status' => 'paid', 'gateway_latency_ms' => 120.0, 'created_at' => date('c', $now - 10)],
    ['txn_id' => 'TXN_CF2', 'gateway' => 'cashfree', 'amount' => 200, 'status' => 'paid', 'gateway_latency_ms' => 140.0, 'created_at' => date('c', $now - 20)],
    // Razorpay: medium (320ms)
    ['txn_id' => 'TXN_RP1', 'gateway' => 'razorpay', 'amount' => 100, 'status' => 'paid', 'gateway_latency_ms' => 300.0, 'created_at' => date('c', $now - 10)],
    ['txn_id' => 'TXN_RP2', 'gateway' => 'razorpay', 'amount' => 200, 'status' => 'paid', 'gateway_latency_ms' => 340.0, 'created_at' => date('c', $now - 20)],
    // PayU: slower (550ms)
    ['txn_id' => 'TXN_PU1', 'gateway' => 'payu', 'amount' => 100, 'status' => 'paid', 'gateway_latency_ms' => 500.0, 'created_at' => date('c', $now - 10)],
    ['txn_id' => 'TXN_PU2', 'gateway' => 'payu', 'amount' => 200, 'status' => 'paid', 'gateway_latency_ms' => 600.0, 'created_at' => date('c', $now - 20)],
];
foreach ($sampleTxns as $t) {
    txn_save($t);
}

$ranked = SmartRouter::rankGateways(SmartRouter::STRATEGY_FASTEST);
test_assert($ranked[0]['gateway'] === 'cashfree', "Cashfree is ranked #1 for lowest latency (" . $ranked[0]['avg_latency_ms'] . "ms)");
test_assert($ranked[1]['gateway'] === 'razorpay', "Razorpay is ranked #2 (" . $ranked[1]['avg_latency_ms'] . "ms)");
test_assert($ranked[2]['gateway'] === 'payu', "PayU is ranked #3 (" . $ranked[2]['avg_latency_ms'] . "ms)");

echo "=== 4. Testing Circuit Breaker Downtime Tripping ===" . PHP_EOL;
// Simulate Cashfree failing 3 consecutive times
SmartRouter::recordFailure('cashfree', 'Cashfree 504 Gateway Timeout', 3500);
SmartRouter::recordFailure('cashfree', 'Cashfree 504 Gateway Timeout', 3500);
SmartRouter::recordFailure('cashfree', 'Cashfree 500 Server Error', 2100);

$metricsAfterFailure = SmartRouter::getGatewayMetrics();
test_assert($metricsAfterFailure['cashfree']['circuit_status'] === 'tripped', "Cashfree circuit status is 'tripped'");
test_assert($metricsAfterFailure['cashfree']['consecutive_failures'] === 3, "Cashfree has 3 consecutive failures recorded");

// Ranking should now skip Cashfree and select Razorpay!
$rankedAfterTrip = SmartRouter::rankGateways(SmartRouter::STRATEGY_FASTEST);
test_assert($rankedAfterTrip[0]['gateway'] === 'razorpay', "Smart Router SKIPS failing Cashfree and picks Razorpay as #1!");
test_assert($rankedAfterTrip[count($rankedAfterTrip) - 1]['gateway'] === 'cashfree', "Tripped Cashfree placed at the bottom as last-resort fallback");

echo "=== 5. Testing Circuit Breaker Auto-Recovery on Success ===" . PHP_EOL;
SmartRouter::recordSuccess('cashfree', 115.0);
$metricsRecovered = SmartRouter::getGatewayMetrics();
test_assert($metricsRecovered['cashfree']['circuit_status'] === 'healthy', "Cashfree circuit recovered to 'healthy' after success");
test_assert($metricsRecovered['cashfree']['consecutive_failures'] === 0, "Cashfree consecutive failures reset to 0");

$rankedRecovered = SmartRouter::rankGateways(SmartRouter::STRATEGY_FASTEST);
test_assert($rankedRecovered[0]['gateway'] === 'cashfree', "Cashfree re-takes #1 spot after recovery");

echo "=== 6. Testing Strategy: Success Rate ===" . PHP_EOL;
// Add failures for Cashfree payments
$failTxn = ['txn_id' => 'TXN_CF_F1', 'gateway' => 'cashfree', 'amount' => 100, 'status' => 'failed', 'created_at' => date('c')];
txn_save($failTxn);
$failTxn2 = ['txn_id' => 'TXN_CF_F2', 'gateway' => 'cashfree', 'amount' => 100, 'status' => 'failed', 'created_at' => date('c')];
txn_save($failTxn2);

$rankedSR = SmartRouter::rankGateways(SmartRouter::STRATEGY_SUCCESS);
test_assert($rankedSR[0]['gateway'] === 'razorpay', "Razorpay ranked #1 under Success Rate strategy (100% vs degraded Cashfree)");

echo "=== 7. Testing Strategy: Smart Composite Score ===" . PHP_EOL;
$rankedComposite = SmartRouter::rankGateways(SmartRouter::STRATEGY_COMPOSITE);
test_assert(!empty($rankedComposite[0]['score']), "Composite score calculated: " . $rankedComposite[0]['score']);

echo "=== 8. Testing resolveRouting() ===" . PHP_EOL;
$decisionAuto = SmartRouter::resolveRouting('auto');
test_assert($decisionAuto['auto_routed'] === true, "Auto-routed flag is true for 'auto'");
test_assert(!empty($decisionAuto['primary_gateway']), "Primary gateway selected: " . $decisionAuto['primary_gateway']);

// Test explicit override false
SmartRouter::saveConfig(['enabled' => true, 'override_explicit' => false]);
$decisionExplicit = SmartRouter::resolveRouting('payu');
test_assert($decisionExplicit['auto_routed'] === false, "Auto-routed is false when explicit gateway specified and override_explicit=false");
test_assert($decisionExplicit['primary_gateway'] === 'payu', "Selected explicit gateway is payu");

// Clean up test dir
$files = glob($testDir . '/*');
foreach ($files as $f) @unlink($f);
@rmdir($testDir);

echo PHP_EOL . ">>> ALL SMART ROUTING TESTS PASSED PERFECTLY! <<<" . PHP_EOL;
