<?php

declare(strict_types=1);

namespace SWMaps\Core\Import;

use SWMaps\Core\Domain\Geometry;
use SWMaps\Core\Exception\InvalidGeometryException;

/**
 * CSV with a header row; every row is a marker. Delimiter and decimal separator are explicit options (defaults
 * "," and "."); the longitude/latitude columns are auto-detected by name unless given.
 */
final class CsvImporter implements ImporterInterface
{
    public const DELIMITERS = [',', ';', "\t", '|'];
    private const LON_NAMES = ['lon', 'lng', 'long', 'longitude', 'x'];
    private const LAT_NAMES = ['lat', 'latitude', 'y'];

    public function parse(string $content, ImportLimits $limits, array $options = []): ParseResult
    {
        ParseCollector::guardSize($content, $limits);
        $delimiter = $options['delimiter'] ?? ',';
        $decimal = $options['decimal'] ?? '.';
        if (!is_string($delimiter) || !in_array($delimiter, self::DELIMITERS, true)) {
            throw new ImportException(ImportException::INVALID_OPTION, 'Unsupported delimiter.');
        }
        if ($decimal !== '.' && $decimal !== ',') {
            throw new ImportException(ImportException::INVALID_OPTION, 'The decimal separator must be "." or ",".');
        }
        if ($decimal === $delimiter) {
            throw new ImportException(ImportException::INVALID_OPTION, 'Delimiter and decimal separator must differ.');
        }
        $content = ltrim($content, "\xEF\xBB\xBF");
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new ImportException(ImportException::MALFORMED, 'The file could not be read.');
        }
        fwrite($handle, $content);
        rewind($handle);
        try {
            return $this->read($handle, $delimiter, $decimal, $options, $limits);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     * @param array<string, mixed> $options
     */
    private function read($handle, string $delimiter, string $decimal, array $options, ImportLimits $limits): ParseResult
    {
        $header = fgetcsv($handle, null, $delimiter, '"', '');
        if ($header === false || $header === [null]) {
            throw new ImportException(ImportException::MALFORMED, 'The file has no header row.');
        }
        $names = array_map(static fn (?string $h): string => trim((string) $h), $header);
        $lon = $this->column($names, $options['lon_column'] ?? null, self::LON_NAMES, 'longitude');
        $lat = $this->column($names, $options['lat_column'] ?? null, self::LAT_NAMES, 'latitude');
        $collector = new ParseCollector($limits);
        $row = 1;
        while (($fields = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            ++$row;
            if ($fields === [null]) {
                continue;
            }
            if (count($fields) !== count($names)) {
                $collector->error($row, 'csv_columns', 'Column count does not match the header.');
                continue;
            }
            $x = $this->number((string) $fields[$lon], $decimal);
            $y = $this->number((string) $fields[$lat], $decimal);
            if ($x === null || $y === null) {
                $collector->error($row, 'csv_number', 'Longitude or latitude is not a number in the selected format.');
                continue;
            }
            $attributes = [];
            foreach ($names as $i => $name) {
                if ($i !== $lon && $i !== $lat && $name !== '') {
                    $attributes[$name] = trim((string) $fields[$i]);
                }
            }
            try {
                $collector->add(Geometry::point($x, $y), $attributes, $row);
            } catch (InvalidGeometryException $e) {
                $collector->error($row, 'geometry_invalid', $e->getMessage());
            }
        }

        return $collector->result();
    }

    /**
     * @param list<string> $names
     * @param list<string> $candidates
     */
    private function column(array $names, mixed $explicit, array $candidates, string $label): int
    {
        if (is_string($explicit) && $explicit !== '') {
            $i = array_search($explicit, $names, true);
            if ($i === false) {
                throw new ImportException(ImportException::INVALID_OPTION, sprintf('The %s column "%s" does not exist.', $label, $explicit));
            }

            return (int) $i;
        }
        foreach ($names as $i => $name) {
            if (in_array(strtolower($name), $candidates, true)) {
                return $i;
            }
        }
        throw new ImportException(ImportException::INVALID_OPTION, sprintf('No %s column found; select one.', $label));
    }

    private function number(string $value, string $decimal): ?float
    {
        $value = trim($value);
        if ($decimal === ',') {
            $value = str_replace(',', '.', $value);
        }
        if (preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', $value) !== 1) {
            return null;
        }

        return (float) $value;
    }
}
