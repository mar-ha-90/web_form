<?php
/**
 * CSV export of stored submissions — the client's safety net when an e-mail
 * gets lost, and where quarantined spam ends up for review.
 *
 *   https://example.sk/api/export.php?form=rezervacia&token=<exportToken>
 *   &include=spam   also list quarantined submissions
 *   &since=2026-01-01
 *
 * Disabled entirely while exportToken is empty, which is the default.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

require __DIR__ . '/lib/Exception.php';
require __DIR__ . '/lib/Config.php';
require __DIR__ . '/lib/Storage.php';

header('X-Robots-Tag: noindex');

/* This endpoint hands over every stored booking, so it gets the same treatment
 * as form.php regardless of whether the server config was applied. no-referrer
 * matters more here than anywhere: the URL contains the export token. */
header('Content-Security-Policy: default-src \'none\'; frame-ancestors \'none\'; base-uri \'none\'; form-action \'none\'; sandbox');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cross-Origin-Resource-Policy: same-origin');
header('Cache-Control: no-store');

/**
 * Excel and LibreOffice treat a cell beginning =, +, - or @ as a formula. A
 * visitor who types "=cmd|'/c calc'!A1" into the message box would otherwise be
 * running code on the client's machine the moment they open the export. A
 * leading apostrophe makes it literal text.
 */
function fe_csv_safe($value)
{
    $value = (string) $value;
    if ($value !== '' && strpos("=+-@\t\r", $value[0]) !== false) {
        return "'" . $value;
    }
    return $value;
}

try {
    $config = FE_Config::load(__DIR__);

    $expected = (string) $config->get('exportToken', '');
    $given = isset($_GET['token']) ? (string) $_GET['token'] : '';

    // hash_equals keeps the comparison constant-time; strlen check first
    // because hash_equals returns false immediately on a length mismatch and
    // we want the same 403 either way.
    if ($expected === '' || !hash_equals($expected, $given)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Forbidden.\n";
        exit;
    }

    $formId = isset($_GET['form']) ? (string) $_GET['form'] : '';
    if (!$config->hasForm($formId)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Unknown form. Available: " . implode(', ', $config->formIds()) . "\n";
        exit;
    }

    $form = $config->form($formId);
    $storage = new FE_Storage($config->get('dataDir'), $config->get('secret'), $config->get('retentionDays'));

    $includeSpam = isset($_GET['include']) && $_GET['include'] === 'spam';
    $since = isset($_GET['since']) ? strtotime((string) $_GET['since']) : false;

    $columns = array('id', 'at', 'lang', 'spam', 'spamScore');
    foreach ($form['fields'] as $name => $rules) {
        $columns[] = $name;
    }
    $columns[] = 'page';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $formId . '-' . date('Y-m-d') . '.csv"');

    $out = fopen('php://output', 'w');

    // UTF-8 BOM: without it Excel on a Czech/Slovak Windows opens the file in
    // CP1250 and every diacritic is mangled. The client will open this in Excel.
    fwrite($out, "\xEF\xBB\xBF");

    // Excel also needs telling that the separator is a comma when the system
    // list separator is a semicolon, which it is in this locale.
    fwrite($out, "sep=,\r\n");

    fputcsv($out, $columns);

    foreach ($storage->all($formId) as $row) {
        if (empty($includeSpam) && !empty($row['spam'])) {
            continue;
        }
        if ($since !== false && isset($row['atIso']) && strtotime($row['atIso']) < $since) {
            continue;
        }

        $values = isset($row['values']) && is_array($row['values']) ? $row['values'] : array();
        $line = array();

        foreach ($columns as $column) {
            if ($column === 'page') {
                $line[] = isset($row['page']) ? $row['page'] : '';
                continue;
            }
            if (in_array($column, array('id', 'at', 'lang', 'spam', 'spamScore'), true)) {
                $v = isset($row[$column]) ? $row[$column] : '';
                $line[] = is_bool($v) ? ($v ? 'yes' : 'no') : $v;
                continue;
            }
            $v = isset($values[$column]) ? $values[$column] : '';
            $line[] = is_bool($v) ? ($v ? 'yes' : 'no') : $v;
        }

        fputcsv($out, array_map('fe_csv_safe', $line));
    }

    fclose($out);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Export failed.\n";
}
