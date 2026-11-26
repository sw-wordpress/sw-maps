<?php

declare(strict_types=1);

namespace SWMaps\Core\Domain;

use SWMaps\Core\Exception\ValidationException;

/**
 * Structured, sanitised marker content. Popups are built from these fields only; every text field is plain text
 * (tags stripped), links are limited to http(s)/mailto/tel, icons to built-in names or media attachments.
 */
final class FeatureProperties
{
    public const BUILTIN_ICONS = ['pin', 'star', 'home', 'flag', 'info', 'camera', 'tree', 'hiking', 'restaurant', 'hotel'];
    public const MAX_TITLE = 200;
    public const MAX_DESCRIPTION = 2000;
    public const MAX_ADDRESS = 300;
    public const MAX_LINK = 2000;
    public const MAX_FIELDS = 10;
    public const MAX_FIELD_LABEL = 60;
    public const MAX_FIELD_VALUE = 300;

    /** @param list<array{label: string, value: string}> $fields structured additional fields (label/value pairs) */
    public function __construct(
        public readonly string $title,
        public readonly string $description,
        public readonly string $address,
        public readonly ?int $imageId,
        public readonly ?string $link,
        public readonly string $icon,
        public readonly array $fields = []
    ) {
    }

    public static function empty(): self
    {
        return new self('', '', '', null, null, 'builtin:pin');
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, ?self $base = null): self
    {
        $base ??= self::empty();
        $errors = [];
        $text = static function (string $key, string $current, int $max, bool $multiline) use ($data, &$errors): string {
            if (!array_key_exists($key, $data)) {
                return $current;
            }
            if (!is_string($data[$key])) {
                $errors['properties.' . $key] = 'Must be a string.';

                return $current;
            }
            $clean = self::plain($data[$key], $multiline);
            if (mb_strlen($clean) > $max) {
                $errors['properties.' . $key] = sprintf('Must not exceed %d characters.', $max);
            }

            return $clean;
        };
        $title = $text('title', $base->title, self::MAX_TITLE, false);
        $description = $text('description', $base->description, self::MAX_DESCRIPTION, true);
        $address = $text('address', $base->address, self::MAX_ADDRESS, false);

        $imageId = $base->imageId;
        if (array_key_exists('image_id', $data)) {
            if ($data['image_id'] !== null && (!is_int($data['image_id']) || $data['image_id'] < 1)) {
                $errors['properties.image_id'] = 'Must be a positive integer or null.';
            } else {
                $imageId = $data['image_id'];
            }
        }
        $link = $base->link;
        if (array_key_exists('link', $data)) {
            $link = null;
            if ($data['link'] !== null && $data['link'] !== '') {
                $link = is_string($data['link']) ? self::safeUrl($data['link']) : null;
                if ($link === null) {
                    $errors['properties.link'] = 'Must be an http(s), mailto or tel link.';
                }
            }
        }
        $icon = $base->icon;
        if (array_key_exists('icon', $data)) {
            if (is_string($data['icon']) && self::validIcon($data['icon'])) {
                $icon = $data['icon'];
            } else {
                $errors['properties.icon'] = 'Unknown icon.';
            }
        }
        $fields = $base->fields;
        if (array_key_exists('fields', $data)) {
            $fields = self::fields($data['fields'], $errors);
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self($title, $description, $address, $imageId, $link, $icon, $fields);
    }

    /**
     * Label/value pairs; plain text only. Entries with an empty label and value are dropped.
     *
     * @param array<string, string> $errors
     * @return list<array{label: string, value: string}>
     */
    private static function fields(mixed $input, array &$errors): array
    {
        if (!is_array($input) || !array_is_list($input) || count($input) > self::MAX_FIELDS) {
            $errors['properties.fields'] = sprintf('Must be a list of at most %d label/value pairs.', self::MAX_FIELDS);

            return [];
        }
        $out = [];
        foreach ($input as $entry) {
            if (!is_array($entry) || !is_string($entry['label'] ?? null) || !is_string($entry['value'] ?? null)) {
                $errors['properties.fields'] = 'Each field needs a text label and value.';

                return [];
            }
            $label = self::plain($entry['label'], false);
            $value = self::plain($entry['value'], false);
            if ($label === '' && $value === '') {
                continue;
            }
            if ($label === '' || mb_strlen($label) > self::MAX_FIELD_LABEL || mb_strlen($value) > self::MAX_FIELD_VALUE) {
                $errors['properties.fields'] = sprintf('Labels are required (max %d characters), values may have %d characters.', self::MAX_FIELD_LABEL, self::MAX_FIELD_VALUE);

                return [];
            }
            $out[] = ['label' => $label, 'value' => $value];
        }

        return $out;
    }

    /**
     * Reads stored data defensively (the database may hold rows written by older versions or other tools): every
     * invalid field falls back to its default; this method never throws.
     *
     * @param array<string, mixed> $data
     */
    public static function lenient(array $data): self
    {
        $result = self::empty();
        foreach (array_keys($data) as $key) {
            try {
                $result = self::fromArray([$key => $data[$key]], $result);
            } catch (ValidationException) {
                continue;
            }
        }

        return $result;
    }

    /** Strips tags and control characters; collapses whitespace (newlines kept for multiline text). */
    public static function plain(string $value, bool $multiline): string
    {
        $value = strip_tags($value);
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        if (!$multiline) {
            $value = (string) preg_replace('/\s+/u', ' ', $value);
        }

        return trim($value);
    }

    public static function safeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > self::MAX_LINK || preg_match('/[\x00-\x20\x7F"<>\\\\]/', $url) === 1) {
            return null;
        }
        if (preg_match('#^https?://[^/?\#]+#i', $url) === 1 || preg_match('/^(mailto|tel):[^\s]+$/i', $url) === 1) {
            return $url;
        }

        return null;
    }

    public static function validIcon(string $icon): bool
    {
        if (str_starts_with($icon, 'builtin:')) {
            return in_array(substr($icon, 8), self::BUILTIN_ICONS, true);
        }

        return preg_match('/^media:[1-9][0-9]{0,18}$/', $icon) === 1;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'address' => $this->address,
            'image_id' => $this->imageId,
            'link' => $this->link,
            'icon' => $this->icon,
            'fields' => $this->fields,
        ];
    }
}
