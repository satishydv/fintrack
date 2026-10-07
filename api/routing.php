<?php
/**
 * /api/routing.php
 * Smart Routing Administration and Status API
 *
 * Supports:
 * - GET: Fetch live routing configuration, gateway metrics, and real-time rankings
 * - POST: Update routing configuration (master toggle, strategy, gateway pool, circuit breaker)
 * - POST ?action=simulate: Simulate gateway resolution with step-by-step reasoning
 * - POST ?action=reset_circuit: Manually reset circuit breaker trips
 *
 * Authentication:
 * - Admin Session ($_SESSION['dashboard_auth']) OR
 * - API Key header (X-Api-Key)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Api-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/SmartRouter.php';

// Authentication: Dashboard session OR API key
$isSessionAuth = !empty($_SESSION['dashboard_auth']);
if (!$isSessionAuth) {
    $headers = getallheaders();
    $key = $headers['X-Api-Key'] ?? $headers['x-api-key'] ?? ($_SERVER['HTTP_X_API_KEY'] ?? '');
    if (empty($key) || !hash_equals($config['api_key'] ?? '', $key)) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error'   => 'Unauthorized: Dashboard login or valid X-Api-Key required',
        ]);
        exit;
    }
}

$action = strtolower(trim((string)($_GET['action'] ?? '')));

// ── GET: Fetch Current State & Metrics ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $routingConfig = SmartRouter::getConfig();
    $metrics = SmartRouter::getGatewayMetrics();
    $ranking = SmartRouter::rankGateways();

    json_response([
        'success'   => true,
        'enabled'   => (bool)($routingConfig['enabled'] ?? true),
        'strategy'  => $routingConfig['strategy'] ?? SmartRouter::STRATEGY_FASTEST,
        'config'    => $routingConfig,
        'metrics'   => $metrics,
        'ranking'   => $ranking,
        'tripped_count' => count(array_filter($metrics, fn($m) => $m['circuit_status'] === 'tripped')),
    ]);
}

// ── POST: Actions & Configuration Updates ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true) ?: $_POST;

    // Action 1: Reset Circuit Breakers
    if ($action === 'reset_circuit') {
        $targetGateway = $body['gateway'] ?? $_GET['gateway'] ?? null;
        SmartRouter::resetCircuitBreaker($targetGateway ?: null);

        json_response([
            'success' => true,
            'message' => $targetGateway ? "Circuit breaker reset for {$targetGateway}" : "All circuit breakers reset",
            'metrics' => SmartRouter::getGatewayMetrics(),
            'ranking' => SmartRouter::rankGateways(),
        ]);
    }

    // Action 2: Simulate Route
    if ($action === 'simulate') {
        $simRequestedGateway = $body['gateway'] ?? null;
        $decision = SmartRouter::resolveRouting($simRequestedGateway);

        json_response([
            'success'  => true,
            'decision' => $decision,
            'metrics'  => SmartRouter::getGatewayMetrics(),
        ]);
    }

    // Action 3: Save Configuration Updates
    $current = SmartRouter::getConfig();

    if (isset($body['enabled'])) {
        $current['enabled'] = filter_var($body['enabled'], FILTER_VALIDATE_BOOLEAN);
    }

    if (!empty($body['strategy']) && in_array($body['strategy'], [
        SmartRouter::STRATEGY_FASTEST,
        SmartRouter::STRATEGY_SUCCESS,
        SmartRouter::STRATEGY_COMPOSITE,
        SmartRouter::STRATEGY_WATERFALL,
    ], true)) {
        $current['strategy'] = $body['strategy'];
    }

    if (isset($body['active_gateways']) && is_array($body['active_gateways'])) {
        $sanitized = array_values(array_intersect(
            array_map('strtolower', array_map('trim', $body['active_gateways'])),
            SmartRouter::ALL_GATEWAYS
        ));
        if (!empty($sanitized)) {
            $current['active_gateways'] = $sanitized;
        }
    }

    if (isset($body['waterfall_priority']) && is_array($body['waterfall_priority'])) {
        $sanitized = array_values(array_intersect(
            array_map('strtolower', array_map('trim', $body['waterfall_priority'])),
            SmartRouter::ALL_GATEWAYS
        ));
        if (!empty($sanitized)) {
            $current['waterfall_priority'] = $sanitized;
        }
    }

    if (isset($body['override_explicit'])) {
        $current['override_explicit'] = filter_var($body['override_explicit'], FILTER_VALIDATE_BOOLEAN);
    }

    if (isset($body['auto_cascade'])) {
        $current['auto_cascade'] = filter_var($body['auto_cascade'], FILTER_VALIDATE_BOOLEAN);
    }

    if (!empty($body['fallback_gateway']) && in_array(strtolower($body['fallback_gateway']), SmartRouter::ALL_GATEWAYS, true)) {
        $current['fallback_gateway'] = strtolower($body['fallback_gateway']);
    }

    if (isset($body['circuit_breaker']) && is_array($body['circuit_breaker'])) {
        if (isset($body['circuit_breaker']['enabled'])) {
            $current['circuit_breaker']['enabled'] = filter_var($body['circuit_breaker']['enabled'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($body['circuit_breaker']['failure_threshold'])) {
            $th = (int)$body['circuit_breaker']['failure_threshold'];
            if ($th >= 1) $current['circuit_breaker']['failure_threshold'] = $th;
        }
        if (isset($body['circuit_breaker']['cooldown_seconds'])) {
            $cd = (int)$body['circuit_breaker']['cooldown_seconds'];
            if ($cd >= 10) $current['circuit_breaker']['cooldown_seconds'] = $cd;
        }
    }

    $saved = SmartRouter::saveConfig($current);
    if (!$saved) {
        json_error('Failed to save routing configuration file', 500);
    }

    json_response([
        'success'   => true,
        'message'   => 'Smart routing settings updated successfully',
        'config'    => SmartRouter::getConfig(),
        'metrics'   => SmartRouter::getGatewayMetrics(),
        'ranking'   => SmartRouter::rankGateways(),
    ]);
}

json_error('Method not allowed', 405);
