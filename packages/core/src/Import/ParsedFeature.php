<?php

declare(strict_types=1);

namespace SWMaps\Core\Import;

use SWMaps\Core\Domain\Geometry;

/** A geometry plus the raw source attributes; `part` numbers the members of a split GeometryCollection (0 = first); field mapping decides how attributes become feature properties. */
final class ParsedFeature
{
    /** @param array<string, string> $attributes */
    public function __construct(
        public readonly Geometry $geometry,
        public readonly array $attributes,
        public readonly int $index,
        public readonly int $part = 0
    ) {
    }
}
