<?php

declare(strict_types=1);

namespace SWMaps\Core\Domain;

enum ProviderType: string
{
    case Xyz = 'xyz';
    /** Raster tiles from one self hosted PMTiles archive (HTTP range requests). Vector archives are not rendered. */
    case Pmtiles = 'pmtiles';
}
