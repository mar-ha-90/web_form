<?php
/**
 * Form engine — the only file the browser talks to.
 *
 *   GET  form.php?form=<id>&nonce=1   ->  { ok, nonce, ttl }
 *   POST form.php  (multipart or urlencoded, with _form and _nonce)
 *                                     ->  { ok, id }  or  { ok:false, error, fields }
 *
 * Drop this folder next to index.html, copy config.example.php to config.php,
 * edit forms.json, done. No Composer, no database, no build step.
 */

// Warnings must never end up in the response body — a stray notice would make
// the JSON unparseable and the visitor would see a generic failure on a form
// that actually worked.
ini_set('display_errors', '0');
error_reporting(E_ALL);

require __DIR__ . '/lib/Exception.php';
require __DIR__ . '/lib/Config.php';
require __DIR__ . '/lib/Token.php';
require __DIR__ . '/lib/Validator.php';
require __DIR__ . '/lib/Spam.php';
require __DIR__ . '/lib/Storage.php';
require __DIR__ . '/lib/Smtp.php';
require __DIR__ . '/lib/Mailer.php';
require __DIR__ . '/lib/Engine.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

/* Set here as well as in .htaccess/web.config on purpose. Those two are the
 * server's job and either can be missing — .htaccess does nothing on IIS,
 * web.config does nothing on Apache, and a host that ignores both leaves this
 * endpoint bare. PHP always runs, so these always apply. Duplicates are
 * harmless: the server's copy replaces, it does not append. */
header('Content-Security-Policy: default-src \'none\'; frame-ancestors \'none\'; base-uri \'none\'; form-action \'none\'; sandbox');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cross-Origin-Resource-Policy: same-origin');

$debug = false;

try {
    $config = FE_Config::load(__DIR__);
    $debug = (bool) $config->get('debug');

    $engine = new FE_Engine($config);
    $result = $engine->handle($_SERVER, $_GET, $_POST);

    http_response_code(200);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (FE_Exception $e) {
    http_response_code($e->status());

    $payload = array('ok' => false, 'error' => $e->errorCode());
    if ($e->fields()) {
        $payload['fields'] = $e->fields();
    }
    if ($debug) {
        $payload['message'] = $e->getMessage();
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    // PHP 7+ Throwable also catches TypeError and friends, so a bug in the
    // engine still produces JSON rather than a half-rendered HTML error page.
    http_response_code(500);
    echo json_encode(array(
        'ok'      => false,
        'error'   => 'server',
        'message' => $debug ? $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() : null,
    ), JSON_UNESCAPED_UNICODE);
}
