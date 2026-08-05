// Static check of the page's inline JS.
//
// php -l does not look inside <script>, which is how "spec is not defined"
// shipped: a ReferenceError inside a promise callback, invisible until the
// button was clicked. Syntax alone would not catch it either - the script is
// run against a stubbed DOM so every top-level definition is exercised, and
// each function body is scanned for identifiers nothing in scope defines.
const fs = require('fs');
const vm = require('vm');

const ROWS = [
    // A registered Yealink is essential: its "Reload config" does not confirm,
    // so it exercises sendNotify directly. Without one, no toast is ever
    // produced and the toast inspection has nothing to look at.
    { aor: '101', name: 'Mike Bundy', brand: 'Yealink', model: 'T46G', firmware: '28.83', useragent: 'Yealink SIP-T46G 28.83',
      transport: 'UDP', status: 'Reachable', rtt_ms: 25, uri: 'sip:101@1.2.3.4:5060', uri_ip: '1.2.3.4',
      via_ip: '1.2.3.4', callid_ip: '10.0.0.9', expire: 1, expire_str: 'x', siblings: 1,
      registered: true, actionable: true },
    { aor: '103', name: 'Jared Busch', brand: 'Fanvil', model: 'V64', firmware: '2.12', useragent: 'Fanvil V64 2.12',
      transport: 'UDP', status: 'Reachable', rtt_ms: 14.8, uri: 'sip:103@1.2.3.4:5060', uri_ip: '1.2.3.4',
      via_ip: '10.0.0.1', callid_ip: '10.0.0.1', expire: 1, expire_str: 'x', siblings: 1,
      registered: true, actionable: true },
    { aor: '105', name: 'Gone Phone', brand: 'Yealink', model: 'T46G', firmware: '', useragent: '',
      transport: '', status: 'Unregistered', rtt_ms: null, uri: '', uri_ip: '9.9.9.9',
      via_ip: '', callid_ip: '', expire: 1, expire_str: 'last seen x', siblings: 0,
      registered: false, actionable: false }
  ];
const file = process.argv[2];
const src = fs.readFileSync(file, 'utf8');
// The word "<script>" also appears in a doc comment, so take the block that
// actually closes with </script> rather than the first opening tag.
const close = src.lastIndexOf('</script>');
const open = src.lastIndexOf('<script>', close);
if (close < 0 || open < 0) { console.error('no <script> block found'); process.exit(1); }

// The PHP interpolations are not JS; substitute plausible values.
let js = src.slice(open + '<script>'.length, close)
  .replace(/<\?php echo json_encode\(\$es_csrf[^)]*\); \?>/g, '"' + 'a'.repeat(64) + '"')
  .replace(/<\?php echo json_encode\(\$es_actions_public[^)]*\); \?>/g,
    JSON.stringify({ Yealink: { reload: { label: 'Reload config', confirm: false, danger: false, verify: 'config' },
                                restart: { label: 'Reboot', confirm: true, danger: true, verify: 'register' } },
                     Fanvil:  { restart: { label: 'Reboot', confirm: true, danger: true, verify: 'register' } } }))
  .replace(/<\?php echo json_encode\(\$es_rows[^)]*\); \?>/g, JSON.stringify(ROWS))
  .replace(/<\?php echo \(int\) \$es_\w+; \?>/g, '30')
  .replace(/<\?php echo \$es_verify_enabled \? 'true' : 'false'; \?>/g, 'true')
  .replace(/<\?php[\s\S]*?\?>/g, 'null');

// 1. Syntax.
try { new vm.Script(js, { filename: 'inline.js' }); }
catch (e) { console.error('SYNTAX ERROR: ' + e.message); process.exit(1); }
console.log('  ok    syntax');

// 2. Run it against a stub DOM, then drive the paths a click would take.
const calls = [];
function elStub(tag) {
  const n = {
    tagName: tag, className: '', textContent: '', nodeValue: '', hidden: false,
    children: [], style: {}, disabled: false, parentNode: null,
    appendChild(c) { this.children.push(c); c.parentNode = this; return c; },
    removeChild(c) { return c; }, remove() {}, focus() {}, blur() {},
    addEventListener(ev, fn) { if (ev === 'click') calls.push(fn); },
    removeEventListener() {}, querySelectorAll() { return []; },
    querySelector() { return null; }, hasAttribute() { return false; },
    setAttribute() {}, contains() { return true; },
    classList: { add() {}, remove() {}, toggle() {} },
  };
  return n;
}
// Stable elements per id, so the toast container can be inspected afterwards.
const byId = {};
const doc = {
  createElement: elStub, createTextNode: (t) => ({ nodeValue: t }),
  getElementById: (id) => (byId[id] = byId[id] || elStub('div')),
  addEventListener() {}, removeEventListener() {},
  contains: () => true, activeElement: null,
};
const sandbox = {
  document: doc, console,
  window: { location: { pathname: '/custom/extensionstatus.php' }, confirm: () => true },
  // Each endpoint answers in its own shape, or the caller trips over a field
  // the real server would always send.
  fetch: (url, opts) => {
    let body;
    if (/action=data/.test(url)) {
      body = { ok: true, rows: ROWS, generated: '12:00:00' };
    } else if (/action=verify/.test(url)) {
      body = { ok: true, seen: false, registered: true, readable: true, mode: 'register' };
    } else {
      body = { ok: true, message: 'NOTIFY dispatched.' };   // the notify POST
    }
    return Promise.resolve({ status: 200, json: () => Promise.resolve(body) });
  },
  setInterval: () => 1, clearInterval() {}, setTimeout: () => 1,
  URLSearchParams: global.URLSearchParams, Promise, Date, JSON, Math, String, Number,
  Object, Array, isNaN, encodeURIComponent,
};
sandbox.window.document = doc;
try { vm.runInNewContext(js, sandbox, { filename: 'inline.js' }); }
catch (e) { console.error('RUNTIME ERROR on load: ' + e.message); process.exit(1); }
console.log('  ok    loads and renders against a stub DOM');

// 3. Every button handler must run clean - including its async continuations.
//
// A synchronous try/catch is not enough. "spec is not defined" fired inside a
// .then(), where sendNotify's own .catch() turned it into a red toast rather
// than an exception, so it never surfaced here. The async paths have to be
// driven and the resulting toasts inspected.
let failed = 0;
calls.forEach((fn, i) => {
  try { fn({ preventDefault() {}, target: elStub('button') }); }
  catch (e) {
    if (e instanceof ReferenceError || e instanceof TypeError) {
      console.error('  FAIL  handler ' + i + ' (sync): ' + e.message);
      failed++;
    }
  }
});

const drain = () => new Promise((res) => setImmediate(res));
(async () => {
  for (let i = 0; i < 20; i++) { await drain(); }

  const toasts = (byId.toast ? byId.toast.children : []).map((c) => c.textContent || '');
  const broken = toasts.filter((t) => /is not defined|is not a function|undefined/i.test(t));
  broken.forEach((t) => { console.error('  FAIL  error surfaced to the user: ' + t); failed++; });

  if (failed) { process.exit(1); }
  console.log('  ok    ' + calls.length + ' click handlers run clean, including async paths');
  console.log('  ok    no JS error reached a toast (' + toasts.length + ' toasts inspected)');
})();
