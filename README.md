# form-engine

A self-hosted form back end for static sites. Drop it in the same folder you FTP
to the client's host, point a `<form>` at it, done. No Formspree, no Basin, no
Google, no account for the client to lose the password to, and nothing leaves
the server the site already runs on.

Built for the way these projects actually ship: hand-written or generated static
HTML, tested on Cloudflare Pages from a git push, deployed to shared hosting like
websupport.sk over FTP.

---

## Why two engines

Cloudflare Pages cannot run PHP. Shared hosting cannot run Workers. The same
form has to work in both places, so there are two implementations of one wire
protocol:

| | runs on | validates | stores | e-mails |
|---|---|---|---|---|
| `api/form.php` | the client's host (**production**) | yes | yes | yes |
| `functions/api/form.js` | Cloudflare Pages (**preview**) | yes | no | no |
| `dev/server.mjs` | your machine (**local**) | yes | yes | writes `.eml` files |

Field definitions live in **`api/forms.json`**, which all three read. A field is
described once. The Cloudflare adapter deliberately does not send mail — doing
that from a Worker means signing up for an e-mail API, which is the third-party
dependency this whole thing exists to remove.

---

## Files

```
site/                          ← everything here goes to the web server
  form.js                        browser client, ~9 KB, no dependencies
  api/
    form.php                     the endpoint
    export.php                   CSV download of stored submissions
    selftest.php                 deployment check — run this first
    forms.json                   ← the one file you edit per project
    config.php                   ← per-site secrets (never commit)
    config.example.php
    .htaccess                    blocks everything that is not an entry point
    data/                        submissions land here (created on first write)
    lib/                         Config, Token, Validator, Spam, Storage, Smtp, Mailer, Engine
  functions/
    _core.mjs                    shared JS logic
    api/form.js                  Cloudflare Pages Function

dev/                           ← never deployed
  server.mjs                     local server: node dev/server.mjs
  test.mjs                       22 protocol tests (JS adapter)
  test.php                       63 tests (PHP engine) — run this too
  demo.html                      working reference form
  data/, outbox/                 local submissions and .eml files
```

---

## Adding it to a project

**1. Copy `site/form.js` and `site/api/` into the project's deploy folder.**
If you also want the form working on Cloudflare previews, copy `site/functions/`
too — the client's Apache ignores it, and Cloudflare ignores the `.php` files.

**2. Describe the form in `api/forms.json`.**

```json
{
  "forms": {
    "rezervacia": {
      "label": "Rezervácia",
      "to": ["klient@gmail.com"],
      "subject": "Nová rezervácia — {meno}, {datum}",
      "replyTo": "email",
      "minSeconds": 3,
      "maxPerHour": 5,
      "fields": {
        "meno":   { "type": "text",  "label": "Meno",   "required": true, "max": 100 },
        "email":  { "type": "email", "label": "E-mail", "required": true },
        "datum":  { "type": "date",  "label": "Dátum",  "required": true, "min": "today" },
        "suhlas": { "type": "consent", "label": "Súhlas", "required": true }
      }
    }
  }
}
```

`{meno}` in `subject` is replaced with the submitted value. `replyTo` names the
field holding the visitor's address, so the client can hit Reply and be talking
to them — this is the bit that makes a self-hosted form feel as good as a paid
service.

**Field types:** `text` `textarea` `email` `tel` `url` `date` `time` `int`
`number` `select` `checkbox` `consent` `hidden`

**Rules:** `required`, `min` / `max` (length for text, value for numbers, a date
or `today` / `+2 years` for dates), `pattern` (regex), `options` (for `select`,
the value must be one of them), `maxLinks` (URLs tolerated in free text before
it counts towards the spam score).

Anything not in the schema is **dropped**, so a bot cannot inject extra keys
into the stored record or the e-mail.

**3. Write the markup.**

```html
<body data-form-endpoint="/api/form.php">

<form data-form="rezervacia" novalidate
      data-msg-success="Ďakujeme! Ozveme sa vám čo najskôr."
      data-msg-error="Nepodarilo sa odoslať. Zavolajte nám prosím."
      data-msg-required="Skontrolujte zvýraznené polia."
      data-msg-email="Zadajte platnú e-mailovú adresu.">

  <label><span>Meno</span><input type="text" name="meno" required></label>
  <label><span>E-mail</span><input type="email" name="email" required></label>
  <label><span>Dátum</span><input type="date" name="datum" required></label>
  <label><input type="checkbox" name="suhlas" required> Súhlasím…</label>

  <button type="submit" data-form-submit>Odoslať</button>
  <p data-form-status role="status" aria-live="polite" hidden></p>
</form>

<script src="/form.js" defer></script>
```

`data-form` must name a form id from `forms.json`, and `name` attributes must
match the schema. Every message is a `data-msg-*` attribute, which is how the
SK / PL / EN builds stay translated without shipping three scripts. See
`dev/demo.html` for a complete one with styling.

The status paragraph gets `data-state="info|ok|error"` — style those three in
the site's own CSS. Field errors get `aria-invalid` plus a `[data-form-error]`
span, which you can also style.

**4. Configure.** Copy `api/config.example.php` to `api/config.php` and fill in
the secret, the allowed origins and the SMTP mailbox.

**5. Test locally.**

```bash
node dev/server.mjs
```

Open http://localhost:8787, submit, read the `.eml` that appears in
`dev/outbox/`. Then run the protocol tests:

```bash
node dev/test.mjs
```

**6. Run the PHP tests too. This is not optional.**

```bash
php dev/test.php
```

`dev/test.mjs` exercises the JS adapter. It cannot tell you anything about
`site/api`, and for a long time nothing did — the PHP was assumed correct
because the JS passed and the two implement the same rules. It was not.
`lib/Exception.php` declared `private $code`, narrowing the `protected $code`
inherited from PHP's own `Exception`. That error is raised when the class is
*declared*, so the file could not be required, so `form.php` died inside its
`require` block before it set the JSON content type. Every request returned an
**empty HTTP 500 with no body** — nothing in the browser, nothing in the JSON,
no clue. It shipped to a live client site in that state.

`dev/test.php` loads every library in its own process first, so "does this file
even declare cleanly" is asserted rather than assumed, then runs a full
submission through the engine. It needs no `config.php` and no mailbox: it
writes a throwaway config and data directory under the system temp folder,
disables mail, and cleans up after itself.

If you have no PHP: `winget install PHP.PHP.8.5` on Windows. At minimum run
`php -l` over every file in `site/api` before any deploy — that alone would have
caught the outage above.

Change one engine, mirror it in the other, and run **both** suites.

---

## Deploying to websupport.sk

1. **Create a mailbox** in the admin panel, e.g. `web@klient.sk`. This is what
   the engine authenticates as. Do not try to send as the client's Gmail —
   Gmail's SPF record does not authorise the client's web host, and the mail
   gets binned.

2. **FTP the files.** `form.js` and the whole `api/` folder, keeping the
   structure. Leave `dev/` and this README behind.

3. **Permissions.** `api/data/` must be writable — 755 usually works, 775 if
   not. Everything else stays 644.

4. **Fill in `config.php`:**

   ```php
   'secret' => '<64 random hex chars>',
   'allowedOrigins' => array('klient.sk', 'www.klient.sk'),
   'mail' => array(
       'from' => 'web@klient.sk',
       'smtp' => array(
           'host' => 'smtp.m1.websupport.sk',
           'port' => 587,
           'security' => 'tls',
           'user' => 'web@klient.sk',
           'pass' => '<mailbox password>',
       ),
   ),
   'exportToken' => '<another random string>',
   ```

   Generate the secret with `php -r "echo bin2hex(random_bytes(32));"` or
   `node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"`.

5. **Run the self test** — do not skip this:

   ```
   https://klient.sk/api/selftest.php?token=<exportToken>
   ```

   It checks the PHP version, the extensions, whether `data/` is writable,
   whether the domain has an SPF record, and it actually connects to SMTP and
   authenticates, sending one test message to the `from` address. Every line it
   prints is a support call that did not happen.

6. **Confirm `api/data/` is not public.** Open `https://klient.sk/api/data/` —
   it must return 403. If it returns a file listing, the host is ignoring
   `.htaccess`; move `dataDir` above the web root instead.

7. Delete `selftest.php` and `config.example.php` from the server once you are
   happy.

### Cloudflare previews

In the Pages project settings add an environment variable `FORM_SECRET` (any
long random string) and optionally `FORM_ALLOWED_ORIGINS`. On the preview build,
set `data-form-endpoint="/api/form"` — that route is the Pages Function, and it
validates and logs without storing or e-mailing. `wrangler pages deployment
tail` shows each submission.

Production keeps `data-form-endpoint="/api/form.php"`.

---

## What stops spam

No captcha, no third party, no cookies. Layered instead:

- **Signed token.** The browser fetches one before it can post. A bot that posts
  straight at the URL is rejected outright, which removes most drive-by spam.
- **Single use.** A captured token cannot be replayed.
- **Time trap.** The token carries its issue time; filling nine fields in under
  three seconds is not a human.
- **Honeypots.** Two off-screen fields injected by JS, so they are not in the
  HTML a scraper downloads.
- **Origin check** against `allowedOrigins`.
- **Rate limit** per visitor per form, sliding hour and day windows.
- **Content scoring** — links beyond the field's `maxLinks`, `<a>`/BBCode markup,
  a short list of marketing phrases, every field filled with the same value.

Anything over the threshold is **quarantined, not refused**: stored with
`spam: true`, not e-mailed, and reported to the browser as success. A false
positive costs the client a look in the CSV rather than a lost booking, and a
real spammer gets no signal to tune against.

Review quarantined items with:

```
https://klient.sk/api/export.php?form=rezervacia&token=<exportToken>&include=spam
```

---

## Storage and GDPR

Submissions are appended to `data/submissions/<form>/<YYYY-MM>.jsonl`, one JSON
object per line, `flock`ed so parallel writes cannot interleave.

- The visitor's **IP is stored only as a salted hash** — enough to rate-limit,
  not an identifier the client has to declare and defend.
- `retentionDays` (default 365) deletes old submissions automatically.
- Nothing is sent anywhere except the client's own mailbox, so the privacy
  policy needs **no third-party processor for the form**. On rafting-oravec this
  means the Formspree paragraph and its US-transfer clause come out of all three
  privacy pages.
- The engine sets no cookies and touches no localStorage.

`export.php` writes a UTF-8 BOM and `sep=,` so the CSV opens correctly in Excel
on a Slovak Windows, and prefixes any cell starting with `=`, `+`, `-` or `@`
with an apostrophe so a submitted formula cannot execute when the client opens
the file.

---

## Migrating rafting-oravec off Formspree

The existing markup is already close. In `build/templates.js`:

- drop `site.formspreeId` and the `action="https://formspree.io/f/…"` attribute
- change `<form data-form …>` to `<form data-form="rezervacia" …>`
- keep every `data-msg-*` attribute as it is — the names match

In `site/main.js`, delete the whole `form()` module (it ends at the `fetch`
block) and load `form.js` alongside it instead. Then bump the `?v=` on both
script tags — that cache-busting habit exists for a reason on these projects.

Copy `api/` into `site/`, add `forms.json` with the SK field names already used
in the markup (`meno`, `email`, `telefon`, `datum`, `cas`, `pocet_osob`,
`sluzba`, `trasa`, `poznamka`, `suhlas`), and remember `.gitignore` must exclude
`api/config.php` and `api/data/`.

---

## Troubleshooting

**The form says it failed but nothing is in the log.** Open the endpoint
directly in a browser: `https://klient.sk/api/form.php?form=rezervacia&nonce=1`.
You should get JSON. HTML means PHP fatal'd — set `'debug' => true` temporarily
and look at the message.

**Everything works but no e-mail arrives.** The submission is on disk regardless
— check `export.php`. Then run `selftest.php`; it will usually say the SMTP
password is wrong or the host blocks outbound port 587.

**Mail arrives in spam.** The `from` address must be on the site's own domain
and that domain needs an SPF record. `selftest.php` checks for one.

**`bad_nonce` on every submission.** The page was cached with a token older than
`nonceTtl`, or `secret` changed. Both fix themselves on reload.

**Everything is quarantined.** Lower the bar in `forms.json` with a higher
`maxLinks`, or check `spamReasons` in the export to see which rule fired.
