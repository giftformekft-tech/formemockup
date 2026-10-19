<?php
/**
 * Swap a design's base value (e.g. "Február", "60") for another value in a
 * product name while keeping Hungarian suffixes in vowel harmony:
 * "Februári" → "Szeptemberi", "60-as" → "50-es", "májusban" → "októberben".
 */
if (!defined('ABSPATH')) {
    exit;
}

class MG_Variant_Name_Inflector {
    /** Suffix forms per vowel class: a = back (a), o = back (o), e = front, ö = front rounded. */
    protected static $families = array(
        's'    => array('a' => 'as', 'o' => 'os', 'e' => 'es', 'ö' => 'ös'),
        'ban'  => array('a' => 'ban', 'o' => 'ban', 'e' => 'ben', 'ö' => 'ben'),
        'ba'   => array('a' => 'ba', 'o' => 'ba', 'e' => 'be', 'ö' => 'be'),
        'ra'   => array('a' => 'ra', 'o' => 'ra', 'e' => 're', 'ö' => 're'),
        'nak'  => array('a' => 'nak', 'o' => 'nak', 'e' => 'nek', 'ö' => 'nek'),
        'nál'  => array('a' => 'nál', 'o' => 'nál', 'e' => 'nél', 'ö' => 'nél'),
        'ból'  => array('a' => 'ból', 'o' => 'ból', 'e' => 'ből', 'ö' => 'ből'),
        'ról'  => array('a' => 'ról', 'o' => 'ról', 'e' => 'ről', 'ö' => 'ről'),
        'tól'  => array('a' => 'tól', 'o' => 'tól', 'e' => 'től', 'ö' => 'től'),
        'hoz'  => array('a' => 'hoz', 'o' => 'hoz', 'e' => 'hez', 'ö' => 'höz'),
        'on'   => array('a' => 'on', 'o' => 'on', 'e' => 'en', 'ö' => 'ön'),
        'ok'   => array('a' => 'ok', 'o' => 'ok', 'e' => 'ek', 'ö' => 'ök'),
        'ak'   => array('a' => 'ak', 'o' => 'ak', 'e' => 'ek', 'ö' => 'ek'),
        'i'    => array('a' => 'i', 'o' => 'i', 'e' => 'i', 'ö' => 'i'),
        'ig'   => array('a' => 'ig', 'o' => 'ig', 'e' => 'ig', 'ö' => 'ig'),
        'ként' => array('a' => 'ként', 'o' => 'ként', 'e' => 'ként', 'ö' => 'ként'),
    );

    /**
     * Replace every occurrence of $base in $name with $value.
     *
     * @return string The new name, or '' when $base does not occur in $name.
     */
    public static function replace($name, $base, $value) {
        $name = (string) $name;
        $base = trim((string) $base);
        $value = trim((string) $value);
        if ($name === '' || $base === '' || $value === '') {
            return '';
        }
        $pattern = '/(?<![\p{L}\p{N}])(' . preg_quote($base, '/') . ')(?![\p{N}])(-?)(\p{L}*)/iu';
        $count = 0;
        $result = preg_replace_callback($pattern, function ($match) use ($value) {
            $replacement = self::match_case($match[1], $value);
            $suffix = $match[3];
            if ($suffix !== '') {
                $suffix = self::harmonize($suffix, self::vowel_class($value));
                if (self::is_upper($match[3])) {
                    $suffix = self::upper($suffix);
                }
            }
            return $replacement . $match[2] . $suffix;
        }, $name, -1, $count);
        return ($count > 0 && is_string($result)) ? $result : '';
    }

    /** Vowel class used to pick the suffix form after $value. */
    public static function vowel_class($value) {
        $value = trim((string) $value);
        if (preg_match('/^\d+$/', $value)) {
            return self::number_class((int) $value);
        }
        $lower = self::lower($value);
        $chars = preg_split('//u', $lower, -1, PREG_SPLIT_NO_EMPTY);
        for ($i = count($chars) - 1; $i >= 0; $i--) {
            $char = $chars[$i];
            if (strpos('aáoóuú', $char) !== false) {
                return 'o';
            }
            if (strpos('öőüű', $char) !== false) {
                return 'ö';
            }
            if ($char === 'e') {
                return 'e';
            }
        }
        return 'e';
    }

    /** Numbers take the suffix of their last spoken word: 60 hatvan → -as, 66 hat → -os, 50 ötven → -es. */
    protected static function number_class($number) {
        $units = array(0 => 'a', 1 => 'e', 2 => 'e', 3 => 'a', 4 => 'e', 5 => 'ö', 6 => 'o', 7 => 'e', 8 => 'a', 9 => 'e');
        $tens = array(1 => 'e', 2 => 'a', 3 => 'a', 4 => 'e', 5 => 'e', 6 => 'a', 7 => 'e', 8 => 'a', 9 => 'e');
        if ($number === 0) {
            return 'a';
        }
        if ($number % 10 !== 0) {
            return $units[$number % 10];
        }
        if ($number % 100 !== 0) {
            return $tens[intdiv($number, 10) % 10];
        }
        // száz → -as, ezer → -es.
        return $number % 1000 !== 0 ? 'a' : 'e';
    }

    /** Re-form a suffix chain for a vowel class. Unknown chains are kept unchanged. */
    protected static function harmonize($suffix, $class) {
        $rest = self::lower($suffix);
        $result = '';
        $previous = '';
        while ($rest !== '') {
            $found = false;
            foreach (self::$families as $key => $forms) {
                // A plural after "-as/-es" is -ok/-ek, after anything else -ak/-ek.
                if (($key === 'ak' && $previous === 's') || ($key === 'ok' && $previous !== 's')) {
                    continue;
                }
                foreach (array_unique($forms) as $form) {
                    if (strpos($rest, $form) === 0) {
                        $result .= $forms[$class];
                        $rest = (string) substr($rest, strlen($form));
                        $previous = $key;
                        $found = true;
                        break 2;
                    }
                }
            }
            if (!$found) {
                return $suffix;
            }
        }
        return $result;
    }

    protected static function match_case($original, $value) {
        if (preg_match('/^\d+$/', $value)) {
            return $value;
        }
        if (self::is_upper($original) && self::lower($original) !== $original) {
            return self::upper($value);
        }
        $first = function_exists('mb_substr') ? mb_substr($original, 0, 1, 'UTF-8') : substr($original, 0, 1);
        $value_first = function_exists('mb_substr') ? mb_substr($value, 0, 1, 'UTF-8') : substr($value, 0, 1);
        $value_rest = function_exists('mb_substr') ? mb_substr($value, 1, null, 'UTF-8') : substr($value, 1);
        if (self::lower($first) === $first) {
            return self::lower($value_first) . $value_rest;
        }
        return self::upper($value_first) . $value_rest;
    }

    protected static function is_upper($text) {
        return self::upper($text) === $text;
    }

    protected static function lower($text) {
        return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    }

    protected static function upper($text) {
        return function_exists('mb_strtoupper') ? mb_strtoupper($text, 'UTF-8') : strtoupper($text);
    }
}
