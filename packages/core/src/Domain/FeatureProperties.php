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

    public function __construct(
        public readonly string $title,
        public readonly string $description,
        public readonly string $address,
        public readonly ?int $imageId,
        public readonly ?string $link,
        public readonly string $icon
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
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self($title, $description, $address, $imageId, $link, $icon);
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
        ];
    }
}
