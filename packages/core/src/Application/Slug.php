<?php

declare(strict_types=1);

namespace SWMaps\Core\Application;

final class Slug
{
    public static function from(string $text): string
    {
        $text = mb_strtolower($text);
        $text = strtr($text, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $text = (string) preg_replace('/[^a-z0-9]+/', '-', $text);

        return substr(trim($text, '-'), 0, 100);
    }

    public static function valid(string $slug): bool
    {
        return preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) === 1 && strlen($slug) <= 100;
    }
}
