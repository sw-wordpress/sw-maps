<?php

declare(strict_types=1);

namespace SWMaps\Core\Export;

use SWMaps\Core\Application\Input;
use SWMaps\Core\Application\Slug;
use SWMaps\Core\Contracts\CategoryRepositoryInterface;
use SWMaps\Core\Contracts\ClockInterface;
use SWMaps\Core\Contracts\EventsInterface;
use SWMaps\Core\Contracts\FeatureRepositoryInterface;
use SWMaps\Core\Contracts\LayerRepositoryInterface;
use SWMaps\Core\Contracts\MapRepositoryInterface;
use SWMaps\Core\Contracts\TransactionInterface;
use SWMaps\Core\Domain\Category;
use SWMaps\Core\Domain\Feature;
use SWMaps\Core\Domain\FeatureProperties;
use SWMaps\Core\Domain\Geometry;
use SWMaps\Core\Domain\Layer;
use SWMaps\Core\Domain\LayerType;
use SWMaps\Core\Domain\MapEntity;
use SWMaps\Core\Domain\MapSettings;
use SWMaps\Core\Domain\MapStatus;
use SWMaps\Core\Domain\Viewport;
use SWMaps\Core\Exception\ValidationException;
use SWMaps\Core\Import\ImportException;
use SWMaps\Core\Import\ImportLimits;

/**
 * Imports a plugin package as new draft maps. Everything is validated before anything is written; the writes run
 * in one transaction, so a rejected package leaves no trace.
 */
final class PackageImporter
{
    public function __construct(
        private readonly MapRepositoryInterface $maps,
        private readonly LayerRepositoryInterface $layers,
        private readonly FeatureRepositoryInterface $features,
        private readonly CategoryRepositoryInterface $categories,
        private readonly TransactionInterface $tx,
        private readonly ClockInterface $clock,
        private readonly EventsInterface $events,
        private readonly ImportLimits $limits = new ImportLimits()
    ) {
    }

    /**
     * @return array{maps: list<array{id: int, title: string, layers: int, features: int}>, categories_created: int, categories_matched: int, media_relink_hint: bool}
     * @throws ImportException when the file as a whole is rejected
     */
    public function import(string $content, int $userId): array
    {
        [$plan, $categories] = $this->plan($content);
        $report = $this->tx->run(fn (): array => $this->write($plan, $categories, $userId));
        foreach ($report['layer_ids'] as [$layerId, $mapId]) {
            $this->events->layerUpdated($layerId, $mapId);
        }
        unset($report['layer_ids']);

        return $report;
    }

    /**
     * @return array{maps: int, layers: int, features: int, categories: int}
     * @throws ImportException when the file as a whole is rejected
     */
    public function validate(string $content): array
    {
        [$plan, $categories] = $this->plan($content);
        $layers = 0;
        $features = 0;
        foreach ($plan as $map) {
            foreach ($map['layers'] as $layer) {
                ++$layers;
                $features += count($layer['features']);
            }
        }

        return ['maps' => count($plan), 'layers' => $layers, 'features' => $features, 'categories' => count($categories)];
    }

    /**
     * @return array{list<array<string, mixed>>, array<string, array{title: string, style: array<string, mixed>}>}
     * @throws ImportException when the file as a whole is rejected
     */
    private function plan(string $content): array
    {
        if (strlen($content) > $this->limits->maxBytes) {
            throw new ImportException(ImportException::TOO_LARGE, sprintf('The file exceeds %d bytes.', $this->limits->maxBytes));
        }
        try {
            $data = json_decode(ltrim($content, "\xEF\xBB\xBF"), true, max(1, $this->limits->maxDepth), JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ImportException($e->getCode() === JSON_ERROR_DEPTH ? ImportException::TOO_DEEP : ImportException::MALFORMED, 'The file is not a valid package.');
        }
        if (!is_array($data) || ($data['format'] ?? null) !== MapExporter::PACKAGE_FORMAT) {
            throw new ImportException(ImportException::MALFORMED, 'The file is not a SW Maps package.');
        }
        $version = $data['version'] ?? null;
        if (!is_int($version) || $version < 1) {
            throw new ImportException(ImportException::MALFORMED, 'The package version is missing.');
        }
        if ($version > MapExporter::PACKAGE_VERSION) {
            throw new ImportException(ImportException::UNSUPPORTED, sprintf('The package has version %d; this plugin supports up to %d.', $version, MapExporter::PACKAGE_VERSION));
        }
        $maps = $data['maps'] ?? null;
        if (!is_array($maps) || !array_is_list($maps) || $maps === [] || count($maps) > MapExporter::PACKAGE_MAX_MAPS) {
            throw new ImportException(ImportException::MALFORMED, 'The package map list is invalid or exceeds the limit.');
        }
        $categories = $this->validCategories($data['categories'] ?? []);
        $plan = [];
        $features = 0;
        foreach ($maps as $i => $map) {
            $plan[] = $this->validMap($map, $i + 1, array_keys($categories), $features);
        }

        return [$plan, $categories];
    }

    /**
     * @return array<string, array{title: string, style: array<string, mixed>}> by slug
     */
    private function validCategories(mixed $input): array
    {
        if (!is_array($input) || !array_is_list($input)) {
            throw new ImportException(ImportException::MALFORMED, 'The category list is invalid.');
        }
        $out = [];
        foreach ($input as $category) {
            try {
                $title = Input::string(is_array($category) ? $category : [], 'title', true, 200) ?? '';
                $style = Input::style(is_array($category) ? $category : [], 'style') ?? [];
            } catch (ValidationException) {
                throw new ImportException(ImportException::MALFORMED, 'A category is invalid.');
            }
            $slug = is_array($category) && is_string($category['slug'] ?? null) ? $category['slug'] : '';
            if (!Slug::valid($slug)) {
                throw new ImportException(ImportException::MALFORMED, 'A category has an invalid slug.');
            }
            $out[$slug] = ['title' => $title, 'style' => $style];
        }

        return $out;
    }

    /**
     * @param list<string> $categorySlugs
     * @return array<string, mixed>
     */
    private function validMap(mixed $map, int $number, array $categorySlugs, int &$featureTotal): array
    {
        $where = sprintf('Map %d', $number);
        if (!is_array($map) || !is_array($map['layers'] ?? null) || !array_is_list($map['layers'])) {
            throw new ImportException(ImportException::MALFORMED, $where . ' is invalid.');
        }
        try {
            $title = Input::string($map, 'title', true, 200) ?? '';
            $description = Input::string($map, 'description', false, 2000) ?? '';
            $viewport = Viewport::fromArray(Input::object($map, 'viewport') ?? []);
            $settings = MapSettings::fromArray(array_merge(Input::object($map, 'settings') ?? [], ['providerId' => null]));
        } catch (ValidationException $e) {
            throw new ImportException(ImportException::MALFORMED, $where . ': ' . implode(' ', $e->errors()));
        }
        $layers = [];
        foreach ($map['layers'] as $j => $layer) {
            $layerWhere = sprintf('%s, layer %d', $where, $j + 1);
            if (!is_array($layer) || !is_array($layer['features'] ?? null) || !array_is_list($layer['features'])) {
                throw new ImportException(ImportException::MALFORMED, $layerWhere . ' is invalid.');
            }
            try {
                $layerTitle = Input::string($layer, 'title', true, 200) ?? '';
                $status = MapStatus::tryFrom(is_string($layer['status'] ?? null) ? $layer['status'] : 'published') ?? throw ValidationException::field('status', 'Unknown status.');
                $style = $this->layerStyle($layer['style'] ?? [], $categorySlugs);
                $order = Input::int($layer, 'sort_order', 0, 100000) ?? $j;
                $visible = Input::bool($layer, 'visible') ?? true;
            } catch (ValidationException $e) {
                throw new ImportException(ImportException::MALFORMED, $layerWhere . ': ' . implode(' ', $e->errors()));
            }
            $featureList = [];
            $seen = [];
            foreach ($layer['features'] as $k => $feature) {
                $featureWhere = sprintf('%s, location %d', $layerWhere, $k + 1);
                if (++$featureTotal > $this->limits->maxFeatures * 10) {
                    throw new ImportException(ImportException::TOO_MANY_FEATURES, 'The package contains too many locations.');
                }
                $featureList[] = $this->validFeature($feature, $featureWhere, $categorySlugs, $seen);
            }
            if (count($featureList) > $this->limits->maxFeatures) {
                throw new ImportException(ImportException::TOO_MANY_FEATURES, $layerWhere . ' contains too many locations.');
            }
            $layers[] = ['title' => $layerTitle, 'status' => $status, 'style' => $style, 'order' => $order, 'visible' => $visible, 'features' => $featureList];
        }

        return ['title' => $title, 'description' => $description, 'slug' => is_string($map['slug'] ?? null) ? $map['slug'] : '', 'viewport' => $viewport, 'settings' => $settings, 'layers' => $layers];
    }

    /**
     * Layer styles carry rules by category slug in a package; they are validated here and keep the slug until the
     * category ids of this site are known.
     *
     * @param list<string> $categorySlugs
     * @return array<string, mixed>
     */
    private function layerStyle(mixed $input, array $categorySlugs): array
    {
        if (!is_array($input)) {
            return [];
        }
        $rules = $input['rules'] ?? [];
        unset($input['rules']);
        $style = Input::style(['style' => $input], 'style') ?? [];
        if (!is_array($rules) || !array_is_list($rules) || count($rules) > 10) {
            throw ValidationException::field('style', 'Rules must be a list of at most 10 entries.');
        }
        $out = [];
        foreach ($rules as $rule) {
            $slug = is_array($rule) ? ($rule['categorySlug'] ?? null) : null;
            if (!is_string($slug) || !is_array($rule['style'] ?? null)) {
                throw ValidationException::field('style', 'Each rule needs a categorySlug and a style.');
            }
            if (in_array($slug, $categorySlugs, true)) {
                $out[] = ['categorySlug' => $slug, 'style' => Input::style(['style' => $rule['style']], 'style') ?? []];
            }
        }
        if ($out !== []) {
            $style['rules'] = $out;
        }

        return $style;
    }

    /**
     * @param list<string> $categorySlugs
     * @param array<string, true> $seen
     * @return array{external_id: ?string, geometry: Geometry, properties: FeatureProperties, style: array<string, mixed>, categories: list<string>}
     */
    private function validFeature(mixed $feature, string $where, array $categorySlugs, array &$seen): array
    {
        if (!is_array($feature) || !is_array($feature['geometry'] ?? null)) {
            throw new ImportException(ImportException::MALFORMED, $where . ' has no geometry.');
        }
        try {
            $geometry = Geometry::fromArray($feature['geometry']);
            $props = Input::object($feature, 'properties') ?? [];
            unset($props['image_id'], $props['image_url'], $props['icon_url']);
            if (isset($props['icon']) && is_string($props['icon']) && str_starts_with($props['icon'], 'media:')) {
                $props['icon'] = 'builtin:pin';
            }
            $properties = FeatureProperties::fromArray($props);
            $style = Input::style($feature, 'style') ?? [];
            $external = Input::string($feature, 'external_id', false, 190);
        } catch (ValidationException $e) { // also covers InvalidGeometryException
            $detail = $e->errors() !== [] ? implode(' ', $e->errors()) : $e->getMessage();
            throw new ImportException(ImportException::MALFORMED, $where . ': ' . $detail);
        }
        $external = $external === '' ? null : $external;
        if ($external !== null) {
            $key = mb_strtolower($external);
            if (isset($seen[$key])) {
                throw new ImportException(ImportException::MALFORMED, $where . ': duplicate external ID.');
            }
            $seen[$key] = true;
        }
        $slugs = [];
        foreach (is_array($feature['categories'] ?? null) ? $feature['categories'] : [] as $slug) {
            if (is_string($slug) && in_array($slug, $categorySlugs, true)) {
                $slugs[] = $slug;
            }
        }

        return ['external_id' => $external, 'geometry' => $geometry, 'properties' => $properties, 'style' => $style, 'categories' => $slugs];
    }

    /**
     * @param list<array<string, mixed>> $plan
     * @param array<string, array{title: string, style: array<string, mixed>}> $categories
     * @return array{maps: list<array{id: int, title: string, layers: int, features: int}>, categories_created: int, categories_matched: int, media_relink_hint: bool, layer_ids: list<array{0: int, 1: int}>}
     */
    private function write(array $plan, array $categories, int $userId): array
    {
        $ids = [];
        $created = 0;
        $matched = 0;
        foreach ($categories as $slug => $category) {
            $existing = $this->categoryBySlug($slug);
            if ($existing !== null) {
                $ids[$slug] = $existing->id;
                ++$matched;
            } else {
                $ids[$slug] = $this->categories->save(new Category(0, $slug, $category['title'], $category['style']))->id;
                ++$created;
            }
        }
        $now = $this->clock->now();
        $report = [];
        $layerIds = [];
        foreach ($plan as $map) {
            $saved = $this->maps->save(new MapEntity(0, $this->uniqueSlug((string) $map['slug'], (string) $map['title']), (string) $map['title'], (string) $map['description'], MapStatus::Draft, $map['viewport'], $map['settings'], $userId, $now, $now));
            $featureCount = 0;
            foreach ($map['layers'] as $layer) {
                $style = $this->styleWithIds($layer['style'], $ids);
                $savedLayer = $this->layers->save(new Layer(0, $saved->id, $layer['title'], LayerType::Features, $layer['status'], $layer['order'], $layer['visible'], $style, $now, $now));
                $layerIds[] = [$savedLayer->id, $saved->id];
                foreach ($layer['features'] as $feature) {
                    $categoryIds = array_values(array_map(static fn (string $slug): int => $ids[$slug], $feature['categories']));
                    sort($categoryIds);
                    $this->features->save(new Feature(0, $savedLayer->id, $feature['external_id'], $feature['geometry'], $feature['properties'], $feature['style'], $now, $categoryIds));
                    ++$featureCount;
                }
            }
            $report[] = ['id' => $saved->id, 'title' => $saved->title, 'layers' => count($map['layers']), 'features' => $featureCount];
        }

        return ['maps' => $report, 'categories_created' => $created, 'categories_matched' => $matched, 'media_relink_hint' => true, 'layer_ids' => $layerIds];
    }

    private function categoryBySlug(string $slug): ?Category
    {
        foreach ($this->categories->all() as $category) {
            if ($category->slug === $slug) {
                return $category;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $style
     * @param array<string, int> $ids
     * @return array<string, mixed>
     */
    private function styleWithIds(array $style, array $ids): array
    {
        if (!isset($style['rules']) || !is_array($style['rules'])) {
            return $style;
        }
        $rules = [];
        foreach ($style['rules'] as $rule) {
            if (is_array($rule) && is_string($rule['categorySlug'] ?? null) && isset($ids[$rule['categorySlug']])) {
                $rules[] = ['categoryId' => $ids[$rule['categorySlug']], 'style' => is_array($rule['style'] ?? null) ? $rule['style'] : []];
            }
        }
        $style['rules'] = $rules;

        return $style;
    }

    private function uniqueSlug(string $slug, string $title): string
    {
        $base = Slug::valid($slug) ? $slug : (Slug::from($title) ?: 'map');
        for ($i = 1;; ++$i) {
            $candidate = $i === 1 ? $base : substr($base, 0, 90) . '-' . $i;
            if (!$this->maps->slugExists($candidate)) {
                return $candidate;
            }
        }
    }
}
