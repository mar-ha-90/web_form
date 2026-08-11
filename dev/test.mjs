/* Protocol tests against a running dev server.
 *
 *   node dev/server.mjs          # in one terminal
 *   node dev/test.mjs            # in another
 *
 * These exercise the paths a browser cannot easily reach — replayed tokens,
 * posts with no token at all, the honeypot, the rate limiter. They test the JS
 * adapter; the PHP engine implements the same rules and the same wire protocol,
 * so a change to one should be mirrored in the other and re-run here.
 */

const BASE = process.env.FORM_ENGINE_BASE || 'http://localhost:8787';
const ENDPOINT = `${BASE}/api/form.php`;

let passed = 0;
let failed = 0;

async function token(form = 'rezervacia') {
  const res = await fetch(`${ENDPOINT}?nonce=1&form=${form}`);
  const body = await res.json();
  return body.nonce;
}

/** A complete, valid submission. Individual tests override single fields. */
function valid(overrides = {}) {
  return {
    _form: 'rezervacia',
    meno: 'Ján Novák',
    email: 'jan@example.sk',
    telefon: '+421 900 123 456',
    datum: '2026-09-15',
    cas: '10:00',
    pocet_osob: '4',
    sluzba: 'Rafting s inštruktorom',
    trasa: 'Neviem, poraďte mi',
    poznamka: 'Ahoj, mám záujem.',
    suhlas: 'on',
    lang: 'sk',
    ...overrides,
  };
}

async function post(fields) {
  const body = new FormData();
  for (const [k, v] of Object.entries(fields)) {
    if (v !== undefined && v !== null) body.set(k, String(v));
  }
  const res = await fetch(ENDPOINT, { method: 'POST', body });
  return { status: res.status, body: await res.json() };
}

function check(name, condition, detail = '') {
  if (condition) {
    passed++;
    console.log(`  ok    ${name}`);
  } else {
    failed++;
    console.log(`  FAIL  ${name}${detail ? '  — ' + detail : ''}`);
  }
}

/* ------------------------------------------------------------------- tests */

console.log('\nform-engine protocol tests\n');

// A token must be issued for a known form and refused for anything else.
{
  const good = await fetch(`${ENDPOINT}?nonce=1&form=rezervacia`).then((r) => r.json());
  check('token issued for a known form', good.ok === true && /^v1\.\d+\.[0-9a-f]{32}$/.test(good.nonce));

  const bad = await fetch(`${ENDPOINT}?nonce=1&form=neexistuje`);
  const body = await bad.json();
  check('token refused for an unknown form', bad.status === 400 && body.error === 'bad_form');
}

// Posting with no token at all is the single most common bot behaviour.
{
  const r = await post(valid());
  check('post without a token is rejected', r.status === 403 && r.body.error === 'bad_nonce', JSON.stringify(r.body));
}

// A forged signature must not pass.
{
  const r = await post(valid({ _nonce: 'v1.' + Math.floor(Date.now() / 1000) + '.' + 'f'.repeat(32) }));
  check('forged token signature is rejected', r.status === 403 && r.body.error === 'bad_nonce');
}

// Single use: the second attempt with the same token is a replay.
{
  const t = await token();
  await new Promise((r) => setTimeout(r, 3100)); // clear the minSeconds trap
  const first = await post(valid({ _nonce: t }));
  const second = await post(valid({ _nonce: t }));

  check('valid submission accepted', first.status === 200 && first.body.ok === true, JSON.stringify(first.body));
  check('replayed token is rejected', second.status === 403 && second.body.error === 'bad_nonce', JSON.stringify(second.body));
}

// Validation, field by field. The client paints these next to the inputs.
{
  const cases = [
    ['missing required field', { meno: '' }, { meno: 'required' }],
    ['malformed e-mail', { email: 'not-an-address' }, { email: 'email' }],
    ['too-short phone number', { telefon: '123' }, { telefon: 'tel' }],
    ['number above max', { pocet_osob: '999' }, { pocet_osob: 'max' }],
    ['number below min', { pocet_osob: '0' }, { pocet_osob: 'min' }],
    ['non-numeric where int expected', { pocet_osob: 'veľa' }, { pocet_osob: 'type' }],
    ['date in the past', { datum: '2020-01-01' }, { datum: 'min' }],
    ['impossible date', { datum: '2026-02-31' }, { datum: 'type' }],
    ['select value not in options', { trasa: 'Mars – Venuša (9 km)' }, { trasa: 'option' }],
    ['unticked consent box', { suhlas: '' }, { suhlas: 'required' }],
  ];

  for (const [name, override, expected] of cases) {
    const t = await token();
    await new Promise((r) => setTimeout(r, 3100));
    const r = await post(valid({ ...override, _nonce: t }));

    const field = Object.keys(expected)[0];
    const ok = r.status === 400
      && r.body.error === 'validation'
      && r.body.fields?.[field] === expected[field];

    check(`validation: ${name}`, ok, JSON.stringify(r.body.fields));
  }
}

// Quarantine: accepted with ok:true so the spammer learns nothing, but flagged
// on disk and never e-mailed.
{
  const t = await token();
  await new Promise((r) => setTimeout(r, 3100));
  const r = await post(valid({ _nonce: t, _website: 'http://spam.example' }));
  check('honeypot submission answers ok but is quarantined', r.status === 200 && r.body.ok === true && !r.body.mail, JSON.stringify(r.body));
}

{
  const t = await token();
  // No wait at all — a human cannot fill nine fields in under a second.
  const r = await post(valid({ _nonce: t }));
  check('sub-second submission is quarantined', r.status === 200 && r.body.ok === true && !r.body.mail, JSON.stringify(r.body));
}

{
  const t = await token();
  await new Promise((r) => setTimeout(r, 3100));
  const r = await post(valid({
    _nonce: t,
    poznamka: 'Cheap backlink packages http://a.example http://b.example http://c.example',
  }));
  check('link-stuffed message is quarantined', r.status === 200 && r.body.ok === true && !r.body.mail, JSON.stringify(r.body));
}

// A legitimate message with one link must still get through.
{
  const t = await token();
  await new Promise((r) => setTimeout(r, 3100));
  const r = await post(valid({ _nonce: t, poznamka: 'Našiel som vás cez https://www.google.com — je voľný termín?' }));
  check('one link in a real message is NOT quarantined', r.status === 200 && r.body.mail === 'outbox', JSON.stringify(r.body));
}

// Rate limiting. maxPerHour is 5 for this form; earlier tests have already
// consumed some of the allowance, so submit until it trips.
{
  let limited = false;
  for (let i = 0; i < 8; i++) {
    const t = await token();
    await new Promise((r) => setTimeout(r, 3100));
    const r = await post(valid({ _nonce: t }));
    if (r.status === 429 && r.body.error === 'rate_limited') {
      limited = true;
      break;
    }
  }
  check('rate limiter trips after the hourly allowance', limited);
}

// Header injection: a CR/LF smuggled into a value must never reach a header.
{
  const t = await token();
  await new Promise((r) => setTimeout(r, 3100));
  const r = await post(valid({ _form: 'kontakt', _nonce: t, meno: 'Ján\r\nBcc: victim@example.com', sprava: 'Dobrý deň, mám otázku ohľadom termínu.', email: 'jan@example.sk', suhlas: 'on' }));
  // Either accepted with the CRLF stripped, or rate-limited — both prove the
  // value never travelled intact. What must not happen is a 500.
  check('CRLF in a value does not crash the engine', r.status !== 500, JSON.stringify(r.body));
}

console.log(`\n  ${passed} passed, ${failed} failed\n`);
process.exit(failed === 0 ? 0 : 1);
