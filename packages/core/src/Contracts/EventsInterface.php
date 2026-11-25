<?php

declare(strict_types=1);

namespace SWMaps\Core\Contracts;

/** Domain events, dispatched only after the data is committed. Listener failures never affect the operation. */
interface EventsInterface
{
    public function mapPublished(int $mapId): void;

    public function layerUpdated(int $layerId, int $mapId): void;
}
