<?php

declare(strict_types=1);

namespace SWMaps\Core\Import;

final class ParseResult
{
    /**
     * @param list<ParsedFeature> $features
     * @param list<ParseIssue> $errors rejected entries (never imported)
     * @param list<ParseIssue> $warnings accepted entries with a note (e.g. split GeometryCollection)
     * @param list<string> $columns attribute names found in the source
     */
    public function __construct(
        public readonly array $features,
        public readonly array $errors,
        public readonly array $warnings,
        public readonly array $columns,
        public readonly int $errorCount
    ) {
    }
}
