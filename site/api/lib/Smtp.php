<?php
/**
 * A small SMTP client, written out rather than pulled in.
 *
 * PHP's mail() hands the message to the host's local sendmail, which on shared
 * hosting is very often not authorised to send as the site's domain. Gmail sees
 * a message that fails SPF/DKIM alignment and files it as spam — and the client
 * never learns that a booking arrived. Authenticating to the domain's own
 * mailbox instead means the mail is signed by the same provider that publishes
 * the DNS records, and it lands in the inbox.
 *
 * Supports STARTTLS on 587, implicit TLS on 465, and AUTH LOGIN / PLAIN, which
 * covers websupport.sk, wedos, active24 and every other host of that shape.
 */
final class FE_Smtp
{
    /** @var resource|null */
    private $conn;
    /** @var array */
    private $opts;
    /** @var string[] transcript, kept for debug output */
    private $trace = array();

    public function __construct(array $opts)
    {
        $this->opts = $opts + array(
            'host'     => '',
            'port'     => 587,
            'security' => 'tls',
            'user'     => '',
            'pass'     => '',
            'timeout'  => 15,
        );
    }

    /**
     * @param string   $from       envelope sender
     * @param string[] $recipients every RCPT TO, including Cc
     * @param string   $message    complete MIME message, headers included
     * @throws FE_Exception
     */
    public function send($from, array $recipients, $message)
    {
        if ($this->opts['host'] === '') {
            throw new FE_Exception('mail_failed', 'No SMTP host configured.');
        }
        if (!$recipients) {
            throw new FE_Exception('mail_failed', 'No recipients.');
        }

        try {
            $this->connect();
            $this->handshake();
            $this->authenticate();

            $this->command('MAIL FROM:<' . $from . '>', 250);
            foreach ($recipients as $rcpt) {
                $this->command('RCPT TO:<' . $rcpt . '>', array(250, 251));
            }

            $this->command('DATA', 354);
            $this->write($this->stuff($message) . "\r\n.\r\n");
            $this->expect(250);

            $this->command('QUIT', array(221, 0));
        } catch (FE_Exception $e) {
            $this->close();
            throw $e;
        }

        $this->close();
    }

    /** @return string[] the SMTP conversation, for the debug log */
    public function trace()
    {
        return $this->trace;
    }

    /* ------------------------------------------------------------ internals */

    private function connect()
    {
        $secure = strtolower($this->opts['security']);
        $host = ($secure === 'ssl' ? 'ssl://' : '') . $this->opts['host'];

        $context = stream_context_create(array(
            'ssl' => array(
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'SNI_enabled'       => true,
                'peer_name'         => $this->opts['host'],
            ),
        ));

        $errno = 0;
        $errstr = '';
        $conn = @stream_socket_client(
            $host . ':' . (int) $this->opts['port'],
            $errno,
            $errstr,
            (int) $this->opts['timeout'],
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$conn) {
            throw new FE_Exception('mail_failed', 'Cannot reach ' . $host . ':' . $this->opts['port'] . ' — ' . $errstr . ' (' . $errno . ')');
        }

        stream_set_timeout($conn, (int) $this->opts['timeout']);
        $this->conn = $conn;
        $this->expect(220);
    }

    private function handshake()
    {
        $ehloName = $this->ehloName();
        $greeting = $this->command('EHLO ' . $ehloName, 250);

        if (strtolower($this->opts['security']) === 'tls') {
            $this->command('STARTTLS', 220);

            $method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            // TLS_CLIENT means "1.0 only" on some older PHP builds; widen it
            // where the newer constants exist.
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            }
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                $method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            }

            if (!@stream_socket_enable_crypto($this->conn, true, $method)) {
                throw new FE_Exception('mail_failed', 'STARTTLS negotiation failed.');
            }

            // The capability list must be re-read inside the TLS session.
            $greeting = $this->command('EHLO ' . $ehloName, 250);
        }

        $this->trace[] = 'CAPS ' . str_replace("\r\n", ' ', trim($greeting));
        $this->capabilities = strtoupper($greeting);
    }

    /** @var string */
    private $capabilities = '';

    private function authenticate()
    {
        if ($this->opts['user'] === '') {
            return;
        }

        if (strpos($this->capabilities, 'AUTH') === false) {
            throw new FE_Exception('mail_failed', 'Server does not advertise AUTH; check the port and security setting.');
        }

        if (strpos($this->capabilities, 'LOGIN') !== false) {
            $this->command('AUTH LOGIN', 334);
            $this->command(base64_encode($this->opts['user']), 334);
            $this->command(base64_encode($this->opts['pass']), 235);
            return;
        }

        if (strpos($this->capabilities, 'PLAIN') !== false) {
            $credentials = base64_encode("\0" . $this->opts['user'] . "\0" . $this->opts['pass']);
            $this->command('AUTH PLAIN ' . $credentials, 235);
            return;
        }

        throw new FE_Exception('mail_failed', 'No supported AUTH mechanism (need LOGIN or PLAIN).');
    }

    /**
     * @param int|int[] $expect acceptable reply codes; 0 in the list means
     *                          "do not care if the connection just drops"
     */
    private function command($line, $expect)
    {
        $this->write($line . "\r\n");
        // Never let a password reach the log.
        $this->trace[] = '> ' . (strpos($line, 'AUTH') === 0 || strlen($line) > 200 ? '[redacted]' : $line);
        return $this->expect($expect);
    }

    private function write($data)
    {
        if (!$this->conn || fwrite($this->conn, $data) === false) {
            throw new FE_Exception('mail_failed', 'Connection lost while writing.');
        }
    }

    private function expect($expect)
    {
        $codes = (array) $expect;
        $response = '';

        while (true) {
            $line = fgets($this->conn, 1024);
            if ($line === false) {
                if (in_array(0, $codes, true)) {
                    return $response;
                }
                $meta = stream_get_meta_data($this->conn);
                $why = !empty($meta['timed_out']) ? 'timed out' : 'closed';
                throw new FE_Exception('mail_failed', 'Connection ' . $why . ' waiting for ' . implode('/', $codes) . '.');
            }

            $response .= $line;
            // "250-EXTENSION" continues, "250 OK" is the last line.
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        $this->trace[] = '< ' . trim($response);
        $code = (int) substr($response, 0, 3);

        if (!in_array($code, $codes, true)) {
            throw new FE_Exception('mail_failed', 'SMTP said "' . trim($response) . '" (wanted ' . implode('/', $codes) . ')');
        }

        return $response;
    }

    /** RFC 5321: a line that begins with a dot gets an extra one. */
    private function stuff($message)
    {
        $message = preg_replace('/\r\n|\r|\n/', "\r\n", $message);
        return preg_replace('/^\./m', '..', $message);
    }

    /**
     * EHLO wants a name the server can make sense of. The site's own hostname
     * is the honest answer; bare 'localhost' gets some servers grumpy.
     */
    private function ehloName()
    {
        $host = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : '';
        if ($host === '' || !preg_match('/^[A-Za-z0-9.\-]+$/', $host)) {
            $host = $this->opts['host'];
        }
        return $host;
    }

    private function close()
    {
        if ($this->conn) {
            @fclose($this->conn);
            $this->conn = null;
        }
    }
}
