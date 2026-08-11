/* Cloudflare Pages Function — the testing adapter.
 *
 * Route: /api/form   (Pages derives it from this file's path)
 *
 * Purpose is narrow and worth being blunt about: it lets the form work on your
 * git → Cloudflare preview deploys, so you can click through a real submission
 * before the client's host ever sees the code. It validates and spam-checks
 * exactly like the PHP engine, then logs the result and returns success.
 *
 * It does NOT send mail and does NOT store anything. A Worker has no local
 * filesystem and no SMTP socket, so delivering mail from here would mean
 * signing up for an e-mail API — the third-party dependency this whole engine
 * exists to avoid. Production runs on PHP, where both are free.
 *
 * Setup: in the Pages project, add an environment variable FORM_SECRET (any
 * long random string) and optionally FORM_ALLOWED_ORIGINS (comma-separated).
 * Point the page at it with <body data-form-endpoint="/api/form">.
 */

import formsJson from '../../api/forms.json';
import {
  FormError,
  checkOrigin,
  evaluateSpam,
  issueToken,
  processSubmission,
  readBody,
} from '../_core.mjs';

const JSON_HEADERS = {
  'Content-Type': 'application/json; charset=utf-8',
  'Cache-Control': 'no-store',
  'X-Content-Type-Options': 'nosniff',
  'X-Robots-Tag': 'noindex',
};

export async function onRequest(context) {
  const { request, env } = context;
  const debug = env.FORM_DEBUG === '1';

  const secret = env.FORM_SECRET;
  if (!secret) {
    return json({ ok: false, error: 'server', message: 'FORM_SECRET is not set on this Pages project.' }, 500);
  }

  const allowed = (env.FORM_ALLOWED_ORIGINS || '')
    .split(',')
    .map((s) => s.trim())
    .filter(Boolean);

  const ttl = Number(env.FORM_NONCE_TTL || 7200);

  try {
    checkOrigin(request.headers.get('origin'), request.headers.get('referer'), allowed);

    const url = new URL(request.url);

    if (request.method === 'GET') {
      const formId = url.searchParams.get('form') || '';
      if (!formsJson?.forms?.[formId]) {
        throw new FormError('bad_form', `Unknown form "${formId}".`);
      }
      return json({ ok: true, nonce: await issueToken(formId, secret), ttl });
    }

    if (request.method !== 'POST') {
      throw new FormError('bad_request', 'Use GET for a token or POST to submit.');
    }

    const body = await readBody(request);

    // No consumeNonce and no checkRate hook: both need somewhere to keep state
    // between requests, and a preview deploy has nowhere. Replay protection and
    // rate limiting are live in the PHP engine, which is what faces the public.
    const { formId, values, verdict } = await processSubmission({
      body,
      formsJson,
      secret,
      nonceTtl: ttl,
      ip: request.headers.get('cf-connecting-ip') || '0.0.0.0',
    });

    // Visible in `wrangler pages deployment tail` and in the dashboard's live
    // log, which is the whole point of this adapter.
    console.log(JSON.stringify({
      formEngine: 'preview',
      form: formId,
      spam: verdict.quarantined,
      score: verdict.score,
      reasons: verdict.reasons,
      values,
    }));

    return json({ ok: true, id: 'preview', mail: 'preview' });
  } catch (err) {
    if (err instanceof FormError) {
      return json(err.toJSON(debug), err.status);
    }
    console.error('form-engine', err);
    return json({ ok: false, error: 'server', message: debug ? String(err) : undefined }, 500);
  }
}

function json(body, status = 200) {
  return new Response(JSON.stringify(body), { status, headers: JSON_HEADERS });
}
