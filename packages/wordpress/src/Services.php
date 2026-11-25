<?php
/**
 * Lazy service container wiring the core services to the wpdb adapters.
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress;

use SWMaps\Core\Application\FeatureService;
use SWMaps\Core\Application\LayerService;
use SWMaps\Core\Application\MapService;
use SWMaps\Core\Application\ProviderService;
use SWMaps\Core\Contracts\FeatureRepositoryInterface;
use SWMaps\Core\Contracts\LayerRepositoryInterface;
use SWMaps\Core\Contracts\MapRepositoryInterface;
use SWMaps\Core\Contracts\ProviderRepositoryInterface;
use SWMaps\WordPress\Persistence\SystemClock;
use SWMaps\WordPress\Persistence\WpdbFeatures;
use SWMaps\WordPress\Persistence\WpdbLayers;
use SWMaps\WordPress\Persistence\WpdbMaps;
use SWMaps\WordPress\Persistence\WpdbProviders;
use SWMaps\WordPress\Persistence\WpdbTransaction;

final class Services {

	private static ?self $instance = null;

	private ?MapService $maps         = null;
	private ?LayerService $layers     = null;
	private ?FeatureService $features = null;
	private ?ProviderService $prov    = null;

	private readonly MapRepositoryInterface $map_repo;
	private readonly LayerRepositoryInterface $layer_repo;
	private readonly FeatureRepositoryInterface $feature_repo;
	private readonly ProviderRepositoryInterface $provider_repo;
	private readonly WpdbTransaction $tx;
	private readonly Events $events;
	private readonly SystemClock $clock;

	private function __construct() {
		$this->map_repo      = new WpdbMaps();
		$this->layer_repo    = new WpdbLayers();
		$this->feature_repo  = new WpdbFeatures();
		$this->provider_repo = new WpdbProviders();
		$this->tx            = new WpdbTransaction();
		$this->events        = new Events();
		$this->clock         = new SystemClock();
	}

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/** For tests: forget cached services. */
	public static function reset(): void {
		self::$instance = null;
	}

	public function maps(): MapService {
		return $this->maps ??= new MapService( $this->map_repo, $this->layer_repo, $this->feature_repo, $this->tx, $this->clock, $this->events );
	}

	public function layers(): LayerService {
		return $this->layers ??= new LayerService( $this->map_repo, $this->layer_repo, $this->feature_repo, $this->tx, $this->clock, $this->events );
	}

	public function features(): FeatureService {
		return $this->features ??= new FeatureService( $this->layer_repo, $this->feature_repo, $this->clock, $this->events );
	}

	public function providers(): ProviderService {
		return $this->prov ??= new ProviderService( $this->provider_repo );
	}

	public function map_repository(): MapRepositoryInterface {
		return $this->map_repo;
	}

	public function layer_repository(): LayerRepositoryInterface {
		return $this->layer_repo;
	}

	public function feature_repository(): FeatureRepositoryInterface {
		return $this->feature_repo;
	}

	public function provider_repository(): ProviderRepositoryInterface {
		return $this->provider_repo;
	}
}
