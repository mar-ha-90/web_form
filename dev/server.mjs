/* Local development server.
 *
 * You have no PHP on this machine, and Cloudflare previews cannot store or send
 * anything — so neither of the two real adapters can be exercised end to end
 * locally. This closes that gap: it speaks the same wire protocol, writes real
 * .jsonl files, and instead of sending mail it drops a .eml into dev/outbox/
 * that you can open and read. Same checks, same responses, nothing to install.
 *
 *   node dev/server.mjs            # http://localhost:8787
 *   node dev/server.mjs --port 3000
 *
 * Routes: /api/form.php and /api/form both work, so you can test whichever
 * endpoint value the page is configured with.
 */

import { createServer } from 'node:http';
import { readFile, mkdir, appendFile, writeFile, readdir } from 'node:fs/promises';
import { existsSync, statSync } from 'node:fs';
import { join, extname, resolve, normalize, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { randomUUID } from 'node:crypto';

import {
  FormError,
  checkOrigin,
  issueToken,
  processSubmission,
  readBody,
} from '../site/functions/_core.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));

/* Defaults to this repo's own demo site. Point it at a real project to test the
 * generated pages against the engine before either adapter exists on a host:
 *     node dev/server.mjs --site ../rafting-oravec/site
 * forms.json is read from whatever folder this lands on, so the project's own
 * schema is the one being exercised. */
const CUSTOM_SITE = Boolean(argValue('--site'));
const SITE = resolve(HERE, argValue('--site') || '../site');
const DATA = resolve(HERE, 'data');
const OUTBOX = resolve(HERE, 'outbox');

const PORT = Number(argValue('--port') || 8787);

/* Dev-only fixed secret. Tokens stay valid across restarts, which makes a
 * hot-reload loop far less irritating. Never reuse this in production — the
 * whole point of config.php's secret is that it is unique per site. */
const SECRET = 'dev-secret-not-for-production-0000000000000000';
const NONCE_TTL = 7200;

const formsJson = JSON.parse(await readFile(join(SITE, 'api/forms.json'), 'utf8'));

/* In-memory, because a dev server restart should start clean. The PHP engine
 * keeps both of these on disk so they survive across requests on a shared host. */
const spentNonces = new Set();
const rateLog = new Map();

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.mjs': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.webp': 'image/webp',
  '.woff2': 'font/woff2',
  '.ico': 'image/x-icon',
};

const server = createServer(async (req, res) => {
  const url = new URL(req.url, `http://localhost:${PORT}`);

  try {
    if (url.pathname === '/api/form.php' || url.pathname === '/api/form') {
      return await handleForm(req, res, url);
    }
    if (url.pathname === '/api/export.php' || url.pathname === '/api/export') {
      return await handleExport(req, res, url);
    }
    return await serveStatic(res, url.pathname);
  } catch (err) {
    console.error(err);
    send(res, 500, { 'Content-Type': 'text/plain' }, 'dev server error\n' + err.stack);
  }
});

server.listen(PORT, () => {
  console.log(`
  form-engine dev server
  ----------------------
  site      http://localhost:${PORT}/
  endpoint  /api/form.php  (and /api/form)
  forms     ${Object.keys(formsJson.forms).join(', ')}
  data      ${DATA}
  outbox    ${OUTBOX}   <- notification e-mails land here as .eml
`);
});

/* --------------------------------------------------------------- endpoints */

async function handleForm(req, res, url) {
  const debug = true;

  try {
    checkOrigin(req.headers.origin, req.headers.referer, ['localhost', '127.0.0.1']);

    if (req.method === 'GET') {
      const formId = url.searchParams.get('form') || '';
      if (!formsJson?.forms?.[formId]) throw new FormError('bad_form', `Unknown form "${formId}".`);
      return send(res, 200, jsonHeaders(), JSON.stringify({
        ok: true,
        nonce: await issueToken(formId, SECRET),
        ttl: NONCE_TTL,
      }));
    }

    if (req.method !== 'POST') throw new FormError('bad_request', 'Use GET or POST.');

    const body = await readBody(await toRequest(req, url));

    const { formId, form, values, verdict } = await processSubmission({
      body,
      formsJson,
      secret: SECRET,
      nonceTtl: NONCE_TTL,
      ip: req.socket.remoteAddress || '127.0.0.1',
      consumeNonce: async (token) => {
        if (spentNonces.has(token)) return false;
        spentNonces.add(token);
        return true;
      },
      checkRate: async (ip, id, perHour, perDay) => {
        const key = `${ip}|${id}`;
        const now = Date.now();
        const stamps = (rateLog.get(key) || []).filter((t) => now - t < 86400_000);

        const lastHour = stamps.filter((t) => now - t < 3600_000).length;
        if ((perHour > 0 && lastHour >= perHour) || (perDay > 0 && stamps.length >= perDay)) {
          rateLog.set(key, stamps);
          throw new FormError('rate_limited', 'Too many submissions.');
        }

        stamps.push(now);
        rateLog.set(key, stamps);
      },
    });

    const id = `${new Date().toISOString().replace(/[-:T.]/g, '').slice(0, 15)}-${randomUUID().slice(0, 8)}`;
    const record = {
      id,
      form: formId,
      at: new Date().toISOString(),
      spam: verdict.quarantined,
      spamScore: verdict.score,
      spamReasons: verdict.reasons,
      values,
    };

    await mkdir(join(DATA, formId), { recursive: true });
    await appendFile(
      join(DATA, formId, `${new Date().toISOString().slice(0, 7)}.jsonl`),
      JSON.stringify(record) + '\n',
      'utf8',
    );

    if (verdict.quarantined) {
      console.log(`  ✖ quarantined ${id}  score=${verdict.score}  ${verdict.reasons.join(' ')}`);
      return send(res, 200, jsonHeaders(), JSON.stringify({ ok: true, id }));
    }

    await writeEml(form, values, record);
    console.log(`  ✔ ${formId} ${id}  ->  dev/outbox/${id}.eml`);

    return send(res, 200, jsonHeaders(), JSON.stringify({ ok: true, id, mail: 'outbox' }));
  } catch (err) {
    if (err instanceof FormError) {
      console.log(`  ✖ ${err.code}: ${err.message}`);
      return send(res, err.status, jsonHeaders(), JSON.stringify(err.toJSON(debug)));
    }
    console.error(err);
    return send(res, 500, jsonHeaders(), JSON.stringify({ ok: false, error: 'server', message: String(err) }));
  }
}

async function handleExport(req, res, url) {
  const formId = url.searchParams.get('form') || '';
  const dir = join(DATA, formId);

  if (!formsJson?.forms?.[formId] || !existsSync(dir)) {
    return send(res, 404, { 'Content-Type': 'text/plain' }, 'no submissions yet\n');
  }

  const rows = [];
  for (const file of (await readdir(dir)).sort().reverse()) {
    const text = await readFile(join(dir, file), 'utf8');
    for (const line of text.split('\n').filter(Boolean)) rows.push(JSON.parse(line));
  }

  send(res, 200, { 'Content-Type': 'application/json; charset=utf-8' }, JSON.stringify(rows, null, 2));
}

/* ------------------------------------------------------------------ outbox */

/** Writes what the PHP Mailer would have sent, as a file any mail client opens. */
async function writeEml(form, values, record) {
  await mkdir(OUTBOX, { recursive: true });

  const lines = [];
  for (const [name, spec] of Object.entries(form.fields)) {
    if (spec.type === 'hidden' || !(name in values)) continue;
    let value = values[name];
    if (typeof value === 'boolean') value = value ? 'áno' : 'nie';
    if (value === '' || value == null) continue;
    lines.push(`${spec.label || name}: ${value}`);
  }

  const subject = String(form.subject).replace(/\{([A-Za-z0-9_]+)\}/g, (_, key) => {
    const v = values[key];
    return typeof v === 'boolean' ? (v ? 'áno' : 'nie') : String(v ?? '');
  });

  const replyTo = form.replyTo && values[form.replyTo] ? String(values[form.replyTo]) : '';

  const eml = [
    `Date: ${new Date().toUTCString()}`,
    `From: Form engine (dev) <dev@localhost>`,
    `To: ${form.to.join(', ')}`,
    replyTo ? `Reply-To: ${replyTo}` : null,
    `Subject: ${subject}`,
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    '',
    form.label,
    '='.repeat(Math.max(4, form.label.length)),
    '',
    ...lines,
    '',
    '-'.repeat(40),
    `ID: ${record.id}`,
    `Spam score: ${record.spamScore}`,
  ].filter((l) => l !== null).join('\r\n');

  await writeFile(join(OUTBOX, `${record.id}.eml`), eml, 'utf8');
}

/* ------------------------------------------------------------------ static */

async function serveStatic(res, pathname) {
  // The demo page lives in dev/ so it never gets copied into a real project.
  // It only takes over the site root when there is no real site to show — with
  // --site the project's own index.html must win, or you end up testing the
  // demo form and believing it was yours.
  if (!CUSTOM_SITE && (pathname === '/' || pathname === '/index.html')) {
    const demo = join(HERE, 'demo.html');
    if (existsSync(demo)) {
      return send(res, 200, { 'Content-Type': MIME['.html'] }, await readFile(demo));
    }
  }

  // normalize() collapses ../ before we join, so a crafted path cannot climb
  // out of the site folder.
  const safe = normalize(pathname).replace(/^(\.\.[/\\])+/, '');
  let file = join(SITE, safe);

  // Directory index, the way every static host does it — so '/' and '/pl/'
  // land on their index.html instead of trying to read the folder itself.
  if (existsSync(file) && statSync(file).isDirectory()) {
    file = join(file, 'index.html');
  }

  if (!file.startsWith(SITE) || !existsSync(file)) {
    return send(res, 404, { 'Content-Type': 'text/plain' }, 'not found\n');
  }

  const type = MIME[extname(file).toLowerCase()] || 'application/octet-stream';
  send(res, 200, { 'Content-Type': type, 'Cache-Control': 'no-store' }, await readFile(file));
}

/* ----------------------------------------------------------------- helpers */

/** Wraps a node request as a fetch Request so _core.mjs can parse the body. */
async function toRequest(req, url) {
  const chunks = [];
  for await (const chunk of req) chunks.push(chunk);

  return new Request(url.href, {
    method: req.method,
    headers: req.headers,
    body: chunks.length ? Buffer.concat(chunks) : undefined,
  });
}

function jsonHeaders() {
  return { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' };
}

function send(res, status, headers, body) {
  res.writeHead(status, headers);
  res.end(body);
}

function argValue(flag) {
  const i = process.argv.indexOf(flag);
  return i === -1 ? null : process.argv[i + 1];
}
