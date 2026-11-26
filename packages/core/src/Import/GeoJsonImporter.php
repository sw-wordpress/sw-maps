<?php

declare(strict_types=1);

namespace SWMaps\Core\Import;

use SWMaps\Core\Domain\Geometry;
use SWMaps\Core\Exception\InvalidGeometryException;

/** RFC 7946 FeatureCollection / Feature / bare geometry. GeometryCollections are split into one feature per member. */
final class GeoJsonImporter implements ImporterInterface
{
    public function parse(string $content, ImportLimits $limits, array $options = []): ParseResult
    {
        ParseCollector::guardSize($content, $limits);
        $content = ltrim($content, "\xEF\xBB\xBF");
        try {
            $data = json_decode($content, true, max(1, $limits->maxDepth), JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            if ($e->getCode() === JSON_ERROR_DEPTH) {
                throw new ImportException(ImportException::TOO_DEEP, 'The file is nested too deeply.');
            }
            throw new ImportException(ImportException::MALFORMED, 'The file is not valid JSON.');
        }
        if (!is_array($data) || !isset($data['type']) || !is_string($data['type'])) {
            throw new ImportException(ImportException::MALFORMED, 'The file is not a GeoJSON object.');
        }
        $collector = new ParseCollector($limits);
        $index = 0;
        if ($data['type'] === 'FeatureCollection') {
            $features = $data['features'] ?? null;
            if (!is_array($features) || !array_is_list($features)) {
                throw new ImportException(ImportException::MALFORMED, 'The FeatureCollection has no features list.');
            }
            foreach ($features as $item) {
                $this->feature($item, ++$index, $collector);
            }
        } elseif ($data['type'] === 'Feature') {
            $this->feature($data, ++$index, $collector);
        } else {
            $this->geometry($data, [], ++$index, $collector);
        }

        return $collector->result();
    }

    private function feature(mixed $item, int $index, ParseCollector $collector): void
    {
        if (!is_array($item) || ($item['type'] ?? null) !== 'Feature') {
            $collector->error($index, 'not_a_feature', 'Entry is not a GeoJSON Feature.');

            return;
        }
        $geometry = $item['geometry'] ?? null;
        if (!is_array($geometry)) {
            $collector->error($index, 'no_geometry', 'Feature has no geometry.');

            return;
        }
        $attributes = $this->attributes($item['properties'] ?? null);
        if (isset($item['id']) && (is_string($item['id']) || is_int($item['id'])) && !isset($attributes['id'])) {
            $attributes['id'] = (string) $item['id'];
        }
        $this->geometry($geometry, $attributes, $index, $collector);
    }

    /**
     * @param array<mixed> $geometry
     * @param array<string, string> $attributes
     */
    private function geometry(array $geometry, array $attributes, int $index, ParseCollector $collector, int &$part = 0): void
    {
        if (($geometry['type'] ?? null) === 'GeometryCollection') {
            $members = $geometry['geometries'] ?? null;
            if (!is_array($members) || $members === []) {
                $collector->error($index, 'collection_empty', 'GeometryCollection has no members.');

                return;
            }
            $collector->warn($index, 'collection_split', 'GeometryCollection was split into separate features.');
            foreach ($members as $member) {
                if (!is_array($member) || ($member['type'] ?? null) === 'GeometryCollection') {
                    $collector->error($index, 'collection_nested', 'Nested GeometryCollections are not supported.');
                    continue;
                }
                $this->geometry($member, $attributes, $index, $collector, $part);
            }

            return;
        }
        try {
            $collector->add(Geometry::fromArray($geometry), $attributes, $index, $part++);
        } catch (InvalidGeometryException $e) {
            $collector->error($index, 'geometry_invalid', $e->getMessage());
        }
    }

    /** @return array<string, string> scalar properties only; nested values are dropped */
    private function attributes(mixed $properties): array
    {
        $out = [];
        if (!is_array($properties)) {
            return $out;
        }
        foreach ($properties as $key => $value) {
            if (is_string($value) || is_int($value) || is_float($value)) {
                $out[(string) $key] = (string) $value;
            } elseif (is_bool($value)) {
                $out[(string) $key] = $value ? 'true' : 'false';
            }
        }

        return $out;
    }
}
