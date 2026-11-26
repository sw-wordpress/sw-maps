<?php

declare(strict_types=1);

namespace SWMaps\Tests\Support;

use SWMaps\Core\Application\CategoryService;
use SWMaps\Core\Application\FeatureService;
use SWMaps\Core\Application\LayerService;
use SWMaps\Core\Application\MapService;
use SWMaps\Core\Application\ProviderService;

/** Wires the application services on in-memory adapters. */
final class Fixture
{
    public InMemoryMaps $maps;
    public InMemoryLayers $layers;
    public InMemoryFeatures $features;
    public InMemoryCategories $categories;
    public InMemoryProviders $providers;
    public InMemoryTransaction $tx;
    public RecordingEvents $events;
    public FixedClock $clock;
    public MapService $mapService;
    public LayerService $layerService;
    public FeatureService $featureService;
    public ProviderService $providerService;
    public CategoryService $categoryService;

    public function __construct()
    {
        $this->maps = new InMemoryMaps();
        $this->layers = new InMemoryLayers();
        $this->features = new InMemoryFeatures($this->layers);
        $this->categories = new InMemoryCategories($this->features);
        $this->providers = new InMemoryProviders();
        $this->tx = new InMemoryTransaction();
        $this->events = new RecordingEvents();
        $this->clock = new FixedClock();
        $this->mapService = new MapService($this->maps, $this->layers, $this->features, $this->tx, $this->clock, $this->events);
        $this->layerService = new LayerService($this->maps, $this->layers, $this->features, $this->tx, $this->clock, $this->events);
        $this->featureService = new FeatureService($this->layers, $this->features, $this->clock, $this->events, $this->categories);
        $this->providerService = new ProviderService($this->providers);
        $this->categoryService = new CategoryService($this->categories, $this->tx);
    }
}
