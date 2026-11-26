<?php

declare(strict_types=1);

namespace SWMaps\Core\Import;

interface ImporterInterface
{
    /**
     * @param array<string, mixed> $options format specific options (CSV: delimiter, decimal, lon/lat columns)
     * @throws ImportException when the file as a whole must be rejected
     */
    public function parse(string $content, ImportLimits $limits, array $options = []): ParseResult;
}
