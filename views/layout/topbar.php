<main class="main">
  <div class="topbar">
    <div class="page-title"><?= htmlspecialchars($section_titles[$section] ?? 'Dashboard') ?></div>
    <div class="tools">
      <?php if (in_array($section, ['dashboard', 'transactions', 'reports'], true)): ?>
        <input class="search" type="text" placeholder="Search transactions..." id="globalSearch" onkeyup="filterTable(this.value)">
      <?php elseif ($section === 'gateway'): ?>
        <span class="badge normal">Live transaction data</span>
      <?php elseif ($section === 'routing'): ?>
        <span class="badge <?= $routing_config['enabled'] ? 'normal' : 'watch' ?>"><?= $routing_config['enabled'] ? 'Smart Routing Active' : 'Routing Standby' ?></span>
      <?php else: ?>
        <span class="badge watch">API v1</span>
      <?php endif; ?>
      <button class="icon-btn" onclick="location.reload()">R</button>
      <button class="pill-btn"><?= htmlspecialchars(date('d M Y')) ?></button>
    </div>
  </div>
