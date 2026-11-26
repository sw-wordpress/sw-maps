<?php

declare(strict_types=1);

namespace SWMaps\Core\Import;

enum ImportMode: string
{
    case NewLayer = 'new_layer';
    case Append = 'append';
    case Upsert = 'upsert';
    case Replace = 'replace';
}
