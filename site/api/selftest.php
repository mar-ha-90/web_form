<?php
/**
 * Deployment check. Upload the engine, then open
 *
 *     https://example.sk/api/selftest.php?token=<exportToken>
 *
 * and it tells you exactly what is and is not working on this host — PHP
 * version, whether the data folder is writable, whether the SMTP mailbox
 * accepts the password. Every one of these has been a real "the form silently
 * does nothing" support call; five minutes here saves an afternoon.
 *
 * It needs exportToken set in config.php, so it cannot be probed by strangers,
 * and it never prints the SMTP password.
 *
 * Delete this file once the site is live if you would rather not have it there.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex');

/* Its output describes the server's configuration, and its URL carries the
 * export token, so it is held to the same standard as the other two. */
header('Content-Security-Policy: default-src \'none\'; frame-ancestors \'none\'; base-uri \'none\'; form-action \'none\'; sandbox');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cross-Origin-Resource-Policy: same-origin');
header('Cache-Control: no-store');

require __DIR__ . '/lib/Exception.php';
require __DIR__ . '/lib/Config.php';
require __DIR__ . '/lib/Token.php';
require __DIR__ . '/lib/Storage.php';
require __DIR__ . '/lib/Smtp.php';

$results = array();

function check($label, $ok, $detail = '')
{
    global $results;
    $results[] = ($ok ? '  ok    ' : '  FAIL  ') . str_pad($label, 38) . $detail;
    return $ok;
}

echo "form-engine self test\n=====================\n\n";

/* ------------------------------------------------------------ environment */

check('PHP version >= 7.4', version_compare(PHP_VERSION, '7.4', '>='), PHP_VERSION);
check('json extension', function_exists('json_encode'));
check('mbstring extension', function_exists('mb_strlen'), function_exists('mb_strlen') ? '' : 'optional, but diacritics length checks are less accurate without it');
check('openssl (needed for SMTP over TLS)', extension_loaded('openssl'));
check('random_bytes', function_exists('random_bytes'));
check('hash_hmac', function_exists('hash_hmac'));
check('stream_socket_client (SMTP)', function_exists('stream_socket_client'));
check('mail() available', function_exists('mail'), function_exists('mail') ? '' : 'fallback transport unavailable');

/* ----------------------------------------------------------- configuration */

$config = null;
try {
    $config = FE_Config::load(__DIR__);
    check('config.php + forms.json load', true);
} catch (FE_Exception $e) {
    check('config.php + forms.json load', false, $e->getMessage());
}

if (!$config) {
    echo implode("\n", $results) . "\n\nFix the above before continuing.\n";
    exit;
}

$expected = (string) $config->get('exportToken', '');
$given = isset($_GET['token']) ? (string) $_GET['token'] : '';

if ($expected === '' || !hash_equals($expected, $given)) {
    http_response_code(403);
    echo "Set 'exportToken' in config.php and call this page with ?token=<that value>.\n";
    exit;
}

check('forms defined', count($config->formIds()) > 0, implode(', ', $config->formIds()));

foreach ($config->formIds() as $formId) {
    $form = $config->form($formId);
    check("form '$formId' has a recipient", !empty($form['to']), implode(', ', $form['to']));
    check("form '$formId' has fields", !empty($form['fields']), count($form['fields']) . ' fields');
}

/* -------------------------------------------------------------- filesystem */

$dataDir = $config->get('dataDir');
$writable = false;

if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0775, true);
}
if (is_dir($dataDir)) {
    $probe = $dataDir . '/.selftest';
    $writable = @file_put_contents($probe, 'x') !== false;
    @unlink($probe);
}
check('data folder writable', $writable, $dataDir);

// If the folder is reachable over HTTP the .htaccess is not being honoured,
// which would expose every submission the site has ever received.
$dataUrl = rtrim(dirname(currentUrl()), '/') . '/data/';
check('data folder .htaccess present', is_file($dataDir . '/.htaccess'), $dataUrl . ' should return 403 — check it by hand');

/* ------------------------------------------------------------------ tokens */

$token = FE_Token::issue('selftest', $config->get('secret'));
$verified = true;
try {
    FE_Token::verify($token, 'selftest', $config->get('secret'), 60);
} catch (FE_Exception $e) {
    $verified = false;
}
check('token sign + verify round trip', $verified);

/* -------------------------------------------------------------------- mail */

$transport = $config->path('mail.transport', 'auto');
$smtpHost = (string) $config->path('mail.smtp.host', '');
$from = (string) $config->path('mail.from', '');

check('mail.from is a valid address', (bool) filter_var($from, FILTER_VALIDATE_EMAIL), $from);

if ($from !== '' && strpos($from, '@') !== false) {
    $domain = substr($from, strpos($from, '@') + 1);
    $spf = false;
    if (function_exists('dns_get_record')) {
        foreach ((array) @dns_get_record($domain, DNS_TXT) as $record) {
            if (isset($record['txt']) && stripos($record['txt'], 'v=spf1') === 0) {
                $spf = true;
            }
        }
        check('SPF record on ' . $domain, $spf, $spf ? '' : 'without SPF, Gmail will very likely spam-folder the notifications');
    }
}

if ($transport === 'mail' || $smtpHost === '') {
    check('SMTP configured', false, 'using PHP mail(); deliverability to Gmail is unreliable');
} else {
    $smtp = new FE_Smtp((array) $config->path('mail.smtp', array()));
    try {
        // Connects, does STARTTLS and authenticates, then quits without
        // sending — proves the credentials before a real booking depends on it.
        $smtp->send($from, array($from), buildProbe($from));
        check('SMTP connect + auth + send', true, $smtpHost . ' (a test message was sent to ' . $from . ')');
    } catch (FE_Exception $e) {
        check('SMTP connect + auth', false, $e->getMessage());
    }
}

echo implode("\n", $results) . "\n\n";
echo "Endpoint: " . rtrim(dirname(currentUrl()), '/') . "/form.php\n";
echo "Set <body data-form-endpoint=\"" . parse_url(rtrim(dirname(currentUrl()), '/') . '/form.php', PHP_URL_PATH) . "\"> on your pages.\n";

function buildProbe($from)
{
    return "From: <$from>\r\n"
         . "To: <$from>\r\n"
         . "Subject: form-engine self test\r\n"
         . "Date: " . date('r') . "\r\n"
         . "Content-Type: text/plain; charset=UTF-8\r\n"
         . "\r\n"
         . "If you are reading this, SMTP works and notifications will reach the inbox.\r\n";
}

function currentUrl()
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
    return ($https ? 'https://' : 'http://') . $host . strtok($uri, '?');
}
