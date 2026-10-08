<?php
session_start();
if (empty($_SESSION['dashboard_auth'])) {
    header('Location: login.php');
    exit;
}

if ($_GET['logout'] ?? false) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$section = $_GET['section'] ?? 'dashboard';
if (!in_array($section, ['dashboard', 'risk', 'transactions', 'gateway', 'routing', 'reports', 'help', 'components'], true)) {
    $section = 'dashboard';
}

// Load data orchestration & metrics
require_once __DIR__ . '/lib/dashboard_data.php';

// Render Layout & Active Section
require __DIR__ . '/views/layout/header.php';
require __DIR__ . '/views/layout/sidebar.php';
require __DIR__ . '/views/layout/topbar.php';

// Dynamic Section Component
require __DIR__ . "/views/sections/{$section}.php";

// Modal & Script Footer
require __DIR__ . '/views/layout/footer.php';
