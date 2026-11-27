<?php

declare(strict_types=1);

namespace SWMaps\Core\Export;

use SWMaps\Core\Contracts\CategoryRepositoryInterface;
use SWMaps\Core\Contracts\ClockInterface;
use SWMaps\Core\Contracts\FeatureRepositoryInterface;
use SWMaps\Core\Contracts\LayerRepositoryInterface;
use SWMaps\Core\Contracts\MapRepositoryInterface;
use SWMaps\Core\Domain\Feature;
use SWMaps\Core\Domain\GeometryType;
use SWMaps\Core\Domain\Layer;
use SWMaps\Core\Domain\MapEntity;
use SWMaps\Core\Exception\NotFoundException;
use SWMaps\Core\Import\ImportException;
use SWMaps\Core\Import\ImportLimits;

/**
 * Exports maps. GeoJSON and CSV are for exchange with other tools; the versioned package is the plugin's own
 * format (map, layers, styles, categories, locations). Neither contains credentials (provider ids and secrets) or
 * media: images and media icons are dropped and must be linked again after an import.
 */
final class MapExporter
{
    public const PACKAGE_FORMAT = 'sw-maps-package';
    public const PACKAGE_VERSION = 1;
    public const PACKAGE_MAX_MAPS = 100;

    public function __construct(
        private readonly MapRepositoryInterface $maps,
        private readonly LayerRepositoryInterface $layers,
        private readonly FeatureRepositoryInterface $features,
        private readonly CategoryRepositoryInterface $categories,
        private readonly ClockInterface $clock,
        private readonly ImportLimits $limits = new ImportLimits()
    ) {
    }

    /** @return array{filename: string, mime_type: string, content: string} */
    public function geojson(int $mapId, ?int $layerId = null): array
    {
        $map = $this->map($mapId);
        $titles = $this->categoryTitles();
        $features = [];
        foreach ($this->selectedLayers($mapId, $layerId) as $layer) {
            foreach ($this->features->forLayers([$layer->id]) as $feature) {
                $features[] = [
                    'type' => 'Feature',
                    'id' => $feature->id,
                    'geometry' => $feature->geometry->toArray(),
                    'properties' => [
                        'title' => $feature->properties->title,
                        'description' => $feature->properties->description,
                        'address' => $feature->properties->address,
                        'link' => $feature->properties->link,
                        'external_id' => $feature->externalId,
                        'layer' => $layer->title,
                        'categories' => array_map(static fn (int $id): string => $titles[$id] ?? '', $feature->categoryIds),
                        'fields' => $this->fieldsObject($feature),
                    ],
                ];
            }
        }
        $json = json_encode(['type' => 'FeatureCollection', 'features' => $features], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);

        return ['filename' => $this->filename($map, 'geojson'), 'mime_type' => 'application/geo+json', 'content' => $json];
    }

    /**
     * CSV of the point locations (UTF-8, comma, dot as decimal separator, formula characters neutralised).
     *
     * @return array{filename: string, mime_type: string, content: string}
     */
    public function csv(int $mapId, ?int $layerId = null): array
    {
        $map = $this->map($mapId);
        $titles = $this->categoryTitles();
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            throw new \RuntimeException('Could not create the export.');
        }
        fputcsv($out, ['title', 'description', 'address', 'link', 'lon', 'lat', 'external_id', 'layer', 'categories'], ',', '"', '');
        foreach ($this->selectedLayers($mapId, $layerId) as $layer) {
            foreach ($this->features->forLayers([$layer->id]) as $feature) {
                if ($feature->geometry->type !== GeometryType::Point) {
                    continue;
                }
                [$lon, $lat] = $feature->geometry->coordinates;
                fputcsv($out, [
                    self::cell($feature->properties->title),
                    self::cell($feature->properties->description),
                    self::cell($feature->properties->address),
                    self::cell($feature->properties->link ?? ''),
                    rtrim(rtrim(sprintf('%.7F', $lon), '0'), '.'),
                    rtrim(rtrim(sprintf('%.7F', $lat), '0'), '.'),
                    self::cell($feature->externalId ?? ''),
                    self::cell($layer->title),
                    self::cell(implode('|', array_map(static fn (int $id): string => $titles[$id] ?? '', $feature->categoryIds))),
                ], ',', '"', '');
            }
        }
        rewind($out);
        $content = (string) stream_get_contents($out);
        fclose($out);

        return ['filename' => $this->filename($map, 'csv'), 'mime_type' => 'text/csv', 'content' => $content];
    }

    /**
     * The plugin package of the given maps (all maps when null).
     *
     * @param list<int>|null $mapIds
     * @return array{filename: string, mime_type: string, content: string}
     */
    public function package(?array $mapIds = null, ?string $name = null): array
    {
        $maps = [];
        if ($mapIds === null) {
            $result = $this->maps->search('', null, 1, self::PACKAGE_MAX_MAPS);
            $this->checkMapCount($result['total']);
            $maps = $result['items'];
        } else {
            $this->checkMapCount(count($mapIds));
            foreach ($mapIds as $id) {
                $maps[] = $this->map($id);
            }
        }
        $slugs = [];
        foreach ($this->categories->all() as $category) {
            $slugs[$category->id] = $category->slug;
        }
        $usedCategories = [];
        $mapData = [];
        $featureTotal = 0;
        foreach ($maps as $map) {
            $mapData[] = $this->mapData($map, $slugs, $usedCategories, $featureTotal);
        }
        $categories = [];
        foreach ($this->categories->all() as $category) {
            if (isset($usedCategories[$category->id])) {
                $categories[] = ['slug' => $category->slug, 'title' => $category->title, 'style' => (object) $category->style];
            }
        }
        $package = [
            'format' => self::PACKAGE_FORMAT,
            'version' => self::PACKAGE_VERSION,
            'exported_at' => $this->clock->now()->format('Y-m-d\TH:i:s\Z'),
            'categories' => $categories,
            'maps' => $mapData,
        ];
        $json = json_encode($package, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (strlen($json) > $this->limits->maxBytes) {
            throw new ImportException(ImportException::TOO_LARGE, 'The package would exceed the import file size limit.');
        }
        $base = $name ?? (count($maps) === 1 ? $maps[0]->slug : 'sw-maps');

        return ['filename' => $base . '-' . $this->clock->now()->format('Ymd') . '.json', 'mime_type' => 'application/json', 'content' => $json];
    }

    private function checkMapCount(int $count): void
    {
        if ($count === 0) {
            throw new ImportException(ImportException::INVALID_OPTION, 'There are no maps to export.');
        }
        if ($count > self::PACKAGE_MAX_MAPS) {
            throw new ImportException(ImportException::TOO_LARGE, 'Too many maps for one package. Export maps individually.');
        }
    }

    /**
     * @param array<int, string> $slugs
     * @param array<int, true> $used
     * @return array<string, mixed>
     */
    private function mapData(MapEntity $map, array $slugs, array &$used, int &$featureTotal): array
    {
        $layers = [];
        foreach ($this->layers->forMap($map->id) as $layer) {
            $features = [];
            foreach ($this->features->forLayers([$layer->id]) as $feature) {
                if (count($features) >= $this->limits->maxFeatures || ++$featureTotal > $this->limits->maxFeatures * 10) {
                    throw new ImportException(ImportException::TOO_MANY_FEATURES, 'The package would exceed the import feature limit.');
                }
                $media = $feature->properties->toArray();
                $media['image_id'] = null;
                $media['icon'] = str_starts_with($feature->properties->icon, 'media:') ? 'builtin:pin' : $feature->properties->icon;
                $features[] = [
                    'external_id' => $feature->externalId,
                    'geometry' => $feature->geometry->toArray(),
                    'properties' => [
                        'title' => $media['title'],
                        'description' => $media['description'],
                        'address' => $media['address'],
                        'link' => $media['link'],
                        'icon' => $media['icon'],
                        'fields' => $feature->properties->fields,
                    ],
                    'style' => (object) $feature->style,
                    'categories' => array_values(array_filter(array_map(static fn (int $id): ?string => $slugs[$id] ?? null, $feature->categoryIds))),
                ];
                foreach ($feature->categoryIds as $id) {
                    $used[$id] = true;
                }
            }
            $layers[] = [
                'title' => $layer->title,
                'status' => $layer->status->value,
                'sort_order' => $layer->sortOrder,
                'visible' => $layer->visible,
                'style' => (object) $this->exportLayerStyle($layer->style, $slugs, $used),
                'features' => $features,
            ];
        }
        $settings = $map->settings->toArray();
        $settings['providerId'] = null; // Provider ids are site specific; the target site chooses its own basemap.

        return [
            'title' => $map->title,
            'description' => $map->description,
            'slug' => $map->slug,
            'viewport' => $map->viewport->toArray(),
            'settings' => $settings,
            'layers' => $layers,
        ];
    }

    /**
     * Layer style rules refer to categories by slug in the package (ids differ between sites).
     *
     * @param array<string, mixed> $style
     * @param array<int, string> $slugs
     * @param array<int, true> $used
     * @return array<string, mixed>
     */
    private function exportLayerStyle(array $style, array $slugs, array &$used): array
    {
        if (!isset($style['rules']) || !is_array($style['rules'])) {
            return $style;
        }
        $rules = [];
        foreach ($style['rules'] as $rule) {
            if (is_array($rule) && is_int($rule['categoryId'] ?? null) && isset($slugs[$rule['categoryId']])) {
                $used[$rule['categoryId']] = true;
                $rules[] = ['categorySlug' => $slugs[$rule['categoryId']], 'style' => $rule['style'] ?? []];
            }
        }
        $style['rules'] = $rules;

        return $style;
    }

    private function map(int $id): MapEntity
    {
        return $this->maps->find($id) ?? throw NotFoundException::for('Map', $id);
    }

    /** @return list<Layer> */
    private function selectedLayers(int $mapId, ?int $layerId): array
    {
        $layers = $this->layers->forMap($mapId);
        if ($layerId === null) {
            return $layers;
        }
        $layer = array_values(array_filter($layers, static fn (Layer $l): bool => $l->id === $layerId));

        return $layer === [] ? throw NotFoundException::for('Layer', $layerId) : $layer;
    }

    /** @return array<int, string> */
    private function categoryTitles(): array
    {
        $titles = [];
        foreach ($this->categories->all() as $category) {
            $titles[$category->id] = $category->title;
        }

        return $titles;
    }

    /** @return array<string, string> */
    private function fieldsObject(Feature $feature): array
    {
        $out = [];
        foreach ($feature->properties->fields as $field) {
            $out[$field['label']] = $field['value'];
        }

        return $out;
    }

    /** Neutralises spreadsheet formulas (=, +, -, @ and control characters at the start of a cell). */
    private static function cell(string $value): string
    {
        return $value !== '' && preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
    }

    private function filename(MapEntity $map, string $extension): string
    {
        $base = $map->slug !== '' ? $map->slug : 'map';

        return $base . '-' . $this->clock->now()->format('Ymd') . '.' . $extension;
    }
}
