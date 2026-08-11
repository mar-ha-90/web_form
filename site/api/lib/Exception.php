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
    /* NOT $code. PHP's own Exception already declares `protected $code`, and a
     * subclass may not narrow an inherited property to private:
     *
     *   Fatal error: Access level to FE_Exception::$code must be protected
     *   (as in class Exception) or weaker
     *
     * That is raised when the class is DECLARED, so the file could not be
     * required at all. form.php died inside its require block, before the line
     * that sets the JSON content type, and every request got an empty HTTP 500
     * with no body and nothing to diagnose from. It shipped to a live site that
     * way, because the PHP path had only ever been exercised through the Node
     * adapter. dev/test.php now covers it.
     *
     * Renaming also keeps the two ideas apart: Exception::$code is an int, this
     * is a string slug like 'bad_nonce'. */
    /** @var string */
    private $errorCode;
    /** @var array field name => rule that failed */
    private $fields;

    public function __construct($code, $message = '', array $fields = array())
    {
        parent::__construct($message !== '' ? $message : $code);
        $this->errorCode = $code;
        $this->fields = $fields;
    }

    public function errorCode()
    {
        return $this->errorCode;
    }

    public function fields()
    {
        return $this->fields;
    }

    /** HTTP status that fits the failure, so monitoring and logs stay honest. */
    public function status()
    {
        switch ($this->errorCode) {
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
