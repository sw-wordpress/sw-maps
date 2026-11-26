<?php

declare(strict_types=1);

namespace SWMaps\Core\Import;

/** Hard limits applied while parsing; exceeding one rejects the whole file before anything is written. */
final class ImportLimits
{
    public function __construct(
        public readonly int $maxBytes = 5_242_880,
        public readonly int $maxFeatures = 5000,
        public readonly int $maxCoordinates = 200_000,
        public readonly int $maxDepth = 32,
        public readonly int $maxErrors = 100
    ) {
    }
}
