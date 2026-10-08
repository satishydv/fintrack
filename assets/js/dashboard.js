// FinTrack Admin Dashboard JavaScript

const allTxns = window.allTxns || [];

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

  return (window.allTxns || allTxns).filter(t => {
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
  const list = window.allTxns || allTxns;
  const t = list.find(x => x.txn_id === txnId);
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

  if (t.routing) {
    rows.push(['Smart Routing', t.routing.auto_routed
      ? `<span class="badge paid">Auto-Routed (${safe(t.routing.strategy || 'fastest')})</span>`
      : `<span class="badge pending">Direct / Manual</span>`]);
    if (t.routing.selection_reason) {
      rows.push(['Routing Reason', safe(t.routing.selection_reason)]);
    }
    if (t.gateway_latency_ms) {
      rows.push(['Gateway Latency', `${Number(t.gateway_latency_ms).toFixed(1)} ms`]);
    }
    if (t.routing.failovers && t.routing.failovers.length > 0) {
      rows.push(['Failover Cascades', `<span class="badge high">${t.routing.failovers.length} Failover(s) Handled</span>`]);
    }
  } else if (t.gateway_latency_ms) {
    rows.push(['Gateway Latency', `${Number(t.gateway_latency_ms).toFixed(1)} ms`]);
  }

  let html = rows.map(([k, v]) => `<div class="detail-row"><div class="detail-key">${k}</div><div class="detail-val">${v}</div></div>`).join('');
  if (t.routing && t.routing.failovers && t.routing.failovers.length > 0) {
    html += '<div class="section-title">Zero-Downtime Cascades</div>';
    html += `<div class="json-block">${safe(JSON.stringify(t.routing.failovers, null, 2))}</div>`;
  }
  if (t.gateway_response) {
    html += '<div class="section-title">Gateway Response</div>';
    html += `<div class="json-block">${safe(JSON.stringify(t.gateway_response, null, 2))}</div>`;
  }

  document.getElementById('modalBody').innerHTML = html;
  document.getElementById('modal').style.display = 'flex';
}

function showToast(msg) {
  let toast = document.getElementById('appToast');
  if (!toast) {
    toast = document.createElement('div');
    toast.id = 'appToast';
    toast.className = 'toast';
    document.body.appendChild(toast);
  }
  toast.innerHTML = '<span>✓</span> ' + safe(msg);
  toast.style.display = 'flex';
  setTimeout(() => { toast.style.display = 'none'; }, 3200);
}

function selectStrategyOption(val, el) {
  document.querySelectorAll('.strategy-option').forEach(card => card.classList.remove('selected'));
  el.classList.add('selected');
  const radio = el.querySelector('input[type="radio"]');
  if (radio) radio.checked = true;
}

async function quickToggleAutoRouting(enabled) {
  const statusText = document.getElementById('masterToggleStatusText');
  if (statusText) {
    statusText.textContent = enabled ? 'Optimizing traffic dynamically...' : 'Switching to manual...';
  }
  try {
    const res = await fetch('api/routing.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ enabled: enabled })
    });
    const data = await res.json();
    if (data.success) {
      showToast(enabled ? 'Smart Auto-Routing ENABLED' : 'Smart Auto-Routing PAUSED');
      if (statusText) {
        statusText.textContent = enabled ? 'Optimizing traffic dynamically' : 'Using explicit/manual gateway';
      }
      setTimeout(() => location.reload(), 700);
    } else {
      alert('Error toggling routing: ' + (data.error || 'Unknown error'));
    }
  } catch (err) {
    alert('Failed to connect to routing API: ' + err.message);
  }
}

async function saveRoutingSettings() {
  const strategyRadio = document.querySelector('input[name="routingStrategy"]:checked');
  const strategy = strategyRadio ? strategyRadio.value : 'fastest';

  const activeGateways = [];
  document.querySelectorAll('.gateway-pool-check:checked').forEach(cb => {
    activeGateways.push(cb.value);
  });

  if (activeGateways.length === 0) {
    alert('Please select at least one active gateway in the pool.');
    return;
  }

  const threshold = parseInt(document.getElementById('cbFailureThreshold')?.value || '3', 10);
  const cooldown = parseInt(document.getElementById('cbCooldownSeconds')?.value || '300', 10);
  const autoCascade = document.getElementById('cbAutoCascade')?.checked ?? true;
  const overrideExplicit = document.getElementById('cbOverrideExplicit')?.checked ?? false;
  const masterToggle = document.getElementById('masterRoutingToggle')?.checked ?? true;

  try {
    const res = await fetch('api/routing.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        enabled: masterToggle,
        strategy: strategy,
        active_gateways: activeGateways,
        override_explicit: overrideExplicit,
        auto_cascade: autoCascade,
        circuit_breaker: {
          enabled: true,
          failure_threshold: threshold,
          cooldown_seconds: cooldown
        }
      })
    });
    const data = await res.json();
    if (data.success) {
      showToast('Smart Routing configuration saved successfully!');
      setTimeout(() => location.reload(), 800);
    } else {
      alert('Failed to save settings: ' + (data.error || 'Unknown error'));
    }
  } catch (err) {
    alert('Network error saving settings: ' + err.message);
  }
}

async function resetCircuitBreakers() {
  if (!confirm('Are you sure you want to reset all quarantined gateway circuits?')) return;
  try {
    const res = await fetch('api/routing.php?action=reset_circuit', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({})
    });
    const data = await res.json();
    if (data.success) {
      showToast('All circuit breakers have been reset!');
      setTimeout(() => location.reload(), 700);
    } else {
      alert('Failed to reset circuits: ' + (data.error || 'Unknown error'));
    }
  } catch (err) {
    alert('Error resetting circuits: ' + err.message);
  }
}

async function runRouteSimulation() {
  const gw = document.getElementById('simGatewaySelect')?.value || 'auto';
  const amt = document.getElementById('simAmount')?.value || '500';
  const out = document.getElementById('simOutput');
  if (!out) return;

  out.style.display = 'block';
  out.textContent = 'Simulating Smart Route decision...';

  try {
    const res = await fetch('api/routing.php?action=simulate', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ gateway: gw, amount: amt })
    });
    const data = await res.json();
    if (data.success && data.decision) {
      const d = data.decision;
      let text = `=== SMART ROUTING SIMULATION RESULT ===\n`;
      text += `Status: ${d.auto_routed ? 'AUTOMATICALLY ROUTED' : 'EXPLICIT DIRECT'}\n`;
      text += `Selected Winner: ${d.primary_gateway.toUpperCase()}\n`;
      text += `Active Strategy: ${d.strategy}\n`;
      text += `Selection Reason: ${d.selection_reason}\n`;
      text += `Failover Cascade Order: ${d.ranked_list.join(' -> ')}\n\n`;
      text += `--- CANDIDATE SCORING BREAKDOWN ---\n`;
      (d.ranked_candidates || []).forEach((c, i) => {
        text += `#${i+1} ${c.gateway.toUpperCase()}: Score ${c.score} | Status: ${c.circuit_status} | Latency: ${c.avg_latency_ms ? c.avg_latency_ms + 'ms' : 'N/A'}\n   Reason: ${c.reason}\n`;
      });
      out.textContent = text;
    } else {
      out.textContent = 'Simulation failed: ' + (data.error || 'Unknown response');
    }
  } catch (err) {
    out.textContent = 'Simulation request failed: ' + err.message;
  }
}

function closeModal(e) {
  if (!e || e.target === document.getElementById('modal')) {
    document.getElementById('modal').style.display = 'none';
  }
}

document.addEventListener('keydown', e => {
  if (e.key === 'Escape') closeModal();
});
