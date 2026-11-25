<?php

declare(strict_types=1);

namespace SWMaps\Core\Domain;

enum MapStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
