<?php
/**
 * Stateless, signed submission tokens.
 *
 * The browser asks for one (GET ?nonce=1&form=x) when the form is first
 * interacted with, then sends it back with the POST. Three things fall out of
 * that for free:
 *
 *   - a bot that posts straight at the endpoint without fetching a token first
 *     is rejected outright, which removes most drive-by spam;
 *   - the embedded timestamp gives a "filled in impossibly fast" check without
 *     a hidden timestamp field the bot could rewrite;
 *   - it is HMAC-signed, so nothing needs to be kept server-side and the
 *     engine still works on hosts with no sessions and no database.
 *
 * It is deliberately not bound to the visitor's IP: mobile networks change it
 * mid-session and legitimate submissions would fail.
 */
final class FE_Token
{
    const VERSION = 'v1';

    public static function issue($formId, $secret, $now = null)
    {
        $ts = $now === null ? time() : (int) $now;
        return self::VERSION . '.' . $ts . '.' . self::sign($formId, $ts, $secret);
    }

    /**
     * @return int the issue timestamp
     * @throws FE_Exception
     */
    public static function verify($token, $formId, $secret, $ttl, $now = null)
    {
        $now = $now === null ? time() : (int) $now;

        if (!is_string($token) || $token === '') {
            throw new FE_Exception('bad_nonce', 'Missing token.');
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3 || $parts[0] !== self::VERSION) {
            throw new FE_Exception('bad_nonce', 'Malformed token.');
        }

        $ts = (int) $parts[1];
        if ((string) $ts !== $parts[1]) {
            throw new FE_Exception('bad_nonce', 'Malformed token timestamp.');
        }

        if (!hash_equals(self::sign($formId, $ts, $secret), $parts[2])) {
            throw new FE_Exception('bad_nonce', 'Token signature does not match.');
        }

        // A little clock skew forgiveness, then the normal expiry.
        if ($ts > $now + 60) {
            throw new FE_Exception('bad_nonce', 'Token is from the future.');
        }
        if ($now - $ts > $ttl) {
            throw new FE_Exception('bad_nonce', 'Token expired.');
        }

        return $ts;
    }

    private static function sign($formId, $ts, $secret)
    {
        $payload = self::VERSION . '|' . $formId . '|' . $ts;
        return substr(hash_hmac('sha256', $payload, $secret), 0, 32);
    }
}
