<?php
/**
 * Per-site configuration. Copy to config.php and fill in.
 *
 * config.php holds a mailbox password, so it must NEVER be committed to git
 * and never be readable over HTTP. The .htaccess in this folder blocks direct
 * requests, but the real protection is that PHP files are executed, not served.
 * If your host lets you write above the web root, point 'dataDir' there too.
 */

return array(

    /**
     * Random string used to sign submission nonces. Generate a fresh one per
     * site — anything long and random will do:
     *     php -r "echo bin2hex(random_bytes(32));"
     * Changing it invalidates in-flight form loads (harmless).
     */
    'secret' => 'CHANGE-ME-to-64-random-hex-chars',

    /**
     * Hosts allowed to post to this endpoint. Requests whose Origin/Referer is
     * present but not on the list are rejected. Include every hostname the site
     * answers on, plus your Cloudflare Pages preview domain while testing.
     * Leave the array empty to disable the check entirely (not recommended).
     */
    'allowedOrigins' => array(
        'raftingoravec-pieniny.sk',
        'www.raftingoravec-pieniny.sk',
        'rafting-oravec.pages.dev',
        'localhost',
    ),

    /**
     * Where submissions are written. Default is ./data next to this file.
     * Better, if the host allows it: somewhere above the web root, e.g.
     *     dirname($_SERVER['DOCUMENT_ROOT']) . '/form-data'
     */
    'dataDir' => __DIR__ . '/data',

    /** Delete stored submissions older than this many days. 0 = keep forever. */
    'retentionDays' => 365,

    /** How long a form page stays submittable after load, in seconds. */
    'nonceTtl' => 7200,

    /** Write every submission to disk as well as e-mailing it. */
    'store' => true,

    'mail' => array(
        'enabled' => true,

        /**
         * auto — use SMTP when a host is configured, otherwise PHP mail()
         * smtp — SMTP only; fail loudly if it does not work
         * mail — PHP mail() only
         * none — never send (storage only; useful while testing)
         */
        'transport' => 'auto',

        /**
         * Envelope sender. This MUST be a mailbox on the site's own domain, or
         * SPF fails and Gmail bins the message. Never put the visitor's address
         * here — their address goes in Reply-To.
         */
        'from'     => 'web@raftingoravec-pieniny.sk',
        'fromName' => 'Rafting Oravec — web',

        /**
         * websupport.sk SMTP: smtp.m1.websupport.sk, port 587, STARTTLS,
         * username = the full e-mail address, password = that mailbox's password.
         * Create the mailbox in the admin panel first.
         */
        'smtp' => array(
            'host'     => 'smtp.m1.websupport.sk',
            'port'     => 587,
            'security' => 'tls',   // 'tls' = STARTTLS on 587, 'ssl' = implicit on 465, 'none'
            'user'     => 'web@raftingoravec-pieniny.sk',
            'pass'     => 'CHANGE-ME',
            'timeout'  => 15,
        ),
    ),

    /**
     * Secret for downloading stored submissions as CSV:
     *     https://example.sk/api/export.php?form=rezervacia&token=...
     * Leave empty to disable the export endpoint completely.
     */
    'exportToken' => '',

    /**
     * Verbose error messages and a plain-text log in dataDir. Turn OFF in
     * production — it leaks configuration detail into HTTP responses.
     */
    'debug' => false,
);
