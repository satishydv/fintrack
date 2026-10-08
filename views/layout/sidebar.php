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
      <a class="nav-item <?= $section === 'routing' ? 'active' : '' ?>" href="?section=routing"><span class="nav-ico"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.93 4.93l2.12 2.12M16.95 16.95l2.12 2.12M4.93 19.07l2.12-2.12M16.95 7.05l2.12-2.12"/></svg></span>Smart Routing <span class="badge <?= $routing_config['enabled'] ? 'normal' : 'watch' ?>" style="margin-left:auto;font-size:9px;padding:1px 5px"><?= $routing_config['enabled'] ? 'ON' : 'OFF' ?></span></a>
      <a class="nav-item <?= $section === 'reports' ? 'active' : '' ?>" href="?section=reports"><span class="nav-ico"><svg viewBox="0 0 24 24"><path d="M7 4h8l3 3v13H7z"/><path d="M15 4v4h4"/><path d="M9.5 13h5"/><path d="M9.5 17h4"/></svg></span>Reports</a>
      <a class="nav-item <?= $section === 'help' ? 'active' : '' ?>" href="?section=help"><span class="nav-ico"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M9.7 9a2.5 2.5 0 0 1 4.8 1c0 1.7-2.5 2-2.5 3.8"/><path d="M12 17.5h.01"/></svg></span>Help & Integration</a>
    </nav>
  </div>
  <div class="support">
    <a class="muted-link" href="#" style="opacity:.5;pointer-events:none">Settings</a>
    <a class="muted-link" href="?logout=1">Logout</a>
  </div>
</aside>
