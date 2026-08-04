<?php
/**
 * Extension Status - markup, styling and browser code.
 *
 * Required by extensionstatus.php at the very end of the request, and reads
 * these from the caller's scope:
 *   $es_rows            row data (see es_build_rows)
 *   $es_actions_public  brand -> action -> {label, confirm, danger}
 *   $es_csrf            CSRF token for the NOTIFY endpoint
 *   $es_json_flags      json_encode flags safe for embedding in <script>
 *   $es_refresh_seconds, $es_refresh_default_on, $es_showdebug, $astman
 *
 * The table body is built in JS with textContent rather than innerHTML: the
 * User-Agent is supplied by whatever registers, so it is attacker-controlled.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Extension Status</title>
<style>
  :root {
    --accent: #4CAF50;
    --border: #ddd;
    --hover: #f5f5f5;
    --muted: #777;
    --danger: #c62828;
  }
  body {
    margin: 0;
    padding: 16px;
    font: 14px/1.4 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    color: #222;
  }
  .toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
  }
  .toolbar input[type="search"] {
    padding: 7px 10px;
    border: 1px solid var(--border);
    border-radius: 4px;
    min-width: 240px;
    font-size: 14px;
  }
  .toolbar label { display: flex; align-items: center; gap: 6px; }
  .spacer { flex: 1; }
  .meta { color: var(--muted); font-size: 13px; }

  .wrap { overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; }
  th {
    height: 44px;
    text-align: left;
    background-color: var(--accent);
    color: #000;
    padding: 8px 12px;
    white-space: nowrap;
  }
  th.sortable { cursor: pointer; user-select: none; }
  th.sortable:hover { filter: brightness(1.07); }
  th .arrow { opacity: .35; font-size: 11px; }
  th.sorted .arrow { opacity: 1; }
  td {
    padding: 10px 12px;
    border-bottom: 1px solid var(--border);
    vertical-align: top;
  }
  tbody tr:hover { background-color: var(--hover); }

  .pill {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 12px;
    background: #eee;
  }
  .pill.udp { background: #e3f2fd; }
  .pill.tcp { background: #e8f5e9; }
  .pill.tls { background: #ede7f6; }
  .pill.ws, .pill.wss { background: #fff8e1; }
  .status-reachable { color: #2e7d32; }
  .status-unreachable, .status-unknown { color: var(--danger); }

  .ips { white-space: nowrap; font-size: 13px; }
  .ips b { font-weight: 600; }
  .ips .none { color: var(--muted); }

  .actions { white-space: nowrap; }
  .actions button {
    font-size: 12px;
    padding: 5px 9px;
    margin-right: 4px;
    border: 1px solid #bbb;
    border-radius: 4px;
    background: #fafafa;
    cursor: pointer;
  }
  .actions button:hover:not(:disabled) { background: #f0f0f0; }
  .actions button:disabled { opacity: .5; cursor: default; }
  .actions button.danger { border-color: #e0b4b4; color: var(--danger); }
  .actions .dash { color: var(--muted); }

  #toast {
    position: fixed;
    right: 16px;
    bottom: 16px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    z-index: 10;
  }
  #toast div {
    padding: 10px 14px;
    border-radius: 4px;
    color: #fff;
    background: #333;
    box-shadow: 0 2px 8px rgba(0,0,0,.25);
    max-width: 380px;
  }
  #toast div.ok { background: #2e7d32; }
  #toast div.err { background: var(--danger); }

  .empty { padding: 24px; color: var(--muted); }
  pre.debug { background: #f7f7f7; padding: 12px; overflow-x: auto; }
</style>
</head>
<body>

<div class="toolbar">
  <input type="search" id="filter" placeholder="Filter (extension, name, brand, IP...)" autocomplete="off">
  <label><input type="checkbox" id="autorefresh"<?php echo $es_refresh_default_on ? ' checked' : ''; ?>> Auto-refresh every <?php echo (int) $es_refresh_seconds; ?>s</label>
  <button type="button" id="refreshnow">Refresh now</button>
  <div class="spacer"></div>
  <span class="meta" id="meta"></span>
</div>

<div class="wrap">
  <table>
    <thead><tr id="head"></tr></thead>
    <tbody id="body"></tbody>
  </table>
  <div class="empty" id="empty" hidden>No registered contacts.</div>
</div>

<div id="toast"></div>

<?php if ($es_showdebug): ?>
<h3>Raw AMI response</h3>
<pre class="debug"><?php echo es_h(print_r(es_fetch_contacts($astman), true)); ?></pre>
<?php endif; ?>

<script>
(function () {
  "use strict";

  var CSRF     = <?php echo json_encode($es_csrf, $es_json_flags); ?>;
  // Brand -> action id -> {label, confirm, danger}. The NOTIFY headers stay
  // server-side; the browser only ever names an action.
  var ACTIONS  = <?php echo json_encode($es_actions_public, $es_json_flags); ?>;
  var INTERVAL = <?php echo (int) $es_refresh_seconds; ?> * 1000;
  var rows     = <?php echo json_encode($es_rows, $es_json_flags); ?>;

  var COLUMNS = [
    { key: 'aor',        label: 'Extension',            type: 'num'  },
    { key: 'name',       label: 'Name',                 type: 'text' },
    { key: 'brand',      label: 'Brand',                type: 'text' },
    { key: 'model',      label: 'Model',                type: 'text' },
    { key: 'firmware',   label: 'Firmware',             type: 'text' },
    { key: 'transport',  label: 'Transport',            type: 'text' },
    { key: 'status',     label: 'Status',               type: 'text' },
    { key: 'rtt_ms',     label: 'Response Time',        type: 'num'  },
    { key: 'ips',        label: 'Known Device IPs',     sort: false  },
    { key: 'expire',     label: 'Registration Expires', type: 'num'  },
    { key: 'actions',    label: 'Actions',              sort: false  }
  ];

  var sortKey = 'aor', sortAsc = true, filter = '';
  var $head = document.getElementById('head');
  var $body = document.getElementById('body');
  var $empty = document.getElementById('empty');
  var $meta = document.getElementById('meta');
  var timer = null;

  function el(tag, text, cls) {
    var n = document.createElement(tag);
    // textContent throughout: the User-Agent is attacker-controlled.
    if (text !== undefined && text !== null) { n.textContent = String(text); }
    if (cls) { n.className = cls; }
    return n;
  }

  function toast(msg, ok) {
    var box = document.getElementById('toast');
    var t = el('div', msg, ok ? 'ok' : 'err');
    box.appendChild(t);
    setTimeout(function () { t.remove(); }, 6000);
  }

  function renderHead() {
    $head.textContent = '';
    COLUMNS.forEach(function (c) {
      var th = el('th', c.label);
      if (c.sort !== false) {
        th.className = 'sortable' + (sortKey === c.key ? ' sorted' : '');
        var a = el('span', sortKey === c.key ? (sortAsc ? ' ▲' : ' ▼') : ' ▵', 'arrow');
        th.appendChild(a);
        th.addEventListener('click', function () {
          if (sortKey === c.key) { sortAsc = !sortAsc; } else { sortKey = c.key; sortAsc = true; }
          render();
        });
      }
      $head.appendChild(th);
    });
  }

  function matches(r) {
    if (!filter) { return true; }
    var hay = [r.aor, r.name, r.brand, r.model, r.firmware, r.transport,
               r.status, r.uri_ip, r.via_ip, r.callid_ip, r.useragent]
              .join(' ').toLowerCase();
    return hay.indexOf(filter) !== -1;
  }

  function compare(a, b) {
    var col = COLUMNS.filter(function (c) { return c.key === sortKey; })[0];
    var av = a[sortKey], bv = b[sortKey];
    var r;
    if (col && col.type === 'num') {
      var an = av === null || av === '' ? NaN : Number(av);
      var bn = bv === null || bv === '' ? NaN : Number(bv);
      // Fall back to a string compare when the value is not numeric (e.g. a
      // non-numeric extension), and sort blanks last either way.
      if (isNaN(an) && isNaN(bn)) { r = String(av || '').localeCompare(String(bv || '')); }
      else if (isNaN(an)) { return 1; }
      else if (isNaN(bn)) { return -1; }
      else { r = an - bn; }
    } else {
      r = String(av === null ? '' : av).localeCompare(String(bv === null ? '' : bv));
    }
    return sortAsc ? r : -r;
  }

  function ipCell(r) {
    var td = el('td', null, 'ips');
    [['URI', r.uri_ip], ['Via', r.via_ip], ['CallID', r.callid_ip]].forEach(function (pair) {
      td.appendChild(el('b', pair[0] + ':'));
      td.appendChild(document.createTextNode(' '));
      if (pair[1] === 'Not an IP') {
        td.appendChild(el('span', pair[1], 'none'));
      } else {
        td.appendChild(document.createTextNode(pair[1]));
      }
      td.appendChild(document.createElement('br'));
    });
    return td;
  }

  function actionCell(r) {
    var td = el('td', null, 'actions');
    var available = ACTIONS[r.brand];
    if (!available) {
      // Softphone or unrecognised brand - nothing sensible to send it.
      td.appendChild(el('span', '—', 'dash'));
      return td;
    }
    Object.keys(available).forEach(function (id) {
      var spec = available[id];
      var b = el('button', spec.label);
      if (spec.danger) { b.className = 'danger'; }
      b.addEventListener('click', function () {
        if (spec.confirm) {
          // Name the specific handset: an extension can have several contacts
          // and this only hits the one in this row.
          var msg = spec.label + '\n\nExtension ' + r.aor +
                    (r.name ? ' (' + r.name + ')' : '') +
                    '\n' + r.brand + ' ' + r.model + ' at ' + r.uri_ip +
                    '\n\nThis affects only this handset. Continue?';
          if (!window.confirm(msg)) { return; }
        }
        sendNotify(r, id, b);
      });
      td.appendChild(b);
    });
    return td;
  }

  function sendNotify(r, actionId, button) {
    var siblings = button.parentNode.querySelectorAll('button');
    siblings.forEach(function (b) { b.disabled = true; });

    var body = new URLSearchParams();
    body.set('action', 'notify');
    body.set('csrf', CSRF);
    body.set('uri', r.uri);
    body.set('action_id', actionId);

    fetch(window.location.pathname, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
      credentials: 'same-origin'
    })
    .then(function (res) { return res.json().catch(function () {
      throw new Error('HTTP ' + res.status);
    }); })
    .then(function (j) {
      // Asterisk confirms dispatch, not delivery - say only what is true.
      toast(j.message, j.ok);
    })
    .catch(function (e) {
      toast('Request failed: ' + e.message, false);
    })
    .finally(function () {
      siblings.forEach(function (b) { b.disabled = false; });
    });
  }

  function render() {
    renderHead();
    var view = rows.filter(matches).slice().sort(compare);

    $body.textContent = '';
    view.forEach(function (r) {
      var tr = document.createElement('tr');
      tr.appendChild(el('td', r.aor));
      tr.appendChild(el('td', r.name));
      tr.appendChild(el('td', r.brand));
      tr.appendChild(el('td', r.model));
      tr.appendChild(el('td', r.firmware));

      var tt = el('td');
      tt.appendChild(el('span', r.transport, 'pill ' + r.transport.toLowerCase()));
      tr.appendChild(tt);

      tr.appendChild(el('td', r.status, 'status-' + String(r.status).toLowerCase()));
      tr.appendChild(el('td', r.rtt_ms === null ? '-' : r.rtt_ms + ' ms'));
      tr.appendChild(ipCell(r));
      tr.appendChild(el('td', r.expire_str));
      tr.appendChild(actionCell(r));
      $body.appendChild(tr);
    });

    $empty.hidden = view.length !== 0;
    $meta.textContent = view.length + ' of ' + rows.length + ' contact' +
                        (rows.length === 1 ? '' : 's');
  }

  function refresh() {
    fetch(window.location.pathname + '?action=data', { credentials: 'same-origin' })
      .then(function (res) {
        if (res.status === 403) { throw new Error('session expired - reload the page'); }
        return res.json();
      })
      .then(function (j) {
        if (!j.ok) { throw new Error(j.message || 'refresh failed'); }
        rows = j.rows;
        render();
        $meta.textContent += ' · updated ' + j.generated;
      })
      .catch(function (e) {
        toast('Refresh failed: ' + e.message, false);
        stopTimer();
        document.getElementById('autorefresh').checked = false;
      });
  }

  function startTimer() { stopTimer(); timer = setInterval(refresh, INTERVAL); }
  function stopTimer() { if (timer) { clearInterval(timer); timer = null; } }

  document.getElementById('filter').addEventListener('input', function (e) {
    filter = e.target.value.trim().toLowerCase();
    render();
  });
  document.getElementById('refreshnow').addEventListener('click', refresh);
  document.getElementById('autorefresh').addEventListener('change', function (e) {
    if (e.target.checked) { startTimer(); } else { stopTimer(); }
  });

  render();
  if (document.getElementById('autorefresh').checked) { startTimer(); }
})();
</script>
</body>
</html>
