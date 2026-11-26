<?php

declare(strict_types=1);

namespace SWMaps\Core\Import;

/** Maps source attributes to feature fields. Explicit mapping wins over the automatic name guess. */
final class FieldMapper
{
    public const TARGETS = ['title', 'description', 'address', 'link', 'external_id'];
    private const GUESS = [
        'title' => ['title', 'name', 'label', 'titel'],
        'description' => ['description', 'desc', 'cmt', 'beschreibung', 'info'],
        'address' => ['address', 'addr', 'adresse'],
        'link' => ['link', 'url', 'website', 'web'],
        'external_id' => ['external_id', 'id', 'uid', 'guid'],
    ];
    private const MEDIA_COLUMNS = ['image', 'img', 'photo', 'picture', 'media', 'icon', 'bild', 'foto'];

    /**
     * @param list<string> $columns
     * @return array<string, string> target => source column (only targets that have a source)
     */
    public static function suggest(array $columns): array
    {
        $lower = [];
        foreach ($columns as $column) {
            $lower[strtolower($column)] ??= $column;
        }
        $out = [];
        foreach (self::GUESS as $target => $names) {
            foreach ($names as $name) {
                if (isset($lower[$name])) {
                    $out[$target] = $lower[$name];
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string, string> $attributes
     * @param array<string, string> $mapping target => source column; an empty source disables the target
     * @return array<string, string> target => value (empty values omitted)
     */
    public static function apply(array $attributes, array $mapping): array
    {
        $out = [];
        foreach ($mapping as $target => $source) {
            if (in_array($target, self::TARGETS, true) && $source !== '' && isset($attributes[$source]) && $attributes[$source] !== '') {
                $out[$target] = $attributes[$source];
            }
        }

        return $out;
    }

    /** @param list<string> $columns */
    public static function hasMediaColumns(array $columns): bool
    {
        foreach ($columns as $column) {
            if (in_array(strtolower($column), self::MEDIA_COLUMNS, true)) {
                return true;
            }
        }

        return false;
    }
}
