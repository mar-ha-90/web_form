/* Form engine — shared JS logic.
 *
 * This is the second implementation of the same wire protocol as api/form.php.
 * It exists because Cloudflare Pages cannot run PHP and the client's shared
 * host cannot run Workers, and you test on the former while they run on the
 * latter. A form that only works in production is a form you cannot develop.
 *
 * Everything here is pure: no filesystem, no network, no mail. The two callers
 * supply their own I/O —
 *   functions/api/form.js  Cloudflare Pages Function (preview; logs only)
 *   dev/server.mjs         local Node server (writes real .jsonl files)
 *
 * Rules live in forms.json, which both this file and the PHP engine read, so a
 * field is still only described once. Keep the behaviour below in step with
 * lib/Validator.php and lib/Spam.php — the tests in dev/test.mjs check that the
 * two agree on the cases that matter.
 */

const TOKEN_VERSION = 'v1';

export const HONEYPOTS = ['_website', '_company_url'];
export const SPAM_THRESHOLD = 5;

/** Mirrors FE_Exception. */
export class FormError extends Error {
  constructor(code, message = '', fields = null) {
    super(message || code);
    this.code = code;
    this.fields = fields;
  }

  get status() {
    switch (this.code) {
      case 'rate_limited': return 429;
      case 'server':
      case 'mail_failed': return 500;
      case 'spam':
      case 'bad_nonce': return 403;
      default: return 400;
    }
  }

  toJSON(debug = false) {
    const body = { ok: false, error: this.code };
    if (this.fields) body.fields = this.fields;
    if (debug) body.message = this.message;
    return body;
  }
}

/* ------------------------------------------------------------------ config */

/** Folds the site-wide defaults into one form definition, as FE_Config::form does. */
export function resolveForm(formsJson, id) {
  const raw = formsJson?.forms?.[id];
  if (!raw) throw new FormError('bad_form', `Unknown form "${id}".`);

  return {
    label: id,
    to: [],
    cc: [],
    subject: `Formulár: ${id}`,
    replyTo: '',
    minSeconds: 3,
    maxPerHour: 5,
    maxPerDay: 20,
    store: true,
    fields: {},
    ...raw,
  };
}

/* ------------------------------------------------------------------- token */

const encoder = new TextEncoder();

async function hmacHex(secret, payload) {
  const key = await crypto.subtle.importKey(
    'raw',
    encoder.encode(secret),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign'],
  );
  const sig = await crypto.subtle.sign('HMAC', key, encoder.encode(payload));
  return [...new Uint8Array(sig)].map((b) => b.toString(16).padStart(2, '0')).join('');
}

/** Byte-for-byte identical to FE_Token::sign — truncated SHA-256 HMAC. */
async function sign(formId, ts, secret) {
  const hex = await hmacHex(secret, `${TOKEN_VERSION}|${formId}|${ts}`);
  return hex.slice(0, 32);
}

export async function issueToken(formId, secret, now = Math.floor(Date.now() / 1000)) {
  return `${TOKEN_VERSION}.${now}.${await sign(formId, now, secret)}`;
}

/** @returns {Promise<number>} the issue timestamp */
export async function verifyToken(token, formId, secret, ttl, now = Math.floor(Date.now() / 1000)) {
  if (typeof token !== 'string' || token === '') {
    throw new FormError('bad_nonce', 'Missing token.');
  }

  const parts = token.split('.');
  if (parts.length !== 3 || parts[0] !== TOKEN_VERSION) {
    throw new FormError('bad_nonce', 'Malformed token.');
  }

  const ts = Number(parts[1]);
  if (!Number.isInteger(ts)) throw new FormError('bad_nonce', 'Malformed token timestamp.');

  const expected = await sign(formId, ts, secret);
  if (!timingSafeEqual(expected, parts[2])) {
    throw new FormError('bad_nonce', 'Token signature does not match.');
  }

  if (ts > now + 60) throw new FormError('bad_nonce', 'Token is from the future.');
  if (now - ts > ttl) throw new FormError('bad_nonce', 'Token expired.');

  return ts;
}

/** Constant-time within the bounds of what JS can promise. */
function timingSafeEqual(a, b) {
  if (a.length !== b.length) return false;
  let diff = 0;
  for (let i = 0; i < a.length; i++) diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
  return diff === 0;
}

/* -------------------------------------------------------------- validation */

const CONTROL_CHARS = /[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/g;
const TRUTHY = new Set(['1', 'on', 'true', 'yes', 'ano', 'tak']);

export function validate(fields, input) {
  const clean = {};
  const errors = {};

  for (const [name, spec] of Object.entries(fields)) {
    const rules = { type: 'text', required: false, label: name, ...spec };

    let raw = input[name];
    if (Array.isArray(raw)) raw = raw.map(String).join(', ');

    const value = raw == null ? '' : String(raw).trim().replace(CONTROL_CHARS, '');

    if (rules.type === 'consent' || rules.type === 'checkbox') {
      const checked = TRUTHY.has(value.toLowerCase());
      if (rules.required && !checked) errors[name] = 'required';
      else clean[name] = checked;
      continue;
    }

    if (value === '') {
      if (rules.required) errors[name] = 'required';
      else clean[name] = '';
      continue;
    }

    const failed = checkOne(rules.type, value, rules);
    if (failed) errors[name] = failed;
    else clean[name] = normalise(rules.type, value);
  }

  if (Object.keys(errors).length) {
    throw new FormError('validation', 'One or more fields are invalid.', errors);
  }

  return clean;
}

function checkOne(type, value, rules) {
  const numeric = type === 'int' || type === 'number';
  const dateish = type === 'date' || type === 'time';

  if (!numeric && !dateish) {
    if (rules.max != null && [...value].length > Number(rules.max)) return 'max';
    if (rules.min != null && [...value].length < Number(rules.min)) return 'min';
  }
  if (rules.pattern && !new RegExp(rules.pattern, 'u').test(value)) return 'pattern';

  switch (type) {
    case 'email':
      if (!/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/iu.test(value)) return 'email';
      break;

    case 'tel': {
      const digits = value.replace(/\D+/g, '');
      if (digits.length < 6 || digits.length > 20 || !/^[0-9+()/\s.\-]+$/u.test(value)) return 'tel';
      break;
    }

    case 'url':
      if (!/^https?:\/\/[^\s]+\.[^\s]+$/i.test(value)) return 'url';
      break;

    case 'int':
    case 'number': {
      if (type === 'int' && !/^-?\d+$/.test(value)) return 'type';
      const n = Number(value);
      if (!Number.isFinite(n)) return 'type';
      if (rules.min != null && n < Number(rules.min)) return 'min';
      if (rules.max != null && n > Number(rules.max)) return 'max';
      break;
    }

    case 'date': {
      if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return 'type';
      const [y, m, d] = value.split('-').map(Number);
      const probe = new Date(Date.UTC(y, m - 1, d));
      if (probe.getUTCFullYear() !== y || probe.getUTCMonth() !== m - 1 || probe.getUTCDate() !== d) {
        return 'type';
      }
      const stamp = probe.getTime();
      if (rules.min != null && stamp < relativeDate(rules.min)) return 'min';
      if (rules.max != null && stamp > relativeDate(rules.max)) return 'max';
      break;
    }

    case 'time':
      if (!/^([01]\d|2[0-3]):[0-5]\d$/.test(value)) return 'type';
      break;

    case 'select':
      if (Array.isArray(rules.options) && !rules.options.includes(value)) return 'option';
      break;

    default:
      // Unknown types are treated as free text, same as the PHP side: a typo in
      // forms.json must not reject a submission the visitor cannot fix.
      break;
  }

  return null;
}

function normalise(type, value) {
  switch (type) {
    case 'email': return value.toLowerCase();
    case 'int': return parseInt(value, 10);
    case 'number': return Number(value);
    case 'tel': return value.replace(/\s+/gu, ' ');
    default: return value;
  }
}

/** Understands 'today', '+2 years', '2027-01-01' — the subset PHP's strtotime is used for here. */
function relativeDate(spec) {
  if (spec === 'today') {
    const now = new Date();
    return Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate());
  }

  const relative = /^([+-])\s*(\d+)\s*(day|week|month|year)s?$/i.exec(String(spec).trim());
  if (relative) {
    const [, dir, amount, unit] = relative;
    const n = Number(amount) * (dir === '-' ? -1 : 1);
    const d = new Date();
    if (unit.toLowerCase() === 'day') d.setUTCDate(d.getUTCDate() + n);
    if (unit.toLowerCase() === 'week') d.setUTCDate(d.getUTCDate() + n * 7);
    if (unit.toLowerCase() === 'month') d.setUTCMonth(d.getUTCMonth() + n);
    if (unit.toLowerCase() === 'year') d.setUTCFullYear(d.getUTCFullYear() + n);
    return d.getTime();
  }

  const parsed = Date.parse(spec);
  return Number.isNaN(parsed) ? 0 : parsed;
}

/* -------------------------------------------------------------------- spam */

export function checkOrigin(originHeader, refererHeader, allowed) {
  if (!allowed || allowed.length === 0) return;

  const raw = originHeader || refererHeader || '';
  if (!raw) return;

  let host;
  try {
    host = new URL(raw).hostname.toLowerCase();
  } catch {
    return;
  }

  for (const entry of allowed) {
    const candidate = String(entry).trim().toLowerCase();
    if (!candidate) continue;
    if (host === candidate || host.endsWith(`.${candidate}`)) return;
  }

  throw new FormError('bad_request', `Origin ${host} is not in allowedOrigins.`);
}

const SPAM_PHRASES = [
  'seo', 'backlink', 'link building', 'guest post', 'crypto',
  'bitcoin', 'casino', 'viagra', 'cialis', 'loan offer',
  'increase your traffic', 'search engine ranking', 'binary option',
];

export function evaluateSpam(raw, clean, form, tokenAge) {
  let score = 0;
  const reasons = [];

  for (const trap of HONEYPOTS) {
    if (raw[trap] != null && String(raw[trap]).trim() !== '') {
      score += 10;
      reasons.push(`honeypot:${trap}`);
    }
  }

  const minSeconds = Number(form.minSeconds ?? 3);
  if (minSeconds > 0 && tokenAge < minSeconds) {
    score += 6;
    reasons.push(`too_fast:${tokenAge}s`);
  }

  const fields = form.fields || {};
  const freeText = {};

  for (const [name, value] of Object.entries(clean)) {
    if (typeof value !== 'string' || value === '') continue;
    const type = fields[name]?.type || 'text';

    if (type === 'text' || type === 'textarea') freeText[name] = value;

    if ((type === 'text' || type === 'tel') && name !== 'poznamka' && countLinks(value) > 0) {
      score += 4;
      reasons.push(`link_in_${name}`);
    }
  }

  for (const [name, value] of Object.entries(freeText)) {
    const allowance = Number(fields[name]?.maxLinks ?? 1);
    const extra = countLinks(value) - allowance;
    if (extra > 0) {
      score += 2 * extra;
      reasons.push(`links:${name}:+${extra}`);
    }

    if (/<\s*a\s|\[url|\[link|href\s*=/iu.test(value)) {
      score += 3;
      reasons.push(`markup:${name}`);
    }

    const hits = SPAM_PHRASES.filter((needle) =>
      new RegExp(`(?<!\\p{L})${escapeRegExp(needle)}(?!\\p{L})`, 'iu').test(value));
    if (hits.length) {
      score += 2 * hits.length;
      reasons.push(`phrases:${hits.join(',')}`);
    }

    const letters = value.replace(/[^\p{L}]/gu, '');
    if (letters.length > 40 && value.toUpperCase() === value) {
      score += 1;
      reasons.push(`shouting:${name}`);
    }
  }

  const strings = Object.values(clean).filter((v) => typeof v === 'string' && v !== '');
  if (strings.length >= 3 && new Set(strings).size === 1) {
    score += 4;
    reasons.push('identical_values');
  }

  return { score, reasons, quarantined: score >= SPAM_THRESHOLD };
}

function countLinks(value) {
  return (value.match(/(https?:\/\/|www\.)[^\s<>"']+/giu) || []).length;
}

function escapeRegExp(value) {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/* ----------------------------------------------------------------- request */

/** Parses either multipart/form-data or urlencoded into a flat object. */
export async function readBody(request) {
  const type = request.headers.get('content-type') || '';

  if (type.includes('application/json')) {
    return await request.json();
  }

  const form = await request.formData();
  const out = {};
  for (const [key, value] of form.entries()) {
    out[key] = typeof value === 'string' ? value : value.name;
  }
  return out;
}

/**
 * The whole pipeline, minus storage and mail. Callers pass hooks for the parts
 * that differ between a Worker and a Node process.
 *
 * @param {object}   o
 * @param {object}   o.body          parsed request body
 * @param {object}   o.formsJson     parsed forms.json
 * @param {string}   o.secret
 * @param {number}   o.nonceTtl
 * @param {(t:string)=>Promise<boolean>} [o.consumeNonce] false if replayed
 * @param {(ip:string,form:string,h:number,d:number)=>Promise<void>} [o.checkRate]
 */
export async function processSubmission({ body, formsJson, secret, nonceTtl = 7200, consumeNonce, checkRate, ip = '0.0.0.0' }) {
  const formId = String(body._form || '');
  const form = resolveForm(formsJson, formId);

  const issuedAt = await verifyToken(body._nonce, formId, secret, nonceTtl);

  if (consumeNonce && !(await consumeNonce(body._nonce))) {
    throw new FormError('bad_nonce', 'This token has already been used.');
  }

  // form.js reports the page language as _lang, under the underscore prefix it
  // uses for its own metadata. Engine.php un-prefixes it before validating, so
  // do the same here — otherwise a `lang` field in forms.json comes out empty
  // on preview and populated in production, which is the sort of difference you
  // only notice once the client asks why every enquiry says nothing.
  if (body.lang === undefined && body._lang !== undefined) body.lang = body._lang;

  const values = validate(form.fields, body);
  const verdict = evaluateSpam(body, values, form, Math.floor(Date.now() / 1000) - issuedAt);

  if (!verdict.quarantined && checkRate) {
    await checkRate(ip, formId, Number(form.maxPerHour), Number(form.maxPerDay));
  }

  return { formId, form, values, verdict, issuedAt };
}
