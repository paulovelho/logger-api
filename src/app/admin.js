const $ = (id) => document.getElementById(id);

// ── Auth ─────────────────────────────────────────────────────────────────────

function getToken() { return localStorage.getItem('admin_token'); }
function setToken(t) { localStorage.setItem('admin_token', t); }
function clearToken() { localStorage.removeItem('admin_token'); }
function getUser() { return localStorage.getItem('admin_user'); }
function setUser(u) { localStorage.setItem('admin_user', u); }

function authHeaders() {
  return { Authorization: 'Bearer ' + getToken() };
}

async function authedFetch(url, options = {}) {
  const res = await fetch(url, {
    ...options,
    headers: { ...authHeaders(), ...(options.headers || {}) },
  });
  if (res.status === 401) {
    showLogin('Session expired. Please sign in again.');
    return null;
  }
  if (res.status === 403) {
    clearToken();
    showLogin('That credential has no admin access (needs "readonly": true).');
    return null;
  }
  return res;
}

// Every response is the Magrathea envelope {success, data}; errors carry data.message.
async function unwrap(res) {
  let json = null;
  try { json = await res.json(); } catch { /* not JSON */ }
  if (!res.ok || !json || json.success !== true) {
    throw new Error((json && json.data && json.data.message) || 'HTTP ' + res.status);
  }
  return json.data;
}

// ── Login / Logout ────────────────────────────────────────────────────────────

function showLogin(message) {
  $('login-error').textContent = message || '';
  $('login-overlay').style.display = 'flex';
  $('app').hidden = true;
}

function showApp() {
  $('login-overlay').style.display = 'none';
  $('app').hidden = false;
  $('logged-as').textContent = getUser();
}

$('login-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const service = $('login-userid').value.trim();
  const secret = $('login-secret').value.trim();
  $('login-error').textContent = '';
  if (!service || !secret) return;

  try {
    const res = await fetch('/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ service, secret }),
    });
    if (!res.ok) { $('login-error').textContent = 'Invalid credentials.'; return; }
    const { token } = await unwrap(res);
    setToken(token);
    setUser(service);
    showApp();
    init();
  } catch {
    $('login-error').textContent = 'Login failed. Is the server reachable?';
  }
});

$('logout-btn').addEventListener('click', () => {
  clearToken();
  stopAutoRefresh('logs');
  stopAutoRefresh('errors');
  showLogin();
});

// ── Tabs ──────────────────────────────────────────────────────────────────────

let activeTab = 'logs';

document.querySelectorAll('.tab').forEach((btn) => {
  btn.addEventListener('click', () => {
    activeTab = btn.dataset.tab;
    document.querySelectorAll('.tab').forEach((b) => b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('.tab-panel').forEach((p) => p.hidden = true);
    $('tab-' + activeTab).hidden = false;
    if (activeTab === 'services') loadServices();
  });
});

// ── Date presets ──────────────────────────────────────────────────────────────

function applyPreset(panel, hours) {
  if (hours === null) {
    $(panel + '-from').value = '';
    $(panel + '-to').value = '';
  } else {
    const now = new Date();
    const from = new Date(now - hours * 3600 * 1000);
    $(panel + '-from').value = toLocalInput(from);
    $(panel + '-to').value = toLocalInput(now);
  }
}

function toLocalInput(date) {
  const d = new Date(date - date.getTimezoneOffset() * 60000);
  return d.toISOString().slice(0, 16);
}

['logs', 'errors'].forEach((panel) => {
  $('tab-' + panel).querySelectorAll('.preset').forEach((btn) => {
    btn.addEventListener('click', () => {
      const hours = btn.dataset.clear !== undefined ? null : Number(btn.dataset.hours);
      applyPreset(panel, hours);
    });
  });
});

// ── Auto-refresh ──────────────────────────────────────────────────────────────

const timers = { logs: null, errors: null };

function stopAutoRefresh(panel) {
  if (timers[panel]) { clearInterval(timers[panel]); timers[panel] = null; }
}

function syncAutoRefresh(panel, loadFn) {
  stopAutoRefresh(panel);
  if ($(panel + '-autorefresh').checked) {
    const secs = Number($(panel + '-autorefresh-interval').value);
    timers[panel] = setInterval(loadFn, secs * 1000);
  }
}

// ── Logs ──────────────────────────────────────────────────────────────────────

let logsSkip = 0;
let logsTotal = 0;

async function loadLogs() {
  const limit = Number($('logs-limit').value);
  const params = new URLSearchParams({ limit, skip: logsSkip });
  if ($('logs-service').value) params.set('service', $('logs-service').value);
  if ($('logs-from').value) params.set('from', new Date($('logs-from').value).toISOString());
  if ($('logs-to').value) params.set('to', new Date($('logs-to').value).toISOString());

  const rows = $('logs-rows');
  try {
    const res = await authedFetch('/admin/logs?' + params);
    if (!res) return;
    const data = await unwrap(res);
    logsTotal = data.total;

    rows.innerHTML = '';
    for (const log of data.logs) {
      rows.appendChild(makeRow(log.timestamp, log.serviceName || log.service, log.data));
    }

    $('logs-summary').textContent = `${logsTotal} total — showing ${data.count}`;
    updatePager('logs', logsSkip, limit, logsTotal);
  } catch (err) {
    rows.innerHTML = '';
    $('logs-summary').innerHTML = `<span class="error">Failed to load: ${err.message}</span>`;
  }
}

$('logs-apply').addEventListener('click', () => { logsSkip = 0; loadLogs(); });
$('logs-refresh').addEventListener('click', loadLogs);
$('logs-prev').addEventListener('click', () => { logsSkip = Math.max(0, logsSkip - Number($('logs-limit').value)); loadLogs(); });
$('logs-next').addEventListener('click', () => { logsSkip += Number($('logs-limit').value); loadLogs(); });
$('logs-autorefresh').addEventListener('change', () => syncAutoRefresh('logs', loadLogs));
$('logs-autorefresh-interval').addEventListener('change', () => syncAutoRefresh('logs', loadLogs));

// ── Errors ────────────────────────────────────────────────────────────────────

let errorsSkip = 0;
let errorsTotal = 0;

async function loadErrors() {
  const limit = Number($('errors-limit').value);
  const params = new URLSearchParams({ limit, skip: errorsSkip });
  if ($('errors-service').value) params.set('service', $('errors-service').value);
  if ($('errors-from').value) params.set('from', new Date($('errors-from').value).toISOString());
  if ($('errors-to').value) params.set('to', new Date($('errors-to').value).toISOString());

  const rows = $('errors-rows');
  try {
    const res = await authedFetch('/admin/errors?' + params);
    if (!res) return;
    const data = await unwrap(res);
    errorsTotal = data.total;

    rows.innerHTML = '';
    for (const err of data.errors) {
      rows.appendChild(makeRow(err.timestamp, err.serviceName || err.service, err.data));
    }

    $('errors-summary').textContent = `${errorsTotal} total — showing ${data.count}`;
    updatePager('errors', errorsSkip, limit, errorsTotal);
  } catch (err) {
    rows.innerHTML = '';
    $('errors-summary').innerHTML = `<span class="error">Failed to load: ${err.message}</span>`;
  }
}

$('errors-apply').addEventListener('click', () => { errorsSkip = 0; loadErrors(); });
$('errors-refresh').addEventListener('click', loadErrors);
$('errors-prev').addEventListener('click', () => { errorsSkip = Math.max(0, errorsSkip - Number($('errors-limit').value)); loadErrors(); });
$('errors-next').addEventListener('click', () => { errorsSkip += Number($('errors-limit').value); loadErrors(); });
$('errors-autorefresh').addEventListener('change', () => syncAutoRefresh('errors', loadErrors));
$('errors-autorefresh-interval').addEventListener('change', () => syncAutoRefresh('errors', loadErrors));

// ── Services ──────────────────────────────────────────────────────────────────

async function loadServices() {
  const grid = $('services-grid');
  try {
    const res = await authedFetch('/admin/services');
    if (!res) return;
    const { services } = await unwrap(res);

    // Populate service filter selects
    ['logs-service', 'errors-service'].forEach((selId) => {
      const sel = $(selId);
      const current = sel.value;
      sel.innerHTML = '<option value="">all</option>';
      for (const s of services) {
        const opt = document.createElement('option');
        opt.value = s.service;
        opt.textContent = s.service;
        if (s.service === current) opt.selected = true;
        sel.appendChild(opt);
      }
    });

    // Render services tab
    grid.innerHTML = '';
    $('services-summary').textContent = `${services.length} service${services.length !== 1 ? 's' : ''}`;

    for (const s of services) {
      const card = document.createElement('div');
      card.className = 'service-card';
      const last = s.lastLog ? new Date(s.lastLog).toLocaleString() : 'never';
      for (const [cls, text] of [
        ['service-name', s.name],
        ['service-stat', `${s.count.toLocaleString()} logs`],
        ['service-last', `last activity ${last}`],
      ]) {
        const div = document.createElement('div');
        div.className = cls;
        div.textContent = text;
        card.appendChild(div);
      }
      card.addEventListener('click', () => {
        $('errors-service').value = s.service;
        errorsSkip = 0;
        document.querySelector('.tab[data-tab="errors"]').click();
        loadErrors();
      });
      grid.appendChild(card);
    }
  } catch (err) {
    grid.innerHTML = '';
    $('services-summary').innerHTML = `<span class="error">Failed to load: ${err.message}</span>`;
  }
}

// ── Shared helpers ────────────────────────────────────────────────────────────

function makeRow(timestamp, service, data) {
  const tr = document.createElement('tr');

  const ts = document.createElement('td');
  ts.className = 'ts';
  ts.textContent = new Date(timestamp).toLocaleString();

  const uid = document.createElement('td');
  uid.className = 'uid';
  uid.textContent = service;

  const dataCell = document.createElement('td');
  const pre = document.createElement('pre');
  const code = document.createElement('code');
  code.className = 'language-json';
  code.textContent = JSON.stringify(data, null, 2);
  pre.appendChild(code);
  dataCell.appendChild(pre);

  if (typeof Prism !== 'undefined') Prism.highlightElement(code);

  tr.append(ts, uid, dataCell);
  tr.addEventListener('click', () => tr.classList.toggle('expanded'));
  return tr;
}

function updatePager(panel, skip, limit, total) {
  const pageNum = Math.floor(skip / limit) + 1;
  const pages = Math.max(1, Math.ceil(total / limit));
  $(panel + '-page').textContent = `page ${pageNum} / ${pages}`;
  $(panel + '-prev').disabled = skip === 0;
  $(panel + '-next').disabled = skip + limit >= total;
}

// ── Tokens ────────────────────────────────────────────────────────────────────

$('token-get').addEventListener('click', async () => {
  const service = $('token-userid').value.trim();
  const secret = $('token-secret').value.trim();
  const errorEl = $('token-error');
  const output = $('token-output');
  errorEl.hidden = true;
  output.value = '';

  if (!service || !secret) {
    errorEl.textContent = 'Enter user id and secret.';
    errorEl.hidden = false;
    return;
  }

  try {
    const res = await fetch('/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ service, secret }),
    });
    if (!res.ok) { errorEl.textContent = 'Invalid credentials.'; errorEl.hidden = false; return; }
    const { token } = await unwrap(res);
    output.value = token;
    output.select();
  } catch {
    errorEl.textContent = 'Request failed. Is the server reachable?';
    errorEl.hidden = false;
  }
});

// ── Token decode ──────────────────────────────────────────────────────────────

$('token-decode-btn').addEventListener('click', async () => {
  const token = $('token-decode-input').value.trim();
  const errorEl = $('token-decode-error');
  const output = $('token-decode-output');
  errorEl.hidden = true;
  output.value = '';

  if (!token) {
    errorEl.textContent = 'Paste a token first.';
    errorEl.hidden = false;
    return;
  }

  try {
    const res = await fetch('/token', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token }),
    });
    const data = await unwrap(res);
    output.value = JSON.stringify(data.decoded, null, 2);
  } catch (err) {
    errorEl.textContent = err.message || 'Invalid token.';
    errorEl.hidden = false;
  }
});

// ── Boot ──────────────────────────────────────────────────────────────────────

function init() {
  loadServices();
  loadLogs();
}

if (getToken()) {
  showApp();
  init();
} else {
  showLogin();
}
