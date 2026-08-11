<?php
/**
 * Orchestration: turns one HTTP request into one stored submission and one
 * notification e-mail.
 *
 * The order of the checks matters. Cheap, certain rejections come first so a
 * flood costs almost nothing, and the rate limiter is consulted only once a
 * request is otherwise legitimate — so an attacker cannot use rubbish requests
 * to exhaust a real visitor's allowance.
 */
final class FE_Engine
{
    /** @var FE_Config */
    private $config;
    /** @var FE_Storage */
    private $storage;

    public function __construct(FE_Config $config)
    {
        $this->config = $config;
        $this->storage = new FE_Storage(
            $config->get('dataDir'),
            $config->get('secret'),
            $config->get('retentionDays')
        );
    }

    /**
     * @return array the JSON body to send back
     * @throws FE_Exception
     */
    public function handle(array $server, array $get, array $post)
    {
        $method = isset($server['REQUEST_METHOD']) ? strtoupper($server['REQUEST_METHOD']) : 'GET';

        FE_Spam::checkOrigin($server, (array) $this->config->get('allowedOrigins', array()));

        if ($method === 'GET') {
            return $this->issueToken($get);
        }
        if ($method !== 'POST') {
            throw new FE_Exception('bad_request', 'Use GET for a token or POST to submit.');
        }

        return $this->submit($server, $post);
    }

    /* ---------------------------------------------------------------- tokens */

    private function issueToken(array $get)
    {
        $formId = isset($get['form']) ? (string) $get['form'] : '';
        if (!$this->config->hasForm($formId)) {
            throw new FE_Exception('bad_form', 'Unknown form "' . $formId . '".');
        }

        return array(
            'ok'    => true,
            'nonce' => FE_Token::issue($formId, $this->config->get('secret')),
            'ttl'   => (int) $this->config->get('nonceTtl'),
        );
    }

    /* ----------------------------------------------------------- submissions */

    private function submit(array $server, array $post)
    {
        $formId = isset($post['_form']) ? (string) $post['_form'] : '';
        if (!$this->config->hasForm($formId)) {
            throw new FE_Exception('bad_form', 'Unknown form "' . $formId . '".');
        }
        $form = $this->config->form($formId);

        $issuedAt = FE_Token::verify(
            isset($post['_nonce']) ? $post['_nonce'] : '',
            $formId,
            $this->config->get('secret'),
            (int) $this->config->get('nonceTtl')
        );

        if (!$this->storage->consumeNonce($post['_nonce'], (int) $this->config->get('nonceTtl'))) {
            throw new FE_Exception('bad_nonce', 'This token has already been used.');
        }

        $values = FE_Validator::run($form['fields'], $post);

        $verdict = FE_Spam::evaluate($post, $values, $form, time() - $issuedAt);
        $quarantined = $verdict['score'] >= FE_Spam::THRESHOLD;

        $ip = $this->clientIp($server);
        if (!$quarantined) {
            $this->storage->checkRate($ip, $formId, (int) $form['maxPerHour'], (int) $form['maxPerDay']);
        }

        $meta = array(
            'form'   => $formId,
            'at'     => date('Y-m-d H:i:s'),
            'atIso'  => date('c'),
            'page'   => $this->cleanUrl(isset($post['_page']) ? $post['_page'] : ''),
            'lang'   => preg_replace('/[^a-z\-]/i', '', substr((string) (isset($post['_lang']) ? $post['_lang'] : ''), 0, 5)),
            'ipHash' => $this->storage->hash($ip),
            'ua'     => substr((string) (isset($server['HTTP_USER_AGENT']) ? $server['HTTP_USER_AGENT'] : ''), 0, 200),
        );

        $id = 'not-stored';
        if (!empty($form['store'])) {
            $record = $meta + array(
                'spam'        => $quarantined,
                'spamScore'   => $verdict['score'],
                'spamReasons' => $verdict['reasons'],
                'values'      => $values,
            );
            $id = $this->storage->save($formId, $record);
            $this->storage->maybe(50, array($this->storage, 'prune'));
        }
        $meta['id'] = $id;

        if ($quarantined) {
            $this->storage->log('quarantined ' . $id . ' score=' . $verdict['score'] . ' ' . implode(' ', $verdict['reasons']));
            // Report success. A spammer learns nothing, and a wrongly-flagged
            // visitor is not told to try again in a way that would fail twice.
            return array('ok' => true, 'id' => $id);
        }

        $mailer = new FE_Mailer($this->config, $this->storage);

        try {
            $transport = $mailer->send($form, $values, $meta);
        } catch (FE_Exception $e) {
            $this->storage->log('mail failed for ' . $id . ': ' . $e->getMessage());
            // The submission is on disk, so nothing is lost even though the
            // notification did not go out. Only admit failure to the visitor if
            // we had nowhere to put it.
            if (empty($form['store'])) {
                throw $e;
            }
            return array('ok' => true, 'id' => $id, 'mail' => 'deferred');
        }

        return array('ok' => true, 'id' => $id, 'mail' => $transport);
    }

    /* ---------------------------------------------------------------- helpers */

    /**
     * REMOTE_ADDR unless the site sits behind a proxy that has been explicitly
     * declared. Trusting a forwarded header by default would let anyone spoof
     * their way past the rate limiter with one extra request header.
     */
    private function clientIp(array $server)
    {
        $header = (string) $this->config->get('trustedProxyHeader', '');
        if ($header !== '') {
            $key = 'HTTP_' . strtoupper(str_replace('-', '_', $header));
            if (!empty($server[$key])) {
                $first = trim(explode(',', $server[$key])[0]);
                if (filter_var($first, FILTER_VALIDATE_IP)) {
                    return $first;
                }
            }
        }

        return isset($server['REMOTE_ADDR']) ? $server['REMOTE_ADDR'] : '0.0.0.0';
    }

    private function cleanUrl($url)
    {
        $url = substr((string) $url, 0, 300);
        return preg_match('#^https?://#i', $url) ? $url : '';
    }
}
