<?php
/**
 * Schema-driven validation against the field definitions in forms.json.
 *
 * Anything the schema does not describe is dropped rather than passed through,
 * so a bot cannot inject extra keys into the stored record or the e-mail body.
 * Errors come back keyed by field name; the client paints them next to the
 * inputs and the visitor never sees a wall of text.
 */
final class FE_Validator
{
    /**
     * @return array clean values, keyed by field name
     * @throws FE_Exception with per-field rule names when something is wrong
     */
    public static function run(array $fields, array $input)
    {
        $clean = array();
        $errors = array();

        foreach ($fields as $name => $rules) {
            $rules += array('type' => 'text', 'required' => false, 'label' => $name);
            $raw = isset($input[$name]) ? $input[$name] : null;

            // Arrays only ever arrive from multi-selects and duplicated names;
            // collapse them so the rest of the code deals with scalars.
            if (is_array($raw)) {
                $raw = implode(', ', array_map('strval', $raw));
            }
            $value = $raw === null ? '' : trim((string) $raw);
            $value = self::stripControlChars($value);

            $type = $rules['type'];
            $required = !empty($rules['required']);

            if ($type === 'consent' || $type === 'checkbox') {
                $checked = self::isTruthy($value);
                if ($required && !$checked) {
                    $errors[$name] = 'required';
                    continue;
                }
                $clean[$name] = $checked;
                continue;
            }

            if ($value === '') {
                if ($required) {
                    $errors[$name] = 'required';
                    continue;
                }
                $clean[$name] = '';
                continue;
            }

            $error = self::checkOne($type, $value, $rules);
            if ($error !== null) {
                $errors[$name] = $error;
                continue;
            }

            $clean[$name] = self::normalise($type, $value);
        }

        if ($errors) {
            throw new FE_Exception('validation', 'One or more fields are invalid.', $errors);
        }

        return $clean;
    }

    /** @return string|null rule name that failed, or null when the value is fine */
    private static function checkOne($type, $value, array $rules)
    {
        $len = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);

        if (isset($rules['max']) && !self::isNumericType($type) && !self::isDateType($type)) {
            if ($len > (int) $rules['max']) {
                return 'max';
            }
        }
        if (isset($rules['min']) && !self::isNumericType($type) && !self::isDateType($type)) {
            if ($len < (int) $rules['min']) {
                return 'min';
            }
        }
        if (isset($rules['pattern']) && !preg_match('/' . str_replace('/', '\\/', $rules['pattern']) . '/u', $value)) {
            return 'pattern';
        }

        switch ($type) {
            case 'email':
                // Belt and braces: filter_var accepts some addresses that no
                // real mail server will, so also insist on a dotted domain.
                if (!filter_var($value, FILTER_VALIDATE_EMAIL) || !preg_match('/@[^@\s]+\.[a-z]{2,}$/iu', $value)) {
                    return 'email';
                }
                break;

            case 'tel':
                // Permissive on purpose — people write numbers every which way.
                // We only insist it could plausibly be dialled.
                $digits = preg_replace('/\D+/', '', $value);
                if (strlen($digits) < 6 || strlen($digits) > 20 || !preg_match('/^[0-9+()\/\s.\-]+$/u', $value)) {
                    return 'tel';
                }
                break;

            case 'url':
                if (!filter_var($value, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $value)) {
                    return 'url';
                }
                break;

            case 'int':
            case 'number':
                if ($type === 'int' && !preg_match('/^-?\d+$/', $value)) {
                    return 'type';
                }
                if (!is_numeric($value)) {
                    return 'type';
                }
                $n = $value + 0;
                if (isset($rules['min']) && $n < $rules['min'] + 0) {
                    return 'min';
                }
                if (isset($rules['max']) && $n > $rules['max'] + 0) {
                    return 'max';
                }
                break;

            case 'date':
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                    return 'type';
                }
                list($y, $m, $d) = array_map('intval', explode('-', $value));
                if (!checkdate($m, $d, $y)) {
                    return 'type';
                }
                $stamp = strtotime($value . ' 00:00:00');
                if (isset($rules['min']) && $stamp < self::relativeDate($rules['min'])) {
                    return 'min';
                }
                if (isset($rules['max']) && $stamp > self::relativeDate($rules['max'])) {
                    return 'max';
                }
                break;

            case 'time':
                if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value)) {
                    return 'type';
                }
                break;

            case 'select':
                if (isset($rules['options']) && is_array($rules['options']) && !in_array($value, $rules['options'], true)) {
                    return 'option';
                }
                break;

            case 'text':
            case 'textarea':
            case 'hidden':
                break;

            default:
                // An unknown type in forms.json is a mistake in the site, not in
                // the visitor's input — treat it as free text rather than
                // rejecting a submission the visitor cannot possibly fix.
                break;
        }

        return null;
    }

    private static function normalise($type, $value)
    {
        switch ($type) {
            case 'email':
                return strtolower($value);
            case 'int':
                return (int) $value;
            case 'number':
                return $value + 0;
            case 'tel':
                // Collapse runs of whitespace but keep the visitor's formatting.
                return preg_replace('/\s+/u', ' ', $value);
            default:
                return $value;
        }
    }

    /** Accepts 'today', '+2 years', '2027-01-01' … */
    private static function relativeDate($spec)
    {
        if ($spec === 'today') {
            return strtotime('today 00:00:00');
        }
        $stamp = strtotime($spec);
        return $stamp === false ? 0 : $stamp;
    }

    private static function isNumericType($type)
    {
        return $type === 'int' || $type === 'number';
    }

    private static function isDateType($type)
    {
        return $type === 'date' || $type === 'time';
    }

    private static function isTruthy($value)
    {
        return in_array(strtolower($value), array('1', 'on', 'true', 'yes', 'ano', 'tak'), true);
    }

    /**
     * Strip C0/C1 controls except tab and newline. This is what stops header
     * injection dead: a CR or LF smuggled into a value can never reach a mail
     * header because it does not survive validation.
     */
    private static function stripControlChars($value)
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        return $value === null ? '' : $value;
    }
}
