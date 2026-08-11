<?php
/**
 * Spam defences that need no third party, no captcha and no cookies.
 *
 * Nothing here blocks outright except the origin check. Everything else adds
 * to a score, and a submission over the threshold is *quarantined*: stored with
 * a spam flag, not e-mailed, and reported to the browser as success. That way a
 * false positive costs the client a look in the CSV rather than a lost booking,
 * and a real spammer gets no feedback to tune against.
 */
final class FE_Spam
{
    /** Score at or above which a submission is quarantined. */
    const THRESHOLD = 5;

    /** Hidden fields the client injects; a human never fills these in. */
    public static $honeypots = array('_website', '_company_url');

    /**
     * Rejects a request whose Origin or Referer is present but points at a host
     * we do not serve. Absent headers are allowed through — curl and some
     * privacy tools omit them, and the signed token already covers that case.
     *
     * @throws FE_Exception
     */
    public static function checkOrigin(array $server, array $allowed)
    {
        if (!$allowed) {
            return;
        }

        $raw = '';
        if (!empty($server['HTTP_ORIGIN'])) {
            $raw = $server['HTTP_ORIGIN'];
        } elseif (!empty($server['HTTP_REFERER'])) {
            $raw = $server['HTTP_REFERER'];
        }
        if ($raw === '') {
            return;
        }

        $host = parse_url($raw, PHP_URL_HOST);
        if (!$host) {
            return;
        }
        $host = strtolower($host);

        foreach ($allowed as $candidate) {
            $candidate = strtolower(trim($candidate));
            if ($candidate === '') {
                continue;
            }
            // A bare entry matches the host and any subdomain of it, so one
            // line covers example.sk and www.example.sk.
            if ($host === $candidate || substr($host, -strlen('.' . $candidate)) === '.' . $candidate) {
                return;
            }
        }

        throw new FE_Exception('bad_request', 'Origin ' . $host . ' is not in allowedOrigins.');
    }

    /**
     * @param array $raw    unfiltered POST body (needed to see the honeypots)
     * @param array $clean  validated values
     * @param array $form   form definition
     * @param int   $tokenAge seconds between token issue and submission
     * @return array{score:int,reasons:string[]}
     */
    public static function evaluate(array $raw, array $clean, array $form, $tokenAge)
    {
        $score = 0;
        $reasons = array();

        foreach (self::$honeypots as $trap) {
            if (isset($raw[$trap]) && trim((string) $raw[$trap]) !== '') {
                $score += 10;
                $reasons[] = 'honeypot:' . $trap;
            }
        }

        $minSeconds = isset($form['minSeconds']) ? (int) $form['minSeconds'] : 3;
        if ($minSeconds > 0 && $tokenAge < $minSeconds) {
            $score += 6;
            $reasons[] = 'too_fast:' . $tokenAge . 's';
        }

        $fields = isset($form['fields']) ? $form['fields'] : array();
        $freeText = array();

        foreach ($clean as $name => $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }
            $type = isset($fields[$name]['type']) ? $fields[$name]['type'] : 'text';
            if ($type === 'text' || $type === 'textarea') {
                $freeText[$name] = $value;
            }

            // A URL in a name or phone box is never a person being helpful.
            if (($type === 'text' || $type === 'tel') && $name !== 'poznamka' && self::countLinks($value) > 0) {
                $score += 4;
                $reasons[] = 'link_in_' . $name;
            }
        }

        foreach ($freeText as $name => $value) {
            $allowance = isset($fields[$name]['maxLinks']) ? (int) $fields[$name]['maxLinks'] : 1;
            $extra = self::countLinks($value) - $allowance;
            if ($extra > 0) {
                $score += 2 * $extra;
                $reasons[] = 'links:' . $name . ':+' . $extra;
            }

            if (preg_match('/<\s*a\s|\[url|\[link|href\s*=/iu', $value)) {
                $score += 3;
                $reasons[] = 'markup:' . $name;
            }

            $hits = self::phraseHits($value);
            if ($hits) {
                $score += 2 * count($hits);
                $reasons[] = 'phrases:' . implode(',', $hits);
            }

            // Long, entirely upper-case blocks are shouty marketing copy.
            $letters = preg_replace('/[^\p{L}]/u', '', $value);
            if (function_exists('mb_strlen') && mb_strlen($letters, 'UTF-8') > 40
                && mb_strtoupper($value, 'UTF-8') === $value) {
                $score += 1;
                $reasons[] = 'shouting:' . $name;
            }
        }

        // Bots that fill every box with the same token.
        $strings = array_filter($clean, 'is_string');
        $nonEmpty = array_filter($strings, 'strlen');
        if (count($nonEmpty) >= 3 && count(array_unique($nonEmpty)) === 1) {
            $score += 4;
            $reasons[] = 'identical_values';
        }

        return array('score' => $score, 'reasons' => $reasons);
    }

    private static function countLinks($value)
    {
        return preg_match_all('#(https?://|www\.)[^\s<>"\']+#iu', $value);
    }

    /**
     * Deliberately short and boring. A long keyword list is how you end up
     * silently eating a legitimate enquiry that happened to mention a price.
     */
    private static function phraseHits($value)
    {
        static $needles = array(
            'seo', 'backlink', 'link building', 'guest post', 'crypto',
            'bitcoin', 'casino', 'viagra', 'cialis', 'loan offer',
            'increase your traffic', 'search engine ranking', 'binary option',
        );

        $hay = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        $hits = array();

        foreach ($needles as $needle) {
            // Word-boundary match so "seo" does not fire inside a Slovak word.
            if (preg_match('/(?<![\p{L}])' . preg_quote($needle, '/') . '(?![\p{L}])/u', $hay)) {
                $hits[] = $needle;
            }
        }

        return $hits;
    }
}
