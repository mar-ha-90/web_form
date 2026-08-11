<?php
/**
 * Builds the notification e-mail and hands it to SMTP or to PHP's mail().
 *
 * The message is multipart/alternative: a plain-text part that stays readable
 * in any client, and a light HTML part that makes a long booking form scannable
 * on a phone. Reply-To is set to the visitor, so the client can hit Reply and
 * be talking to the person who filled the form in — this is the single feature
 * that makes a self-hosted form feel as good as a paid service.
 */
final class FE_Mailer
{
    /** @var FE_Config */
    private $config;
    /** @var FE_Storage */
    private $storage;

    public function __construct(FE_Config $config, FE_Storage $storage)
    {
        $this->config = $config;
        $this->storage = $storage;
    }

    /**
     * @param array $form   form definition from forms.json
     * @param array $values validated values
     * @param array $meta   submission id, timestamp, page url, language
     * @throws FE_Exception when sending fails
     */
    public function send(array $form, array $values, array $meta)
    {
        $transport = $this->config->path('mail.transport', 'auto');
        if (!$this->config->path('mail.enabled', true) || $transport === 'none') {
            return 'skipped';
        }

        $to = $this->addresses($form['to']);
        if (!$to) {
            throw new FE_Exception('server', 'The form has no valid "to" address in forms.json.');
        }
        $cc = $this->addresses($form['cc']);

        $from = trim((string) $this->config->path('mail.from', ''));
        if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new FE_Exception('server', 'mail.from is missing or not a valid address.');
        }
        $fromName = $this->headerSafe($this->config->path('mail.fromName', ''));

        $subject = $this->headerSafe($this->fillTemplate($form['subject'], $values));
        $replyTo = $this->replyTo($form, $values);

        $text = $this->textBody($form, $values, $meta);
        $html = $this->htmlBody($form, $values, $meta);

        $boundary = 'fe-' . bin2hex(random_bytes(12));

        $headers = array(
            'From'         => ($fromName !== '' ? $this->encodeWord($fromName) . ' ' : '') . '<' . $from . '>',
            'To'           => implode(', ', $to),
            'Date'         => date('r'),
            'Message-ID'   => '<' . $meta['id'] . '@' . $this->domainOf($from) . '>',
            'MIME-Version' => '1.0',
            'Content-Type' => 'multipart/alternative; boundary="' . $boundary . '"',
            'X-Mailer'     => 'form-engine',
            'Auto-Submitted' => 'auto-generated',
        );
        if ($cc) {
            $headers['Cc'] = implode(', ', $cc);
        }
        if ($replyTo !== '') {
            $headers['Reply-To'] = $replyTo;
        }

        $body = "This is a message in MIME format.\r\n\r\n"
              . '--' . $boundary . "\r\n"
              . "Content-Type: text/plain; charset=UTF-8\r\n"
              . "Content-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($text), 76, "\r\n")
              . '--' . $boundary . "\r\n"
              . "Content-Type: text/html; charset=UTF-8\r\n"
              . "Content-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($html), 76, "\r\n")
              . '--' . $boundary . "--\r\n";

        $smtpHost = (string) $this->config->path('mail.smtp.host', '');
        $useSmtp = $transport === 'smtp' || ($transport === 'auto' && $smtpHost !== '');

        if ($useSmtp) {
            try {
                $this->viaSmtp($from, array_merge($to, $cc), $headers, $body, $subject);
                return 'smtp';
            } catch (FE_Exception $e) {
                $this->storage->log('SMTP failed: ' . $e->getMessage());
                if ($transport === 'smtp') {
                    throw $e;
                }
                // 'auto' means try hard, then still get the message out somehow.
                $this->storage->log('falling back to mail()');
            }
        }

        $this->viaMailFunction($to, $subject, $headers, $body);
        return 'mail';
    }

    /* ------------------------------------------------------------ transports */

    private function viaSmtp($from, array $recipients, array $headers, $body, $subject)
    {
        $headers = array('Subject' => $this->encodeWord($subject)) + $headers;

        $raw = '';
        foreach ($headers as $name => $value) {
            $raw .= $name . ': ' . $value . "\r\n";
        }
        $raw .= "\r\n" . $body;

        $smtp = new FE_Smtp((array) $this->config->path('mail.smtp', array()));
        $smtp->send($from, $recipients, $raw);

        if ($this->config->get('debug')) {
            $this->storage->log("SMTP ok\n  " . implode("\n  ", $smtp->trace()));
        }
    }

    private function viaMailFunction(array $to, $subject, array $headers, $body)
    {
        // mail() takes To and Subject as its own arguments; leaving them in the
        // header block as well produces duplicates in some MTAs.
        unset($headers['To']);

        $lines = array();
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $envelope = '';
        $from = (string) $this->config->path('mail.from', '');
        if ($from !== '' && !ini_get('safe_mode')) {
            $envelope = '-f' . $from;
        }

        $ok = @mail(
            implode(', ', $to),
            $this->encodeWord($subject),
            $body,
            implode("\r\n", $lines),
            $envelope
        );

        if (!$ok) {
            throw new FE_Exception('mail_failed', 'PHP mail() returned false — the host may have disabled it.');
        }
    }

    /* ---------------------------------------------------------------- bodies */

    private function textBody(array $form, array $values, array $meta)
    {
        $out = array($form['label'], str_repeat('=', max(4, strlen($form['label']))), '');

        foreach ($this->orderedPairs($form, $values) as $label => $value) {
            $out[] = $label . ': ' . $value;
        }

        $out[] = '';
        $out[] = str_repeat('-', 40);
        $out[] = 'Odoslané: ' . $meta['at'];
        if (!empty($meta['page'])) {
            $out[] = 'Stránka: ' . $meta['page'];
        }
        $out[] = 'ID: ' . $meta['id'];

        return implode("\r\n", $out);
    }

    private function htmlBody(array $form, array $values, array $meta)
    {
        $esc = function ($s) {
            return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        };

        $rows = '';
        foreach ($this->orderedPairs($form, $values) as $label => $value) {
            $rows .= '<tr>'
                . '<td style="padding:8px 14px 8px 0;vertical-align:top;color:#6b7280;font-size:13px;white-space:nowrap">' . $esc($label) . '</td>'
                . '<td style="padding:8px 0;vertical-align:top;color:#111827;font-size:15px">' . nl2br($esc($value)) . '</td>'
                . '</tr>';
        }

        return '<!doctype html><html><body style="margin:0;background:#f6f7f9;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif">'
            . '<div style="max-width:640px;margin:0 auto;padding:24px">'
            . '<div style="background:#fff;border-radius:12px;padding:24px;border:1px solid #e5e7eb">'
            . '<h1 style="margin:0 0 4px;font-size:18px;color:#111827">' . $esc($form['label']) . '</h1>'
            . '<p style="margin:0 0 18px;font-size:13px;color:#6b7280">' . $esc($meta['at']) . '</p>'
            . '<table style="width:100%;border-collapse:collapse">' . $rows . '</table>'
            . '</div>'
            . '<p style="margin:16px 4px 0;font-size:12px;color:#9ca3af">'
            . 'ID ' . $esc($meta['id'])
            . (!empty($meta['page']) ? ' &middot; <a href="' . $esc($meta['page']) . '" style="color:#9ca3af">' . $esc($meta['page']) . '</a>' : '')
            . '</p></div></body></html>';
    }

    /**
     * Values in the order forms.json declares them, with the schema's labels.
     * Empty optional fields are left out so the client reads five lines, not
     * fifteen with ten blanks.
     */
    private function orderedPairs(array $form, array $values)
    {
        $pairs = array();

        foreach ($form['fields'] as $name => $rules) {
            if (!array_key_exists($name, $values)) {
                continue;
            }
            $type = isset($rules['type']) ? $rules['type'] : 'text';
            if ($type === 'hidden') {
                continue;
            }

            $value = $values[$name];
            if (is_bool($value)) {
                $value = $value ? 'áno' : 'nie';
            }
            if ($value === '' || $value === null) {
                continue;
            }

            $label = isset($rules['label']) ? $rules['label'] : $name;
            // Two fields could carry the same label; keep both.
            if (isset($pairs[$label])) {
                $label .= ' (' . $name . ')';
            }
            $pairs[$label] = $value;
        }

        return $pairs;
    }

    /* ---------------------------------------------------------------- helpers */

    /** Replaces {field} in the subject with the submitted value. */
    private function fillTemplate($template, array $values)
    {
        return preg_replace_callback('/\{([A-Za-z0-9_]+)\}/', function ($m) use ($values) {
            $v = isset($values[$m[1]]) ? $values[$m[1]] : '';
            if (is_bool($v)) {
                $v = $v ? 'áno' : 'nie';
            }
            return (string) $v;
        }, (string) $template);
    }

    private function replyTo(array $form, array $values)
    {
        $field = isset($form['replyTo']) ? $form['replyTo'] : '';
        if ($field === '' || empty($values[$field])) {
            return '';
        }
        $address = (string) $values[$field];

        return filter_var($address, FILTER_VALIDATE_EMAIL) ? $address : '';
    }

    /** @return string[] valid addresses only */
    private function addresses($list)
    {
        $out = array();
        foreach ((array) $list as $address) {
            $address = trim((string) $address);
            if (filter_var($address, FILTER_VALIDATE_EMAIL)) {
                $out[] = $address;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Last line of defence against header injection. Validator already strips
     * CR and LF from every value, but the subject is built from a template and
     * this costs nothing.
     */
    private function headerSafe($value)
    {
        $value = str_replace(array("\r", "\n", "\0"), ' ', (string) $value);
        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    /** RFC 2047 encoding, so Slovak and Polish subjects survive the trip. */
    private function encodeWord($value)
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value)) {
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function domainOf($address)
    {
        $at = strrpos($address, '@');
        return $at === false ? 'localhost' : substr($address, $at + 1);
    }
}
