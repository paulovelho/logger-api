const $ = (id) => document.getElementById(id);
let skip = 0;
let total = 0;

async function loadServices() {
  const res = await fetch('/admin/services');
  const { services } = await res.json();
  const sel = $('userId');
  for (const s of services) {
    const opt = document.createElement('option');
    opt.value = s.userId;
    opt.textContent = `${s.userId} (${s.count})`;
    sel.appendChild(opt);
  }
}

async function loadLogs() {
  const limit = Number($('limit').value);
  const params = new URLSearchParams({ limit, skip });
  if ($('userId').value) params.set('userId', $('userId').value);
  if ($('from').value) params.set('from', new Date($('from').value).toISOString());
  if ($('to').value) params.set('to', new Date($('to').value).toISOString());

  const rows = $('rows');
  try {
    const res = await fetch('/admin/logs?' + params);
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const data = await res.json();
    total = data.total;

    rows.innerHTML = '';
    for (const log of data.logs) {
      const tr = document.createElement('tr');
      const ts = document.createElement('td');
      ts.className = 'ts';
      ts.textContent = new Date(log.timestamp).toLocaleString();
      const uid = document.createElement('td');
      uid.className = 'uid';
      uid.textContent = log.userId;
      const dataCell = document.createElement('td');
      const pre = document.createElement('pre');
      pre.textContent = JSON.stringify(log.data, null, 2);
      dataCell.appendChild(pre);
      tr.append(ts, uid, dataCell);
      tr.addEventListener('click', () => tr.classList.toggle('expanded'));
      rows.appendChild(tr);
    }

    $('summary').textContent = `${total} logs total — showing ${data.count}`;
    const pageNum = Math.floor(skip / limit) + 1;
    const pages = Math.max(1, Math.ceil(total / limit));
    $('page').textContent = `page ${pageNum} / ${pages}`;
    $('prev').disabled = skip === 0;
    $('next').disabled = skip + limit >= total;
  } catch (err) {
    rows.innerHTML = '';
    $('summary').innerHTML = `<span class="error">Failed to load logs: ${err.message}</span>`;
  }
}

$('apply').addEventListener('click', () => { skip = 0; loadLogs(); });
$('refresh').addEventListener('click', loadLogs);
$('prev').addEventListener('click', () => { skip = Math.max(0, skip - Number($('limit').value)); loadLogs(); });
$('next').addEventListener('click', () => { skip += Number($('limit').value); loadLogs(); });

loadServices();
loadLogs();
