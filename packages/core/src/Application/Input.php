<?php

declare(strict_types=1);

namespace SWMaps\Core\Application;

use SWMaps\Core\Exception\ValidationException;

/** Small typed accessors for decoded request bodies. */
final class Input
{
    /** @param array<string, mixed> $data */
    public static function string(array $data, string $key, bool $required, int $max): ?string
    {
        if (!array_key_exists($key, $data) || ($data[$key] === null && !$required)) {
            if ($required) {
                throw ValidationException::field($key, 'Required.');
            }

            return null;
        }
        if (!is_string($data[$key])) {
            throw ValidationException::field($key, 'Must be a string.');
        }
        $value = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', strip_tags($data[$key])));
        if (mb_strlen($value) > $max) {
            throw ValidationException::field($key, sprintf('Must not exceed %d characters.', $max));
        }
        if ($required && $value === '') {
            throw ValidationException::field($key, 'Must not be empty.');
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    public static function bool(array $data, string $key): ?bool
    {
        if (!array_key_exists($key, $data)) {
            return null;
        }
        if (!is_bool($data[$key])) {
            throw ValidationException::field($key, 'Must be a boolean.');
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    public static function int(array $data, string $key, int $min, int $max): ?int
    {
        if (!array_key_exists($key, $data)) {
            return null;
        }
        if (!is_int($data[$key]) || $data[$key] < $min || $data[$key] > $max) {
            throw ValidationException::field($key, sprintf('Must be an integer between %d and %d.', $min, $max));
        }

        return $data[$key];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public static function object(array $data, string $key): ?array
    {
        if (!array_key_exists($key, $data)) {
            return null;
        }
        if (!is_array($data[$key]) || ($data[$key] !== [] && array_is_list($data[$key]))) {
            throw ValidationException::field($key, 'Must be an object.');
        }

        // JSON object keys such as "1" arrive as int keys in PHP; normalise to strings.
        $object = [];
        foreach ($data[$key] as $k => $v) {
            $object[(string) $k] = $v;
        }

        return $object;
    }

    /**
     * Style objects: shallow map of short keys to scalars (colour, opacity, width ...), bounded in size.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public static function style(array $data, string $key): ?array
    {
        $style = self::object($data, $key);
        if ($style === null) {
            return null;
        }
        if (count($style) > 20) {
            throw ValidationException::field($key, 'Too many style entries.');
        }
        $out = [];
        foreach ($style as $k => $v) {
            if ($k === 'rules') {
                $out['rules'] = self::rules($v, $key);
                continue;
            }
            if (preg_match('/^[a-zA-Z][a-zA-Z0-9]{0,31}$/', $k) !== 1) {
                throw ValidationException::field($key, 'Invalid style key.');
            }
            if (!is_scalar($v) && $v !== null) {
                throw ValidationException::field($key, 'Style values must be scalars.');
            }
            if (is_string($v) && (strlen($v) > 64 || preg_match('/^[#a-zA-Z0-9 .,()%_:-]*$/', $v) !== 1)) {
                throw ValidationException::field($key, 'Invalid style value.');
            }
            $out[$k] = $v;
        }

        return $out;
    }

    /**
     * Layer style rules: "locations in category N look like this". At most 10 rules; each style is a flat style map.
     *
     * @return list<array{categoryId: int, style: array<string, mixed>}>
     */
    private static function rules(mixed $value, string $key): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 10) {
            throw ValidationException::field($key, 'Rules must be a list of at most 10 entries.');
        }
        $rules = [];
        foreach ($value as $rule) {
            $id = is_array($rule) ? ($rule['categoryId'] ?? null) : null;
            if (!is_int($id) || $id < 1 || !is_array($rule['style'] ?? null) || isset($rule['style']['rules'])) {
                throw ValidationException::field($key, 'Each rule needs a categoryId and a style.');
            }
            $rules[] = ['categoryId' => $id, 'style' => self::style(['style' => $rule['style']], 'style') ?? []];
        }

        return $rules;
    }
}
