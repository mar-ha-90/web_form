<?php
/**
 * PHP-side test suite.
 *
 *     php dev/test.php
 *
 * Companion to dev/test.mjs, which covers the Node/Cloudflare adapter. This one
 * covers site/api, and it exists because that side went unexercised for months:
 * every test ran against the JS adapter, the PHP was assumed fine by symmetry,
 * and it was not. lib/Exception.php declared `private $code`, narrowing the
 * `protected $code` it inherits from PHP's built-in Exception. That is raised
 * when the class is declared, so the file could not be required at all, so
 * form.php died inside its require block before setting its JSON content type.
 * Every request returned an empty HTTP 500 with no body. It reached production.
 *
 * The first group below is therefore the important one: each library is loaded
 * in its own process, so "does this file even declare cleanly" is asserted
 * rather than assumed. Everything after that is behaviour.
 *
 * Needs no config.php and no mailbox: it writes a throwaway config and data
 * directory under the system temp folder, disables mail, and removes them at
 * the end. Nothing here touches dev/data or dev/outbox.
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

$API = realpath(__DIR__ . '/../site/api');
if ($API === false) {
    fwrite(STDERR, "Cannot find site/api next to dev/\n");
    exit(1);
}

$pass = 0;
$fail = 0;

function ok($label, $cond, $detail = '')
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ok    $label" . ($detail !== '' ? "  ($detail)" : '') . "\n";
    } else {
        $fail++;
        echo "  FAIL  $label" . ($detail !== '' ? "  ($detail)" : '') . "\n";
    }
}

function group($name)
{
    echo "\n$name\n";
}

echo "form-engine PHP tests — PHP " . PHP_VERSION . "\n";
echo str_repeat('=', 46) . "\n";

/* ------------------------------------------------------------------------ */
/* 1. Every library must DECLARE cleanly.                                     */
/*                                                                            */
/* Each in its own process, so the first fatal does not mask the rest, and so  */
/* a class-declaration error is caught rather than a mere syntax error. This   */
/* is the group that would have caught the outage.                            */
/* ------------------------------------------------------------------------ */

group('libraries load');

$libs = array('Exception', 'Config', 'Token', 'Validator', 'Spam', 'Storage', 'Smtp', 'Mailer', 'Engine');
foreach ($libs as $lib) {
    $file = $API . '/lib/' . $lib . '.php';
    $cmd = escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -r '
         . escapeshellarg('require ' . var_export($file, true) . '; echo "LOADED";') . ' 2>&1';
    $out = array();
    exec($cmd, $out);
    $text = trim(implode(' ', $out));
    ok("lib/$lib.php", strpos($text, 'LOADED') !== false, strpos($text, 'LOADED') !== false ? '' : $text);
}

/* The endpoints too — they are what the browser actually hits. */
foreach (array('form.php', 'selftest.php', 'export.php') as $entry) {
    $out = array();
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($API . '/' . $entry) . ' 2>&1', $out, $code);
    ok($entry, $code === 0, $code === 0 ? '' : trim(implode(' ', $out)));
}

require $API . '/lib/Exception.php';
require $API . '/lib/Config.php';
require $API . '/lib/Token.php';
require $API . '/lib/Validator.php';
require $API . '/lib/Spam.php';
require $API . '/lib/Storage.php';
require $API . '/lib/Smtp.php';
require $API . '/lib/Mailer.php';
require $API . '/lib/Engine.php';

/* ------------------------------------------------------------------------ */

group('FE_Exception');

$e = new FE_Exception('bad_nonce', 'Malformed token.');
ok('errorCode() round trips', $e->errorCode() === 'bad_nonce', $e->errorCode());
ok('getMessage() survives', $e->getMessage() === 'Malformed token.');
ok('message defaults to the code', (new FE_Exception('spam'))->getMessage() === 'spam');
ok('fields() round trips', (new FE_Exception('validation', '', array('email' => 'email')))->fields() === array('email' => 'email'));
ok('rate_limited -> 429', (new FE_Exception('rate_limited'))->status() === 429);
ok('server -> 500', (new FE_Exception('server'))->status() === 500);
ok('mail_failed -> 500', (new FE_Exception('mail_failed'))->status() === 500);
ok('bad_nonce -> 403', (new FE_Exception('bad_nonce'))->status() === 403);
ok('spam -> 403', (new FE_Exception('spam'))->status() === 403);
ok('validation -> 400', (new FE_Exception('validation'))->status() === 400);
// It is still a real Exception, so form.php's catch blocks behave.
ok('is catchable as Exception', $e instanceof Exception);
ok('is catchable as Throwable', $e instanceof Throwable);

/* ------------------------------------------------------------------------ */

$tmp = sys_get_temp_dir() . '/form-engine-test-' . bin2hex(random_bytes(4));
mkdir($tmp . '/data', 0775, true);

$SECRET = 'test-secret-not-used-anywhere-real-0123456789abcdef';
file_put_contents($API . '/config.php', "<?php\nreturn " . var_export(array(
    'secret'         => $SECRET,
    'allowedOrigins' => array('example.sk', 'www.example.sk'),
    'dataDir'        => $tmp . '/data',
    'retentionDays'  => 180,
    'nonceTtl'       => 7200,
    'store'          => true,
    'exportToken'    => '',
    'debug'          => false,
    'mail'           => array('enabled' => false, 'transport' => 'none', 'from' => 'web@example.sk'),
), true) . ";\n");

/* config.php is gitignored and must never survive a test run, however this
 * script exits. */
register_shutdown_function(function () use ($API, $tmp) {
    @unlink($API . '/config.php');
    rrmdir($tmp);
});

function rrmdir($dir)
{
    if (!is_dir($dir)) return;
    foreach (array_diff(scandir($dir), array('.', '..')) as $f) {
        $p = $dir . '/' . $f;
        is_dir($p) ? rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

$config = FE_Config::load($API);
$engine = new FE_Engine($config);

$server = array(
    'REQUEST_METHOD'  => 'GET',
    'REMOTE_ADDR'     => '203.0.113.9',
    'HTTP_ORIGIN'     => 'https://www.example.sk',
    'HTTP_USER_AGENT' => 'form-engine tests',
    'SERVER_NAME'     => 'www.example.sk',
);

/* A token is a deterministic HMAC of form id + issue time, so two asked for in
 * the same second are the same string and the second is refused as a replay.
 * Each test needs its own second. */
$backdate = 30;
function freshNonce($formId = 'rezervacia')
{
    global $SECRET, $backdate;
    return FE_Token::issue($formId, $SECRET, time() - ($backdate++));
}

/* ------------------------------------------------------------------------ */

group('tokens');

$tok = $engine->handle($server, array('form' => 'rezervacia', 'nonce' => '1'), array());
ok('GET issues a token', !empty($tok['ok']) && !empty($tok['nonce']));
ok('ttl reported', $tok['ttl'] === 7200, (string) $tok['ttl']);
ok('response is json encodable', json_encode($tok, JSON_UNESCAPED_UNICODE) !== false);
ok('verify accepts its own token', FE_Token::verify($tok['nonce'], 'rezervacia', $SECRET, 7200) > 0);

try {
    FE_Token::verify($tok['nonce'], 'kontakt', $SECRET, 7200);
    ok('token is bound to its form', false, 'a rezervacia token verified as kontakt');
} catch (FE_Exception $ex) {
    ok('token is bound to its form', $ex->errorCode() === 'bad_nonce');
}
try {
    FE_Token::verify($tok['nonce'], 'rezervacia', 'a-different-secret', 7200);
    ok('token is bound to the secret', false, 'it verified under another secret');
} catch (FE_Exception $ex) {
    ok('token is bound to the secret', $ex->errorCode() === 'bad_nonce');
}
try {
    FE_Token::verify(FE_Token::issue('rezervacia', $SECRET, time() - 99999), 'rezervacia', $SECRET, 7200);
    ok('expired token refused', false, 'it was accepted');
} catch (FE_Exception $ex) {
    ok('expired token refused', $ex->errorCode() === 'bad_nonce');
}

/* ------------------------------------------------------------------------ */

group('a real submission');

$server['REQUEST_METHOD'] = 'POST';
$post = array(
    '_form'      => 'rezervacia',
    '_nonce'     => freshNonce(),
    '_page'      => 'https://www.example.sk/',
    '_lang'      => 'sk',
    'meno'       => 'Ján Novák',
    'email'      => 'JAN@Example.SK',
    'telefon'    => '+421 900 123 456',
    'datum'      => date('Y-m-d', strtotime('+3 days')),
    'cas'        => '10:00',
    'pocet_osob' => '4',
    'sluzba'     => 'Rafting s inštruktorom',
    'trasa'      => 'Červený Kláštor – Lesnica (9 km)',
    'poznamka'   => 'Dve deti, 8 a 11 rokov.',
    'suhlas'     => 'on',
);

$res = $engine->handle($server, array(), $post);
ok('accepted', !empty($res['ok']), json_encode($res, JSON_UNESCAPED_UNICODE));
ok('id issued', !empty($res['id']) && $res['id'] !== 'not-stored', isset($res['id']) ? $res['id'] : '');
ok('mail reported skipped', isset($res['mail']) && $res['mail'] === 'skipped', isset($res['mail']) ? $res['mail'] : '(none)');

$files = glob($tmp . '/data/submissions/rezervacia/*.jsonl');
ok('written to jsonl', !empty($files), $files ? basename($files[0]) : 'nothing');

$row = $files ? json_decode(trim(file_get_contents($files[0])), true) : array();
ok('record is valid json', is_array($row) && !empty($row));
ok('email lowercased', isset($row['values']['email']) && $row['values']['email'] === 'jan@example.sk', isset($row['values']['email']) ? $row['values']['email'] : '');
ok('int cast', isset($row['values']['pocet_osob']) && $row['values']['pocet_osob'] === 4);
ok('consent stored as bool', isset($row['values']['suhlas']) && $row['values']['suhlas'] === true);
ok('diacritics intact', isset($row['values']['trasa']) && $row['values']['trasa'] === 'Červený Kláštor – Lesnica (9 km)');
ok('not flagged as spam', isset($row['spam']) && $row['spam'] === false, 'score ' . (isset($row['spamScore']) ? $row['spamScore'] : '?'));
ok('ip kept only as a hash', empty($row['ip']) && !empty($row['ipHash']));

/* ------------------------------------------------------------------------ */

group('rejections');

try {
    $engine->handle($server, array(), $post);
    ok('replayed nonce refused', false, 'it was accepted twice');
} catch (FE_Exception $ex) {
    ok('replayed nonce refused', $ex->errorCode() === 'bad_nonce', $ex->errorCode() . ' / ' . $ex->status());
}

$bad = $post;
$bad['_nonce'] = freshNonce();
$bad['email'] = 'not-an-email';
unset($bad['suhlas']);
try {
    $engine->handle($server, array(), $bad);
    ok('invalid fields refused', false, 'it was accepted');
} catch (FE_Exception $ex) {
    $f = $ex->fields();
    ok('invalid fields refused', $ex->errorCode() === 'validation', $ex->errorCode());
    ok('  email named', isset($f['email']) && $f['email'] === 'email', json_encode($f));
    ok('  consent named', isset($f['suhlas']) && $f['suhlas'] === 'required');
    ok('  status 400', $ex->status() === 400);
}

$past = $post;
$past['_nonce'] = freshNonce();
$past['datum'] = date('Y-m-d', strtotime('-1 day'));
try {
    $engine->handle($server, array(), $past);
    ok('past date refused', false, 'it was accepted');
} catch (FE_Exception $ex) {
    $f = $ex->fields();
    ok('past date refused', isset($f['datum']) && $f['datum'] === 'min', json_encode($f));
}

$optn = $post;
$optn['_nonce'] = freshNonce();
$optn['trasa'] = 'A route that is not in forms.json';
try {
    $engine->handle($server, array(), $optn);
    ok('off-schema select refused', false, 'it was accepted');
} catch (FE_Exception $ex) {
    $f = $ex->fields();
    ok('off-schema select refused', isset($f['trasa']) && $f['trasa'] === 'option', json_encode($f));
}

/* Header injection: a CR or LF smuggled into a single-line field must never
 * survive validation. A textarea is the one type that legitimately keeps \n,
 * so it must keep it — and must still lose \r. */
$inject = $post;
$inject['_nonce'] = freshNonce();
$inject['meno'] = "Ján\r\nBcc: victim@example.com";
$inject['poznamka'] = "prvý riadok\r\ndruhý riadok";
$engine->handle($server, array(), $inject);
$rows = file(glob($tmp . '/data/submissions/rezervacia/*.jsonl')[0], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$last = json_decode(end($rows), true);
$meno = $last['values']['meno'];
$pozn = $last['values']['poznamka'];
ok('CR/LF stripped from a single-line field', strpos($meno, "\r") === false && strpos($meno, "\n") === false, json_encode($meno));
ok('  the text itself is kept', $meno === 'JánBcc: victim@example.com', json_encode($meno));
ok('textarea keeps its newline', strpos($pozn, "\n") !== false, json_encode($pozn));
ok('  but loses the CR', strpos($pozn, "\r") === false, json_encode($pozn));

try {
    $engine->handle($server, array('form' => 'nope', 'nonce' => '1'), array());
    ok('unknown form refused', false, 'it was accepted');
} catch (FE_Exception $ex) {
    ok('unknown form refused', $ex->errorCode() === 'bad_form', $ex->errorCode());
}

try {
    $evil = $server;
    $evil['HTTP_ORIGIN'] = 'https://evil.example.com';
    $engine->handle($evil, array('form' => 'rezervacia', 'nonce' => '1'), array());
    ok('foreign origin refused', false, 'it was accepted');
} catch (FE_Exception $ex) {
    ok('foreign origin refused', $ex->errorCode() === 'bad_request', $ex->errorCode());
}

/* A subdomain of an allowed host is allowed; a lookalike is not. */
$sub = $server;
$sub['REQUEST_METHOD'] = 'GET';
$sub['HTTP_ORIGIN'] = 'https://shop.example.sk';
$engine->handle($sub, array('form' => 'rezervacia', 'nonce' => '1'), array());
ok('subdomain of an allowed host is allowed', true);
try {
    $look = $sub;
    $look['HTTP_ORIGIN'] = 'https://notexample.sk';
    $engine->handle($look, array('form' => 'rezervacia', 'nonce' => '1'), array());
    ok('lookalike host refused', false, 'notexample.sk was accepted');
} catch (FE_Exception $ex) {
    ok('lookalike host refused', $ex->errorCode() === 'bad_request');
}

/* ------------------------------------------------------------------------ */

group('spam handling');

$server['REQUEST_METHOD'] = 'POST';
$trap = $post;
$trap['_nonce'] = freshNonce();
$trap['_website'] = 'http://spam.example';
$res3 = $engine->handle($server, array(), $trap);
ok('honeypot reported as success', !empty($res3['ok']), json_encode($res3));
ok('  but not mailed', !isset($res3['mail']));

$rows = file(glob($tmp . '/data/submissions/rezervacia/*.jsonl')[0], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$q = json_decode(end($rows), true);
ok('  stored with spam:true', isset($q['spam']) && $q['spam'] === true, 'score ' . $q['spamScore']);

$fast = $post;
$fast['_nonce'] = FE_Token::issue('rezervacia', $SECRET, time());  // filled in instantly
$res4 = $engine->handle($server, array(), $fast);
$rows = file(glob($tmp . '/data/submissions/rezervacia/*.jsonl')[0], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$f2 = json_decode(end($rows), true);
ok('too-fast submission scored', $f2['spamScore'] >= 6, 'score ' . $f2['spamScore'] . ' ' . implode(',', $f2['spamReasons']));

/* ------------------------------------------------------------------------ */

group('the second form in forms.json');

$server['REQUEST_METHOD'] = 'GET';
$k = $engine->handle($server, array('form' => 'kontakt', 'nonce' => '1'), array());
ok('kontakt issues a token', !empty($k['nonce']));
$server['REQUEST_METHOD'] = 'POST';
$kp = array(
    '_form'  => 'kontakt',
    '_nonce' => freshNonce('kontakt'),
    'meno'   => 'Anna Kováčová',
    'email'  => 'anna@example.sk',
    'sprava' => 'Dobrý deň, mám otázku ohľadom termínu.',
    'suhlas' => 'on',
);
$kr = $engine->handle($server, array(), $kp);
ok('kontakt accepts a submission', !empty($kr['ok']), json_encode($kr, JSON_UNESCAPED_UNICODE));

/* ------------------------------------------------------------------------ */

echo "\n" . str_repeat('-', 46) . "\n";
echo "  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
