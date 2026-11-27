<?php
/**
 * Lazy service container wiring the core services to the wpdb adapters.
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress;

use SWMaps\Core\Application\CategoryService;
use SWMaps\Core\Application\FeatureService;
use SWMaps\Core\Application\LayerService;
use SWMaps\Core\Application\MapService;
use SWMaps\Core\Application\ProviderService;
use SWMaps\Core\Application\PublicFeatureService;
use SWMaps\Core\Contracts\CategoryRepositoryInterface;
use SWMaps\Core\Export\MapExporter;
use SWMaps\Core\Geocoding\GeocoderInterface;
use SWMaps\Core\Geocoding\NominatimGeocoder;
use SWMaps\Core\Network\GuardedHttpClient;
use SWMaps\Core\Network\UrlGuard;
use SWMaps\WordPress\Network\PhpHostResolver;
use SWMaps\WordPress\Persistence\OptionRateLimiter;
use SWMaps\WordPress\Network\WpHttpTransport;
use SWMaps\Core\Export\PackageImporter;
use SWMaps\Core\Contracts\FeatureRepositoryInterface;
use SWMaps\Core\Contracts\LayerRepositoryInterface;
use SWMaps\Core\Contracts\MapRepositoryInterface;
use SWMaps\Core\Contracts\ProviderRepositoryInterface;
use SWMaps\Core\Import\ImportLimits;
use SWMaps\Core\Import\ImportService;
use SWMaps\WordPress\Persistence\SystemClock;
use SWMaps\WordPress\Persistence\WpdbCategories;
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
	private ?CategoryService $cats    = null;

	private readonly MapRepositoryInterface $map_repo;
	private readonly LayerRepositoryInterface $layer_repo;
	private readonly FeatureRepositoryInterface $feature_repo;
	private readonly ProviderRepositoryInterface $provider_repo;
	private readonly CategoryRepositoryInterface $category_repo;
	private readonly WpdbTransaction $tx;
	private readonly Events $events;
	private readonly SystemClock $clock;

	private function __construct() {
		$this->map_repo      = new WpdbMaps();
		$this->layer_repo    = new WpdbLayers();
		$this->feature_repo  = new WpdbFeatures();
		$this->provider_repo = new WpdbProviders();
		$this->category_repo = new WpdbCategories();
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
		return $this->features ??= new FeatureService( $this->layer_repo, $this->feature_repo, $this->clock, $this->events, $this->category_repo );
	}

	public function categories(): CategoryService {
		return $this->cats ??= new CategoryService( $this->category_repo, $this->tx );
	}

	public function category_repository(): CategoryRepositoryInterface {
		return $this->category_repo;
	}

	/** Server side requests (provider tests, geocoding): http(s) only, public networks only unless the host is allowlisted. */
	public function http_client(): GuardedHttpClient {
		$guard = new UrlGuard( new PhpHostResolver(), Settings::get()['allowed_hosts'] );
		return new GuardedHttpClient( new WpHttpTransport(), $guard, 5, 1048576 );
	}

	/** Geocoder from the settings; the key (optional) comes from the constant SWMAP_GEOCODER_KEY only. */
	public function geocoder(): GeocoderInterface {
		$settings = Settings::get();
		$key      = defined( 'SWMAP_GEOCODER_KEY' ) && is_string( SWMAP_GEOCODER_KEY ) ? SWMAP_GEOCODER_KEY : null;
		return new NominatimGeocoder( $this->http_client(), new OptionRateLimiter(), $settings['geocoder_url'], $settings['geocoder_contact'], 'SW-Maps/' . SWMAPS_VERSION . ' (' . home_url() . ')', $key );
	}

	public function exporter(): MapExporter {
		return new MapExporter( $this->map_repo, $this->layer_repo, $this->feature_repo, $this->category_repo, $this->clock, $this->package_limits() );
	}

	public function package_importer(): PackageImporter {
		return new PackageImporter( $this->map_repo, $this->layer_repo, $this->feature_repo, $this->category_repo, $this->tx, $this->clock, $this->events, $this->package_limits() );
	}

	private function package_limits(): ImportLimits {
		return new ImportLimits( maxFeatures: Settings::get()['max_features_per_layer'] );
	}

	public function public_features(): PublicFeatureService {
		return new PublicFeatureService( $this->map_repo, $this->layer_repo, $this->feature_repo );
	}

	public function providers(): ProviderService {
		return $this->prov ??= new ProviderService( $this->provider_repo );
	}

	public function imports(): ImportService {
		$max = Settings::get()['max_features_per_layer'];
		return new ImportService(
			$this->map_repo,
			$this->layer_repo,
			$this->feature_repo,
			$this->tx,
			$this->clock,
			$this->events,
			new ImportLimits( maxFeatures: $max ),
			$max
		);
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
