<?php
/**
 * Every failure the engine can report to the browser. The code is what the
 * client switches on; the message is only shown when debug is on, because it
 * can describe server configuration.
 *
 * Codes: bad_request, bad_form, bad_nonce, too_fast, rate_limited, spam,
 *        validation, mail_failed, server
 */
final class FE_Exception extends Exception
{
    /** @var string */
    private $code;
    /** @var array field name => rule that failed */
    private $fields;

    public function __construct($code, $message = '', array $fields = array())
    {
        parent::__construct($message !== '' ? $message : $code);
        $this->code = $code;
        $this->fields = $fields;
    }

    public function errorCode()
    {
        return $this->code;
    }

    public function fields()
    {
        return $this->fields;
    }

    /** HTTP status that fits the failure, so monitoring and logs stay honest. */
    public function status()
    {
        switch ($this->code) {
            case 'rate_limited':
                return 429;
            case 'server':
            case 'mail_failed':
                return 500;
            case 'spam':
            case 'bad_nonce':
                return 403;
            default:
                return 400;
        }
    }
}
