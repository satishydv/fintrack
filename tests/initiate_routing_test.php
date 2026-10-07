<?php
/**
 * Test for initiate.php integration with Smart Routing
 */

require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/SmartRouter.php';

$testDir = sys_get_temp_dir() . '/fintrack_initiate_test_' . uniqid();
mkdir($testDir, 0777, true);
$config['data_dir'] = $testDir;

// Enable smart routing
SmartRouter::saveConfig([
    'enabled' => true,
    'strategy' => SmartRouter::STRATEGY_FASTEST,
    'active_gateways' => ['razorpay', 'cashfree', 'payu'],
    'auto_cascade' => true,
]);

// Populate latency metrics
txn_save(['txn_id' => 'TXN_TEST_CF', 'gateway' => 'cashfree', 'amount' => 50, 'status' => 'paid', 'gateway_latency_ms' => 110.0, 'created_at' => date('c')]);
txn_save(['txn_id' => 'TXN_TEST_RP', 'gateway' => 'razorpay', 'amount' => 50, 'status' => 'paid', 'gateway_latency_ms' => 310.0, 'created_at' => date('c')]);

$decision = SmartRouter::resolveRouting('auto');
if ($decision['primary_gateway'] !== 'cashfree') {
    throw new Exception("Expected primary_gateway to be cashfree, got " . $decision['primary_gateway']);
}
echo "[PASS] Smart Routing resolved fastest gateway: " . $decision['primary_gateway'] . PHP_EOL;

// Clean up
$files = glob($testDir . '/*');
foreach ($files as $f) @unlink($f);
@rmdir($testDir);
echo "Initiate routing integration verified!" . PHP_EOL;
