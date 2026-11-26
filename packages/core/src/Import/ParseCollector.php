<?php

declare(strict_types=1);

namespace SWMaps\Core\Import;

use SWMaps\Core\Domain\Geometry;

/** Shared bookkeeping of the importers: enforces the limits and keeps the first errors only (all are counted). */
final class ParseCollector
{
    /** @var list<ParsedFeature> */
    private array $features = [];
    /** @var list<ParseIssue> */
    private array $errors = [];
    /** @var list<ParseIssue> */
    private array $warnings = [];
    /** @var array<string, true> */
    private array $columns = [];
    private int $errorCount = 0;
    private int $coordinates = 0;

    public function __construct(private readonly ImportLimits $limits)
    {
    }

    /** @param array<string, string> $attributes */
    public function add(Geometry $geometry, array $attributes, int $index, int $part = 0): void
    {
        if (count($this->features) >= $this->limits->maxFeatures) {
            throw new ImportException(ImportException::TOO_MANY_FEATURES, sprintf('The file contains more than %d features.', $this->limits->maxFeatures));
        }
        $this->coordinates += count($geometry->flatten());
        if ($this->coordinates > $this->limits->maxCoordinates) {
            throw new ImportException(ImportException::TOO_MANY_COORDINATES, sprintf('The file contains more than %d coordinates.', $this->limits->maxCoordinates));
        }
        foreach (array_keys($attributes) as $name) {
            $this->columns[(string) $name] = true;
        }
        $this->features[] = new ParsedFeature($geometry, $attributes, $index, $part);
    }

    /** @param array<string, string> $params */
    public function error(int $index, string $code, string $message, array $params = []): void
    {
        ++$this->errorCount;
        if (count($this->errors) < $this->limits->maxErrors) {
            $this->errors[] = new ParseIssue($index, $code, $message, $params);
        }
    }

    /** @param array<string, string> $params */
    public function warn(int $index, string $code, string $message, array $params = []): void
    {
        if (count($this->warnings) < $this->limits->maxErrors) {
            $this->warnings[] = new ParseIssue($index, $code, $message, $params);
        }
    }

    public function result(): ParseResult
    {
        return new ParseResult($this->features, $this->errors, $this->warnings, array_map('strval', array_keys($this->columns)), $this->errorCount);
    }

    public static function guardSize(string $content, ImportLimits $limits): void
    {
        if (strlen($content) > $limits->maxBytes) {
            throw new ImportException(ImportException::TOO_LARGE, sprintf('The file exceeds %d bytes.', $limits->maxBytes));
        }
    }
}
