<?php
/**
 * Flat-file persistence. No database, because shared hosting makes creating one
 * a manual step per client and this needs to work the moment the files land.
 *
 *   <dataDir>/submissions/<form>/<YYYY-MM>.jsonl   one JSON object per line
 *   <dataDir>/rate/<hash>.json                     sliding-window rate limiter
 *   <dataDir>/nonce/<hash>                         spent single-use tokens
 *   <dataDir>/engine.log                           only when debug is on
 *
 * Appends are flock()ed, so two visitors submitting at the same instant cannot
 * interleave and corrupt a line. Visitor IPs are stored only as a salted hash —
 * enough to rate-limit and to prove abuse, without keeping an identifier the
 * client would then have to declare and defend under GDPR.
 */
final class FE_Storage
{
    /** @var string */
    private $dir;
    /** @var string */
    private $secret;
    /** @var int */
    private $retentionDays;

    public function __construct($dir, $secret, $retentionDays = 365)
    {
        $this->dir = rtrim($dir, "/\\");
        $this->secret = $secret;
        $this->retentionDays = (int) $retentionDays;
    }

    /* ------------------------------------------------------------ writing */

    /**
     * @return string the submission id
     * @throws FE_Exception
     */
    public function save($formId, array $record)
    {
        $id = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $record = array('id' => $id) + $record;

        $path = $this->ensureDir($this->dir . '/submissions/' . $this->safe($formId))
              . '/' . date('Y-m') . '.jsonl';

        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            throw new FE_Exception('server', 'Could not encode the submission as JSON.');
        }

        $fh = @fopen($path, 'ab');
        if (!$fh) {
            throw new FE_Exception('server', 'Cannot write to ' . $path . ' — check folder permissions (755/775).');
        }
        // LOCK_EX makes the append atomic against other PHP workers. It is a
        // no-op on some exotic filesystems, hence writing the whole line in one
        // fwrite as well: a single small write does not tear in practice.
        @flock($fh, LOCK_EX);
        fwrite($fh, $line . "\n");
        @flock($fh, LOCK_UN);
        fclose($fh);

        return $id;
    }

    /* ------------------------------------------------------- rate limiting */

    /**
     * Sliding window per visitor. Called once the submission is otherwise
     * valid, so a visitor cannot burn their allowance on requests that were
     * never going to succeed.
     *
     * @throws FE_Exception when the visitor is over either limit
     */
    public function checkRate($ip, $formId, $maxPerHour, $maxPerDay)
    {
        if ($maxPerHour <= 0 && $maxPerDay <= 0) {
            return;
        }

        $now = time();
        $file = $this->ensureDir($this->dir . '/rate') . '/' . $this->hash($ip . '|' . $formId) . '.json';

        $fh = @fopen($file, 'c+b');
        if (!$fh) {
            // Storage trouble must not take the form down; log and let it pass.
            $this->log('rate limiter could not open ' . $file);
            return;
        }

        @flock($fh, LOCK_EX);
        $body = stream_get_contents($fh);
        $stamps = $body === '' ? array() : json_decode($body, true);
        if (!is_array($stamps)) {
            $stamps = array();
        }

        $stamps = array_values(array_filter($stamps, function ($t) use ($now) {
            return is_int($t) && $t > $now - 86400;
        }));

        $lastHour = count(array_filter($stamps, function ($t) use ($now) {
            return $t > $now - 3600;
        }));

        $over = ($maxPerHour > 0 && $lastHour >= $maxPerHour)
             || ($maxPerDay > 0 && count($stamps) >= $maxPerDay);

        if (!$over) {
            $stamps[] = $now;
        }

        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($stamps));
        @flock($fh, LOCK_UN);
        fclose($fh);

        if ($over) {
            throw new FE_Exception('rate_limited', 'Too many submissions from this address.');
        }
    }

    /* ---------------------------------------------------- single-use tokens */

    /**
     * Marks a token as spent. Returns false if it had already been used, which
     * means someone captured a valid token and is replaying it.
     */
    public function consumeNonce($token, $ttl)
    {
        $dir = $this->ensureDir($this->dir . '/nonce');
        $file = $dir . '/' . $this->hash($token);

        // 'x' fails if the file exists — the check and the claim are one
        // filesystem operation, so two parallel replays cannot both win.
        $fh = @fopen($file, 'xb');
        if (!$fh) {
            return false;
        }
        fclose($fh);

        $this->maybe(20, function () use ($dir, $ttl) {
            $this->pruneOlderThan($dir, time() - max(600, (int) $ttl));
        });

        return true;
    }

    /* --------------------------------------------------------- maintenance */

    /** Deletes stored submissions past the retention window. */
    public function prune()
    {
        if ($this->retentionDays <= 0) {
            return;
        }

        $root = $this->dir . '/submissions';
        if (!is_dir($root)) {
            return;
        }

        $cutoff = time() - ($this->retentionDays * 86400);

        foreach ((array) glob($root . '/*', GLOB_ONLYDIR) as $formDir) {
            foreach ((array) glob($formDir . '/*.jsonl') as $file) {
                // Month files are only ever appended to, so the file's own
                // mtime is the newest record in it. If that is past the cutoff,
                // every line inside is too.
                if (@filemtime($file) < $cutoff) {
                    @unlink($file);
                }
            }
        }
    }

    /** Runs $fn on roughly 1 request in $odds, to keep housekeeping off the hot path. */
    public function maybe($odds, callable $fn)
    {
        if (random_int(1, max(1, (int) $odds)) === 1) {
            $fn();
        }
    }

    public function log($message)
    {
        $line = '[' . date('c') . '] ' . $message . "\n";
        @file_put_contents($this->dir . '/engine.log', $line, FILE_APPEND | LOCK_EX);
    }

    /* ------------------------------------------------------------ reading */

    /** @return array all stored submissions for a form, newest first */
    public function all($formId, $limit = 5000)
    {
        $dir = $this->dir . '/submissions/' . $this->safe($formId);
        $files = (array) glob($dir . '/*.jsonl');
        rsort($files);

        $rows = array();
        foreach ($files as $file) {
            foreach (array_reverse(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) as $line) {
                $row = json_decode($line, true);
                if (is_array($row)) {
                    $rows[] = $row;
                }
                if (count($rows) >= $limit) {
                    return $rows;
                }
            }
        }

        return $rows;
    }

    /** Salted hash — the same visitor always hashes the same way on this site only. */
    public function hash($value)
    {
        return substr(hash_hmac('sha256', (string) $value, $this->secret), 0, 32);
    }

    /* ------------------------------------------------------------ internals */

    private function ensureDir($path)
    {
        if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
            throw new FE_Exception('server', 'Cannot create ' . $path . ' — check folder permissions.');
        }
        return $path;
    }

    private function pruneOlderThan($dir, $cutoff)
    {
        foreach ((array) glob($dir . '/*') as $file) {
            if (is_file($file) && @filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }

    /** Form ids come from forms.json, but never build a path from unchecked input. */
    private function safe($name)
    {
        $name = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $name);
        return $name === '' ? 'form' : $name;
    }
}
