/* Form engine — browser side.
 *
 * Progressive enhancement over ordinary markup: every form keeps working as a
 * plain HTML form if this file fails to load, and nothing here is required for
 * the page to render. Plain ES2015, no dependencies, no build step.
 *
 * Markup contract
 * ---------------
 *   <form data-form="rezervacia" novalidate
 *         data-msg-sending="…" data-msg-success="…" data-msg-error="…"
 *         data-msg-required="…">
 *     <input name="meno" required>
 *     <button type="submit" data-form-submit>Odoslať</button>
 *     <p data-form-status role="status" aria-live="polite" hidden></p>
 *   </form>
 *
 * The endpoint comes from <body data-form-endpoint="/api/form.php">, so the
 * same HTML works on the PHP host and on a Cloudflare preview by changing one
 * attribute.
 *
 * Field names must match forms.json — that file is the single source of truth
 * and the server drops anything it does not recognise.
 */
(function () {
  'use strict';

  var body = document.body;
  var ENDPOINT = body.getAttribute('data-form-endpoint') || '/api/form.php';

  /* Honeypots. These names are also listed in lib/Spam.php; keep them in step.
   * They are injected from JS rather than written into the HTML so that they
   * never appear in the page source a scraper downloads. */
  var HONEYPOTS = ['_website', '_company_url'];

  /* Default copy. Every string is overridable per form via data-msg-*, which is
   * how the SK/PL/EN builds stay translated without shipping three scripts. */
  var FALLBACK = {
    sending: 'Odosielam…',
    success: 'Ďakujeme, správu sme dostali.',
    error: 'Formulár sa nepodarilo odoslať. Skúste to prosím znova.',
    required: 'Vyplňte prosím povinné polia.',
    email: 'Zadajte platnú e-mailovú adresu.',
    tel: 'Zadajte platné telefónne číslo.',
    date: 'Zadajte platný dátum.',
    min: 'Táto hodnota je príliš malá alebo krátka.',
    max: 'Táto hodnota je príliš veľká alebo dlhá.',
    option: 'Vyberte prosím jednu z ponúkaných možností.',
    pattern: 'Hodnota nemá správny tvar.',
    type: 'Hodnota nemá správny tvar.',
    rate: 'Formulár ste odoslali príliš veľa krát. Skúste to prosím neskôr.',
    offline: 'Vyzerá to, že ste offline. Skontrolujte pripojenie a skúste znova.'
  };

  var forms = document.querySelectorAll('[data-form]');
  for (var i = 0; i < forms.length; i++) {
    setup(forms[i]);
  }

  /* ------------------------------------------------------------------ setup */

  function setup(form) {
    if (form.tagName !== 'FORM') return;

    var formId = form.getAttribute('data-form') || form.getAttribute('data-form-id');
    if (!formId) {
      // Without an id the server cannot know which schema to validate against,
      // and guessing would silently post to the wrong form.
      console.warn('[form-engine] <form data-form> needs a form id from forms.json.');
      return;
    }

    var state = {
      id: formId,
      form: form,
      status: form.querySelector('[data-form-status]'),
      button: form.querySelector('[data-form-submit]') || form.querySelector('[type=submit]'),
      msg: readMessages(form),
      nonce: null,
      nonceAt: 0,
      nonceTtl: 7200,
      pending: false,
      done: false
    };

    addHoneypots(form);

    // The token is fetched on first interaction, not on page load: a visitor who
    // scrolls past the form costs the server nothing, and the token's clock
    // starts when they actually begin filling it in.
    var prime = function () { ensureNonce(state); };
    form.addEventListener('focusin', prime, { once: true });
    form.addEventListener('input', prime, { once: true });

    // Clear a field's error as soon as the visitor edits it — leaving stale red
    // text under a box they have just fixed is the most annoying thing a form
    // can do.
    form.addEventListener('input', function (e) {
      if (e.target && e.target.name) clearFieldError(form, e.target.name);
    });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      submit(state);
    });
  }

  function readMessages(form) {
    var msg = {};
    for (var key in FALLBACK) {
      if (!Object.prototype.hasOwnProperty.call(FALLBACK, key)) continue;
      msg[key] = form.getAttribute('data-msg-' + key) || FALLBACK[key];
    }
    return msg;
  }

  function addHoneypots(form) {
    HONEYPOTS.forEach(function (name) {
      if (form.querySelector('[name="' + name + '"]')) return;

      var wrap = document.createElement('div');
      // Off-screen rather than display:none — some bots skip hidden inputs, and
      // aria-hidden plus tabindex keeps it away from screen readers and keyboard
      // users, who would otherwise land in a box with no label.
      wrap.setAttribute('aria-hidden', 'true');
      wrap.style.cssText = 'position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden';

      var input = document.createElement('input');
      input.type = 'text';
      input.name = name;
      input.tabIndex = -1;
      input.autocomplete = 'off';
      input.value = '';

      wrap.appendChild(input);
      form.appendChild(wrap);
    });
  }

  /* ------------------------------------------------------------------ token */

  function ensureNonce(state) {
    var fresh = state.nonce && (Date.now() - state.nonceAt) / 1000 < state.nonceTtl - 60;
    if (fresh) return Promise.resolve(state.nonce);

    var url = ENDPOINT + (ENDPOINT.indexOf('?') === -1 ? '?' : '&') +
              'nonce=1&form=' + encodeURIComponent(state.id);

    return fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (!data || !data.ok || !data.nonce) throw new Error('no token');
        state.nonce = data.nonce;
        state.nonceAt = Date.now();
        if (data.ttl) state.nonceTtl = data.ttl;
        return state.nonce;
      })
      .catch(function (err) {
        // Swallowed on purpose: a failed prefetch must not throw an unhandled
        // rejection into the console on a page the visitor is just reading.
        // submit() asks again and reports properly if it still fails.
        state.nonce = null;
        return null;
      });
  }

  /* ----------------------------------------------------------------- submit */

  function submit(state) {
    var form = state.form;
    if (state.pending || state.done) return;

    clearAllErrors(form);

    // Let the browser do the first pass. It knows the visitor's language for
    // built-in messages and it is instant.
    if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
      var bad = form.querySelector(':invalid');
      if (bad) {
        markField(form, bad.name, state.msg.required);
        focus(bad);
      }
      say(state, 'error', state.msg.required);
      return;
    }

    if (!navigator.onLine) {
      say(state, 'error', state.msg.offline);
      return;
    }

    setPending(state, true);
    say(state, 'info', state.msg.sending);

    ensureNonce(state)
      .then(function (nonce) {
        var payload = new FormData(form);
        payload.set('_form', state.id);
        payload.set('_nonce', nonce || '');
        payload.set('_page', location.href.split('#')[0]);
        payload.set('_lang', document.documentElement.lang || '');

        return fetch(ENDPOINT, {
          method: 'POST',
          body: payload,
          headers: { Accept: 'application/json' },
          credentials: 'same-origin'
        });
      })
      .then(function (res) {
        return res.json().catch(function () {
          // A non-JSON body means PHP died before the engine could answer —
          // usually a missing config.php or a fatal on an old PHP version.
          throw new Error('http ' + res.status);
        }).then(function (data) {
          return { status: res.status, data: data };
        });
      })
      .then(function (result) {
        var data = result.data;

        if (data && data.ok) {
          succeed(state);
          return;
        }

        // The token is single-use, so any rejection burns it. Drop it or the
        // visitor's retry fails for a different reason than the first attempt.
        state.nonce = null;

        fail(state, data);
      })
      .catch(function () {
        state.nonce = null;
        setPending(state, false);
        say(state, 'error', state.msg.error);
      });
  }

  function succeed(state) {
    state.done = true;
    setPending(state, false);
    say(state, 'ok', state.msg.success);

    // Hide the inputs rather than the whole form, so the status message the
    // visitor just earned stays on screen and keeps its aria-live region.
    var fields = state.form.querySelectorAll('input, select, textarea, button');
    for (var i = 0; i < fields.length; i++) {
      fields[i].disabled = true;
    }
    state.form.setAttribute('data-form-done', '');

    if (state.status && typeof state.status.scrollIntoView === 'function') {
      state.status.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    // Anything the site wants to hang off a successful submission — a GA event,
    // a thank-you redirect — can listen for this instead of patching this file.
    state.form.dispatchEvent(new CustomEvent('form-engine:success', {
      bubbles: true,
      detail: { form: state.id }
    }));
  }

  function fail(state, data) {
    setPending(state, false);

    var code = data && data.error ? data.error : 'error';

    if (code === 'validation' && data.fields) {
      var first = null;
      for (var name in data.fields) {
        if (!Object.prototype.hasOwnProperty.call(data.fields, name)) continue;
        var rule = data.fields[name];
        var text = state.msg[rule] || state.msg.required;
        var el = markField(state.form, name, text);
        if (!first) first = el;
      }
      say(state, 'error', state.msg.required);
      if (first) focus(first);
      return;
    }

    if (code === 'rate_limited') {
      say(state, 'error', state.msg.rate);
      return;
    }

    say(state, 'error', state.msg.error);
  }

  /* ------------------------------------------------------------------- view */

  function setPending(state, pending) {
    state.pending = pending;
    if (!state.button) return;

    state.button.disabled = pending;
    state.button.setAttribute('aria-busy', pending ? 'true' : 'false');
  }

  /** @param kind 'info' | 'ok' | 'error' — styled by the site's own CSS */
  function say(state, kind, text) {
    var el = state.status;
    if (!el) return;

    el.textContent = text;
    el.setAttribute('data-state', kind);
    el.hidden = false;
    el.classList.remove('hidden');
  }

  function markField(form, name, text) {
    if (!name) return null;

    var field = form.querySelector('[name="' + cssEscape(name) + '"]');
    if (!field) return null;

    field.setAttribute('aria-invalid', 'true');

    var id = 'fe-err-' + name;
    var note = form.querySelector('#' + cssEscape(id));

    if (!note) {
      note = document.createElement('span');
      note.id = id;
      note.setAttribute('data-form-error', '');
      note.style.cssText = 'display:block;margin-top:.35rem;font-size:.8125rem;color:#b91c1c';
      // After the field's own wrapper where there is one, so the message does
      // not land between the label and the input.
      var host = field.closest('label') || field.parentNode;
      host.appendChild(note);
    }

    note.textContent = text;
    field.setAttribute('aria-describedby', id);

    return field;
  }

  function clearFieldError(form, name) {
    var field = form.querySelector('[name="' + cssEscape(name) + '"]');
    if (field) {
      field.removeAttribute('aria-invalid');
      field.removeAttribute('aria-describedby');
    }
    var note = form.querySelector('#fe-err-' + cssEscape(name));
    if (note && note.parentNode) note.parentNode.removeChild(note);
  }

  function clearAllErrors(form) {
    var notes = form.querySelectorAll('[data-form-error]');
    for (var i = 0; i < notes.length; i++) {
      notes[i].parentNode.removeChild(notes[i]);
    }
    var marked = form.querySelectorAll('[aria-invalid]');
    for (var j = 0; j < marked.length; j++) {
      marked[j].removeAttribute('aria-invalid');
      marked[j].removeAttribute('aria-describedby');
    }
  }

  function focus(el) {
    if (!el || typeof el.focus !== 'function') return;
    el.focus({ preventScroll: false });
  }

  /** Field names come from forms.json, but they still land in a selector. */
  function cssEscape(value) {
    if (window.CSS && typeof window.CSS.escape === 'function') return window.CSS.escape(value);
    return String(value).replace(/["\\\]\[#.:>+~*^$|=()]/g, '\\$&');
  }
})();
