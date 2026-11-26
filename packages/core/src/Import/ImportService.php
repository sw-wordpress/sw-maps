<?php

declare(strict_types=1);

namespace SWMaps\Core\Import;

use SWMaps\Core\Application\Input;
use SWMaps\Core\Contracts\ClockInterface;
use SWMaps\Core\Contracts\EventsInterface;
use SWMaps\Core\Contracts\FeatureRepositoryInterface;
use SWMaps\Core\Contracts\LayerRepositoryInterface;
use SWMaps\Core\Contracts\MapRepositoryInterface;
use SWMaps\Core\Contracts\TransactionInterface;
use SWMaps\Core\Domain\Feature;
use SWMaps\Core\Domain\FeatureProperties;
use SWMaps\Core\Domain\Layer;
use SWMaps\Core\Domain\LayerType;
use SWMaps\Core\Domain\MapStatus;
use SWMaps\Core\Exception\NotFoundException;
use SWMaps\Core\Exception\ValidationException;

/**
 * Validates and executes imports. Nothing is written when the file violates a limit, when entries are invalid and
 * `skip_invalid` is not set, or when a destructive mode lacks its confirmation; all writes run in one transaction.
 */
final class ImportService
{
    public const FORMATS = ['geojson', 'gpx', 'csv'];
    private const PREVIEW_ROWS = 10;

    public function __construct(
        private readonly MapRepositoryInterface $maps,
        private readonly LayerRepositoryInterface $layers,
        private readonly FeatureRepositoryInterface $features,
        private readonly TransactionInterface $tx,
        private readonly ClockInterface $clock,
        private readonly EventsInterface $events,
        private readonly ImportLimits $limits = new ImportLimits(),
        private readonly int $maxFeaturesPerLayer = 5000
    ) {
    }

    /**
     * @param array<string, mixed> $input format, content, mode, layer_id, layer_title, mapping, options, skip_invalid,
     *                                    confirm_replace, dry_run
     * @return array<string, mixed> the import report
     * @throws ImportException when the file as a whole is rejected
     * @throws ValidationException on invalid request parameters
     * @throws NotFoundException
     */
    public function run(int $mapId, array $input): array
    {
        $this->maps->find($mapId) ?? throw NotFoundException::for('Map', $mapId);
        $format = Input::string($input, 'format', true, 20) ?? '';
        $content = $input['content'] ?? null;
        if (!in_array($format, self::FORMATS, true) || !is_string($content)) {
            throw new ValidationException(['format' => 'Unknown format or missing file content.']);
        }
        $mode = ImportMode::tryFrom(Input::string($input, 'mode', false, 20) ?? 'new_layer') ?? throw ValidationException::field('mode', 'Unknown import mode.');
        $dryRun = Input::bool($input, 'dry_run') ?? false;
        $skipInvalid = Input::bool($input, 'skip_invalid') ?? false;
        $layer = $this->targetLayer($mapId, $mode, $input);
        if ($mode === ImportMode::Replace && !$dryRun && (Input::bool($input, 'confirm_replace') ?? false) !== true) {
            throw ValidationException::field('confirm_replace', 'Replacing the layer content must be confirmed.');
        }

        $parsed = $this->importer($format)->parse($content, $this->limits, Input::object($input, 'options') ?? []);
        $mapping = $this->mapping($input, $parsed->columns);
        $extra = $this->extraColumns($input, $parsed->columns);
        [$rows, $rowErrors, $rowWarnings] = $this->prepare($parsed, $mapping, $extra);
        $errorCount = $parsed->errorCount + count($rowErrors);
        $errors = array_slice(array_merge(array_map(static fn (ParseIssue $i): array => $i->toArray(), $parsed->errors), $rowErrors), 0, $this->limits->maxErrors);
        $warnings = array_slice(array_merge(array_map(static fn (ParseIssue $i): array => $i->toArray(), $parsed->warnings), $rowWarnings), 0, $this->limits->maxErrors);

        $existing = $layer !== null && $mode === ImportMode::Upsert ? $this->features->byExternalIds($layer->id, $this->externalIds($rows)) : [];
        $creates = 0;
        $updates = 0;
        foreach ($rows as $row) {
            if ($mode === ImportMode::Upsert && $row['external_id'] !== null && isset($existing[$row['external_id']])) {
                ++$updates;
            } else {
                ++$creates;
            }
        }
        $base = ($layer !== null && $mode !== ImportMode::Replace && $mode !== ImportMode::NewLayer) ? $this->features->count($layer->id) : 0;
        $report = [
            'mode' => $mode->value,
            'layer_id' => $layer?->id,
            'total' => count($parsed->features) + $parsed->errorCount,
            'created' => $creates,
            'updated' => $updates,
            'skipped' => $errorCount,
            'error_count' => $errorCount,
            'errors' => $errors,
            'warnings' => $warnings,
            'columns' => $parsed->columns,
            'mapping' => $mapping,
            'media_relink_hint' => FieldMapper::hasMediaColumns($parsed->columns),
            'imported' => false,
            'preview' => array_map(
                static fn (array $r): array => ['title' => $r['properties']->title, 'type' => $r['geometry']->type->value, 'external_id' => $r['external_id']],
                array_slice($rows, 0, self::PREVIEW_ROWS)
            ),
        ];
        if ($base + $creates > $this->maxFeaturesPerLayer) {
            throw new ImportException(ImportException::TOO_MANY_FEATURES, sprintf('A layer may contain at most %d locations.', $this->maxFeaturesPerLayer));
        }
        if ($dryRun) {
            return $report;
        }
        if ($errorCount > 0 && !$skipInvalid) {
            throw new ValidationException(['file' => sprintf('%d entries are invalid; fix the file or skip the invalid entries.', $errorCount)]);
        }
        if ($rows === []) {
            throw new ValidationException(['file' => 'The file contains nothing to import.']);
        }

        $layerId = $this->tx->run(function () use ($mapId, $mode, $layer, $rows, $existing, $input): int {
            $target = $layer ?? $this->createLayer($mapId, $input);
            if ($mode === ImportMode::Replace) {
                $this->features->deleteForLayer($target->id);
            }
            $now = $this->clock->now();
            foreach ($rows as $row) {
                $current = $mode === ImportMode::Upsert && $row['external_id'] !== null ? ($existing[$row['external_id']] ?? null) : null;
                if ($current !== null) {
                    $this->features->save($current->with($row['geometry'], FeatureProperties::fromArray($this->patch($row['properties']), $current->properties), null, $now));
                } else {
                    $this->features->save(new Feature(0, $target->id, $row['external_id'], $row['geometry'], $row['properties'], [], $now));
                }
            }

            return $target->id;
        });
        $this->events->layerUpdated($layerId, $mapId);
        $report['layer_id'] = $layerId;
        $report['imported'] = true;

        return $report;
    }

    /**
     * Upsert only overwrites what the file provides; image and icon of the existing location stay untouched.
     *
     * @return array<string, mixed>
     */
    private function patch(FeatureProperties $properties): array
    {
        $patch = [];
        foreach (['title' => $properties->title, 'description' => $properties->description, 'address' => $properties->address, 'link' => $properties->link ?? ''] as $key => $value) {
            if ($value !== '') {
                $patch[$key] = $value;
            }
        }
        if ($properties->fields !== []) {
            $patch['fields'] = $properties->fields;
        }

        return $patch;
    }

    private function importer(string $format): ImporterInterface
    {
        return match ($format) {
            'gpx' => new GpxImporter(),
            'csv' => new CsvImporter(),
            default => new GeoJsonImporter(),
        };
    }

    /** @param array<string, mixed> $input */
    private function targetLayer(int $mapId, ImportMode $mode, array $input): ?Layer
    {
        if ($mode === ImportMode::NewLayer) {
            return null;
        }
        $id = Input::int($input, 'layer_id', 1, PHP_INT_MAX) ?? throw ValidationException::field('layer_id', 'A target layer is required.');
        $layer = $this->layers->find($id);
        if ($layer === null || $layer->mapId !== $mapId) {
            throw NotFoundException::for('Layer', $id);
        }

        return $layer;
    }

    /** @param array<string, mixed> $input */
    private function createLayer(int $mapId, array $input): Layer
    {
        $now = $this->clock->now();
        $title = Input::string($input, 'layer_title', false, 200) ?? '';

        return $this->layers->save(new Layer(0, $mapId, $title === '' ? 'Import' : $title, LayerType::Features, MapStatus::Published, $this->layers->nextSortOrder($mapId), true, [], $now, $now));
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string> $columns
     * @return array<string, string>
     */
    private function mapping(array $input, array $columns): array
    {
        $given = Input::object($input, 'mapping');
        if ($given === null) {
            return FieldMapper::suggest($columns);
        }
        $mapping = [];
        foreach ($given as $target => $source) {
            if (!in_array($target, FieldMapper::TARGETS, true) || !is_string($source)) {
                throw ValidationException::field('mapping', 'Unknown target field or invalid column.');
            }
            if ($source !== '' && !in_array($source, $columns, true)) {
                throw ValidationException::field('mapping', sprintf('Column "%s" does not exist in the file.', $source));
            }
            $mapping[$target] = $source;
        }

        return $mapping;
    }

    /**
     * @param array<string, string> $mapping
     * @param list<string> $extra source columns that become additional fields
     * @return array{0: list<array{geometry: \SWMaps\Core\Domain\Geometry, properties: FeatureProperties, external_id: ?string}>, 1: list<array{index: int, code: string, message: string, params: array<string, string>}>, 2: list<array{index: int, code: string, message: string, params: array<string, string>}>}
     */
    private function prepare(ParseResult $parsed, array $mapping, array $extra): array
    {
        $rows = [];
        $errors = [];
        $warnings = [];
        $seen = [];
        foreach ($parsed->features as $feature) {
            $fields = FieldMapper::apply($feature->attributes, $mapping);
            $external = $fields['external_id'] ?? null;
            unset($fields['external_id']);
            // Members of a split GeometryCollection share the source id: keep them distinct and stable.
            if ($external !== null && $feature->part > 0) {
                $external .= '#' . ($feature->part + 1);
            }
            if ($external !== null && (mb_strlen($external) > 190 || isset($seen[$external]))) {
                $duplicate = isset($seen[$external]);
                $errors[] = [
                    'index' => $feature->index,
                    'code' => $duplicate ? 'duplicate_external_id' : 'external_id_too_long',
                    'message' => $duplicate ? 'Duplicate external ID in the file.' : 'External ID is too long.',
                    'params' => [],
                ];
                continue;
            }
            $properties = $this->properties($fields + ['fields' => $this->extraFields($feature->attributes, $extra)], $feature->index, $warnings);
            if ($external !== null) {
                $seen[$external] = true;
            }
            $rows[] = ['geometry' => $feature->geometry, 'properties' => $properties, 'external_id' => $external];
        }

        return [$rows, $errors, $warnings];
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string> $columns
     * @return list<string>
     */
    private function extraColumns(array $input, array $columns): array
    {
        if (!array_key_exists('extra_columns', $input)) {
            return [];
        }
        $given = $input['extra_columns'];
        if (!is_array($given) || !array_is_list($given) || count($given) > FeatureProperties::MAX_FIELDS) {
            throw ValidationException::field('extra_columns', sprintf('Select at most %d columns.', FeatureProperties::MAX_FIELDS));
        }
        $out = [];
        foreach ($given as $column) {
            if (!is_string($column) || !in_array($column, $columns, true)) {
                throw ValidationException::field('extra_columns', 'Column does not exist in the file.');
            }
            $out[] = $column;
        }

        return $out;
    }

    /**
     * @param array<string, string> $attributes
     * @param list<string> $extra
     * @return list<array{label: string, value: string}>
     */
    private function extraFields(array $attributes, array $extra): array
    {
        $fields = [];
        foreach ($extra as $column) {
            if (($attributes[$column] ?? '') !== '') {
                $fields[] = ['label' => $column, 'value' => $attributes[$column]];
            }
        }

        return $fields;
    }

    /**
     * Invalid optional fields are dropped with a warning instead of rejecting the entry.
     *
     * @param array<string, mixed> $fields
     * @param list<array{index: int, code: string, message: string, params: array<string, string>}> $warnings
     */
    private function properties(array $fields, int $index, array &$warnings): FeatureProperties
    {
        for ($attempt = 0; $attempt < 6; ++$attempt) {
            try {
                return FeatureProperties::fromArray($fields);
            } catch (ValidationException $e) {
                foreach (array_keys($e->errors()) as $key) {
                    $field = str_replace('properties.', '', $key);
                    unset($fields[$field]);
                    $warnings[] = [
                        'index' => $index,
                        'code' => 'field_invalid',
                        'message' => sprintf('Field "%s" is invalid and was left empty.', $field),
                        'params' => ['field' => $field],
                    ];
                }
            }
        }

        return FeatureProperties::empty();
    }

    /**
     * @param list<array{geometry: \SWMaps\Core\Domain\Geometry, properties: FeatureProperties, external_id: ?string}> $rows
     * @return list<string>
     */
    private function externalIds(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if ($row['external_id'] !== null) {
                $ids[] = $row['external_id'];
            }
        }

        return $ids;
    }
}
