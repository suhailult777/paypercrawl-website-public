<?php
namespace CrawlGuard;

/**
 * Class Toon_Encoder
 * 
 * Handles conversion of data to TOON (Token-Oriented Object Notation) format.
 * A lightweight implementation for WordPress.
 */
class Toon_Encoder {

    /**
     * Encode data to TOON format.
     *
     * @param mixed $data The data to encode.
     * @return string The TOON formatted string.
     */
    public static function encode($data) {
        if (is_array($data) && self::is_uniform_array($data)) {
            return self::encode_tabular($data);
        }

        if (is_array($data) || is_object($data)) {
            return self::encode_object((array)$data);
        }

        return (string)$data;
    }

    /**
     * Check if an array is uniform (array of objects with same keys).
     */
    private static function is_uniform_array($data) {
        if (empty($data) || !is_array($data) || !array_is_list($data)) {
            return false;
        }

        $first_keys = array_keys((array)$data[0]);
        sort($first_keys);

        foreach ($data as $item) {
            if (!is_array($item) && !is_object($item)) return false;
            $keys = array_keys((array)$item);
            sort($keys);
            if ($keys !== $first_keys) return false;
        }

        return true;
    }

    /**
     * Encode a uniform array as a TOON table.
     * key[N]{fields}:
     *   val1,val2
     */
    private static function encode_tabular($data, $indent = 0) {
        if (empty($data)) return '';

        $keys = array_keys((array)$data[0]);
        $header = '{' . implode(',', $keys) . '}';
        $count = '[' . count($data) . ']';
        
        $output = '';
        // Note: In a full implementation, this would be part of a parent key
        // For root array, we just list the rows if it's the top level
        
        foreach ($data as $row) {
            $values = [];
            foreach ($keys as $key) {
                $val = ((array)$row)[$key];
                $values[] = self::escape_csv_value($val);
            }
            $output .= str_repeat('  ', $indent) . implode(',', $values) . "\n";
        }

        return $output;
    }

    /**
     * Encode an associative array/object as TOON key-value pairs.
     */
    private static function encode_object($data, $indent = 0) {
        $output = '';
        foreach ($data as $key => $value) {
            $prefix = str_repeat('  ', $indent) . $key;

            if (is_array($value) && self::is_uniform_array($value)) {
                $keys = array_keys((array)$value[0]);
                $header = '{' . implode(',', $keys) . '}';
                $count = '[' . count($value) . ']';
                $output .= $prefix . $count . $header . ":\n";
                $output .= self::encode_tabular($value, $indent + 1);
            } elseif (is_array($value) || is_object($value)) {
                $output .= $prefix . ":\n";
                $output .= self::encode_object((array)$value, $indent + 1);
            } else {
                $output .= $prefix . ': ' . $value . "\n";
            }
        }
        return $output;
    }

    private static function escape_csv_value($value) {
        if (is_string($value)) {
            // Simple CSV escaping
            if (strpos($value, ',') !== false || strpos($value, "\n") !== false || strpos($value, '"') !== false) {
                return '"' . str_replace('"', '""', $value) . '"';
            }
        }
        return $value;
    }
}

if (!function_exists('array_is_list')) {
    function array_is_list(array $a) {
        return $a === [] || (array_keys($a) === range(0, count($a) - 1));
    }
}
