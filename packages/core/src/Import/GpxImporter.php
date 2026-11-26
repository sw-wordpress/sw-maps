<?php

declare(strict_types=1);

namespace SWMaps\Core\Import;

use SWMaps\Core\Domain\Geometry;
use SWMaps\Core\Domain\GeometryType;
use SWMaps\Core\Exception\InvalidGeometryException;

/**
 * GPX 1.0 / 1.1: waypoints become markers, routes lines, tracks (multi-)lines with one part per segment.
 * XML is parsed without entity substitution, DTD loading or network access; any DOCTYPE is rejected.
 */
final class GpxImporter implements ImporterInterface
{
    public function parse(string $content, ImportLimits $limits, array $options = []): ParseResult
    {
        ParseCollector::guardSize($content, $limits);
        $content = ltrim($content, "\xEF\xBB\xBF");
        if (preg_match('/<!(DOCTYPE|ENTITY)/i', $content) === 1) {
            throw new ImportException(ImportException::FORBIDDEN, 'DOCTYPE and ENTITY declarations are not allowed.');
        }
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        try {
            $loaded = $document->loadXML($content, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($loaded === false || $document->documentElement === null) {
            throw new ImportException(ImportException::MALFORMED, 'The file is not valid XML.');
        }
        if ($document->doctype !== null) {
            throw new ImportException(ImportException::FORBIDDEN, 'DOCTYPE declarations are not allowed.');
        }
        $root = $document->documentElement;
        if (self::depth($root) > $limits->maxDepth) {
            throw new ImportException(ImportException::TOO_DEEP, 'The file is nested too deeply.');
        }
        if ($root->localName !== 'gpx') {
            throw new ImportException(ImportException::MALFORMED, 'The file is not a GPX document.');
        }
        $collector = new ParseCollector($limits);
        $index = 0;
        foreach ($this->children($root) as $element) {
            $name = $element->localName;
            if ($name === 'wpt') {
                $this->waypoint($element, ++$index, $collector);
            } elseif ($name === 'rte') {
                $this->route($element, ++$index, $collector);
            } elseif ($name === 'trk') {
                $this->track($element, ++$index, $collector);
            }
        }

        return $collector->result();
    }

    /** Maximum element nesting (iterative, so hostile nesting cannot exhaust the stack). */
    private static function depth(\DOMElement $root): int
    {
        $max = 0;
        $stack = [[$root, 1]];
        while ($stack !== []) {
            [$node, $level] = array_pop($stack);
            $max = max($max, $level);
            foreach ($node->childNodes as $child) {
                if ($child instanceof \DOMElement) {
                    $stack[] = [$child, $level + 1];
                }
            }
        }

        return $max;
    }

    private function waypoint(\DOMElement $wpt, int $index, ParseCollector $collector): void
    {
        $point = $this->position($wpt);
        if ($point === null) {
            $collector->error($index, 'gpx_waypoint', 'Waypoint has no valid lat/lon.');

            return;
        }
        $this->emit(GeometryType::Point, $point, $this->attributes($wpt), $index, $collector);
    }

    private function route(\DOMElement $rte, int $index, ParseCollector $collector): void
    {
        $points = $this->positions($rte, 'rtept');
        if ($points === null || count($points) < 2) {
            $collector->error($index, 'gpx_route', 'Route needs at least two valid points.');

            return;
        }
        $this->emit(GeometryType::LineString, $points, $this->attributes($rte), $index, $collector);
    }

    private function track(\DOMElement $trk, int $index, ParseCollector $collector): void
    {
        $segments = [];
        foreach ($this->children($trk) as $child) {
            if ($child->localName !== 'trkseg') {
                continue;
            }
            $points = $this->positions($child, 'trkpt');
            if ($points === null) {
                $collector->error($index, 'gpx_segment', 'Track segment contains an invalid lat/lon.');

                return;
            }
            if (count($points) >= 2) {
                $segments[] = $points;
            }
        }
        if ($segments === []) {
            $collector->error($index, 'gpx_track', 'Track has no segment with at least two points.');

            return;
        }
        $attributes = $this->attributes($trk);
        if (count($segments) === 1) {
            $this->emit(GeometryType::LineString, $segments[0], $attributes, $index, $collector);
        } else {
            $this->emit(GeometryType::MultiLineString, $segments, $attributes, $index, $collector);
        }
    }

    /**
     * @param array<mixed> $coordinates
     * @param array<string, string> $attributes
     */
    private function emit(GeometryType $type, array $coordinates, array $attributes, int $index, ParseCollector $collector): void
    {
        try {
            $collector->add(Geometry::create($type, $coordinates), $attributes, $index);
        } catch (InvalidGeometryException $e) {
            $collector->error($index, 'geometry_invalid', $e->getMessage());
        }
    }

    /** @return list<\DOMElement> */
    private function children(\DOMElement $parent): array
    {
        $out = [];
        foreach ($parent->childNodes as $node) {
            if ($node instanceof \DOMElement) {
                $out[] = $node;
            }
        }

        return $out;
    }

    /** @return list<array{0: float, 1: float}>|null null when one point is invalid */
    private function positions(\DOMElement $parent, string $name): ?array
    {
        $out = [];
        foreach ($this->children($parent) as $child) {
            if ($child->localName !== $name) {
                continue;
            }
            $position = $this->position($child);
            if ($position === null) {
                return null;
            }
            $out[] = $position;
        }

        return $out;
    }

    /** @return array{0: float, 1: float}|null */
    private function position(\DOMElement $element): ?array
    {
        $lat = trim($element->getAttribute('lat'));
        $lon = trim($element->getAttribute('lon'));
        if (!is_numeric($lat) || !is_numeric($lon)) {
            return null;
        }

        return [(float) $lon, (float) $lat];
    }

    /** @return array<string, string> */
    private function attributes(\DOMElement $element): array
    {
        $out = [];
        foreach ($this->children($element) as $child) {
            $name = (string) $child->localName;
            if (in_array($name, ['name', 'desc', 'cmt', 'type', 'sym', 'src'], true)) {
                $out[$name] = trim($child->textContent);
            } elseif ($name === 'url') {
                $out['link'] = trim($child->textContent);
            } elseif ($name === 'link' && $child->getAttribute('href') !== '') {
                $out['link'] = $child->getAttribute('href');
            }
        }

        return $out;
    }
}
