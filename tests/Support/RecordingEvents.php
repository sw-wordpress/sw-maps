<?php

declare(strict_types=1);

namespace SWMaps\Tests\Support;

use SWMaps\Core\Contracts\EventsInterface;

final class RecordingEvents implements EventsInterface
{
    /** @var list<string> */
    public array $log = [];

    public function mapPublished(int $mapId): void
    {
        $this->log[] = 'published:' . $mapId;
    }

    public function layerUpdated(int $layerId, int $mapId): void
    {
        $this->log[] = 'layer:' . $layerId . ':' . $mapId;
    }
}
