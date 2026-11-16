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
    cache: 'no-store',
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
  Object.keys(panels).forEach((name) => stopAutoRefresh(name));
  showLogin();
});

// ── Lists (logs, errors) ──────────────────────────────────────────────────────

// One entry per list tab; its name is also its /admin/<name> route and the response's list key.
// hours: the rolling date preset (1h / 24h / 7d), null when from/to are set by hand.
// seq: bumped on every load, so a slower, older response can't overwrite a newer one.
const panels = {
  logs:   { skip: 0, hours: null, seq: 0, timer: null },
  errors: { skip: 0, hours: null, seq: 0, timer: null },
};

// ── Service filter (top bar; applies to every list) ───────────────────────────

let service = localStorage.getItem('admin_service') || '';

function setService(value) {
  service = value;
  if (service) localStorage.setItem('admin_service', service);
  else localStorage.removeItem('admin_service');
  $('service').value = service;
  $('service-filter').classList.toggle('active', service !== '');
  for (const p of Object.values(panels)) p.skip = 0;
  document.querySelectorAll('.service-card').forEach((c) => c.classList.toggle('selected', c.dataset.service === service));
}

// The selected service stays listed even when /admin/services doesn't return it (inactive, or not loaded yet)
function renderServiceOptions(services) {
  const options = services.map((s) => [s.service, s.name || s.service]);
  if (service && !options.some(([id]) => id === service)) options.push([service, service]);
  const sel = $('service');
  sel.innerHTML = '<option value="">all</option>';
  for (const [id, name] of options) {
    const opt = document.createElement('option');
    opt.value = id;
    opt.textContent = name;
    sel.appendChild(opt);
  }
  sel.value = service;
}

$('service').addEventListener('change', () => {
  setService($('service').value);
  if (panels[activeTab]) loadPanel(activeTab);
});

// ── Tabs ──────────────────────────────────────────────────────────────────────

let activeTab = 'logs';

// Lists reload on every visit, so they are never older than the moment they were opened
function showTab(tab) {
  activeTab = tab;
  document.querySelectorAll('.tab').forEach((b) => b.classList.toggle('active', b.dataset.tab === tab));
  document.querySelectorAll('.tab-panel').forEach((p) => p.hidden = p.id !== 'tab-' + tab);
  if (tab === 'services') loadServices();
  if (panels[tab]) loadPanel(tab);
}

document.querySelectorAll('.tab').forEach((btn) => {
  btn.addEventListener('click', () => showTab(btn.dataset.tab));
});

// ── Date presets ──────────────────────────────────────────────────────────────

// 1h / 24h / 7d are rolling: every load (refresh, auto-refresh, paging) recomputes `from` and leaves
// `to` open, so new entries show up. A `to` fixed at the click froze the list at that moment.
// Editing from/to by hand turns the preset off; "all" clears both.

function toLocalInput(date) {
  const d = new Date(date - date.getTimezoneOffset() * 60000);
  return d.toISOString().slice(0, 16);
}

function markPreset(name, hours) {
  panels[name].hours = hours;
  $('tab-' + name).querySelectorAll('.preset').forEach((b) =>
    b.classList.toggle('active', hours !== null && Number(b.dataset.hours) === hours));
}

Object.keys(panels).forEach((name) => {
  $('tab-' + name).querySelectorAll('.preset').forEach((btn) => {
    btn.addEventListener('click', () => {
      markPreset(name, btn.dataset.clear !== undefined ? null : Number(btn.dataset.hours));
      $(name + '-from').value = '';
      $(name + '-to').value = '';
      panels[name].skip = 0;
      loadPanel(name);
    });
  });
  for (const id of [name + '-from', name + '-to']) {
    $(id).addEventListener('input', () => markPreset(name, null));
  }
});

// ── Auto-refresh ──────────────────────────────────────────────────────────────

function stopAutoRefresh(name) {
  const p = panels[name];
  if (p.timer) { clearInterval(p.timer); p.timer = null; }
}

function syncAutoRefresh(name) {
  stopAutoRefresh(name);
  if ($(name + '-autorefresh').checked) {
    const secs = Number($(name + '-autorefresh-interval').value);
    panels[name].timer = setInterval(() => loadPanel(name), secs * 1000);
  }
}

// ── Loading a list ────────────────────────────────────────────────────────────

async function loadPanel(name) {
  const p = panels[name];
  const seq = ++p.seq;
  if (p.hours !== null) {
    $(name + '-from').value = toLocalInput(new Date(Date.now() - p.hours * 3600 * 1000));
    $(name + '-to').value = '';
  }

  const limit = Number($(name + '-limit').value);
  const params = new URLSearchParams({ limit, skip: p.skip });
  if (service) params.set('service', service);
  if ($(name + '-from').value) params.set('from', new Date($(name + '-from').value).toISOString());
  if ($(name + '-to').value) params.set('to', new Date($(name + '-to').value).toISOString());

  const rows = $(name + '-rows');
  try {
    const res = await authedFetch('/admin/' + name + '?' + params);
    if (!res) return;
    const data = await unwrap(res);
    if (seq !== p.seq) return;

    rows.innerHTML = '';
    for (const entry of data[name]) {
      rows.appendChild(makeRow(entry.occurredAt || entry.timestamp, entry.serviceName || entry.service, entry.data));
    }

    $(name + '-summary').textContent = `${data.total} total — showing ${data.count}`;
    updatePager(name, p.skip, limit, data.total);
  } catch (err) {
    if (seq !== p.seq) return;
    rows.innerHTML = '';
    showFailure($(name + '-summary'), err);
  }
}

Object.keys(panels).forEach((name) => {
  const p = panels[name];
  const limit = () => Number($(name + '-limit').value);
  $(name + '-apply').addEventListener('click', () => { p.skip = 0; loadPanel(name); });
  $(name + '-refresh').addEventListener('click', () => loadPanel(name));
  $(name + '-prev').addEventListener('click', () => { p.skip = Math.max(0, p.skip - limit()); loadPanel(name); });
  $(name + '-next').addEventListener('click', () => { p.skip += limit(); loadPanel(name); });
  $(name + '-autorefresh').addEventListener('change', () => syncAutoRefresh(name));
  $(name + '-autorefresh-interval').addEventListener('change', () => syncAutoRefresh(name));
});

// ── Services ──────────────────────────────────────────────────────────────────

async function loadServices() {
  const grid = $('services-grid');
  try {
    const res = await authedFetch('/admin/services');
    if (!res) return;
    const { services } = await unwrap(res);

    renderServiceOptions(services);

    grid.innerHTML = '';
    $('services-summary').textContent = `${services.length} service${services.length !== 1 ? 's' : ''}`;

    for (const s of services) {
      const card = document.createElement('div');
      card.className = 'service-card';
      card.dataset.service = s.service;
      card.classList.toggle('selected', s.service === service);
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
      // Selects the service in the top bar (every list) and opens its logs
      card.addEventListener('click', () => {
        setService(s.service);
        showTab('logs');
      });
      grid.appendChild(card);
    }
  } catch (err) {
    grid.innerHTML = '';
    showFailure($('services-summary'), err);
  }
}

// ── Shared helpers ────────────────────────────────────────────────────────────

// The message can come from the server: text, never HTML
function showFailure(el, err) {
  const span = document.createElement('span');
  span.className = 'error';
  span.textContent = 'Failed to load: ' + err.message;
  el.replaceChildren(span);
}

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
  renderServiceOptions([]);   // the remembered service shows before /admin/services answers
  setService(service);
  loadServices();
  if (panels[activeTab]) loadPanel(activeTab);
}

if (getToken()) {
  showApp();
  init();
} else {
  showLogin();
}
