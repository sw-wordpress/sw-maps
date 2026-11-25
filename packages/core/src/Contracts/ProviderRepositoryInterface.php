<?php

declare(strict_types=1);

namespace SWMaps\Core\Contracts;

use SWMaps\Core\Domain\Provider;

interface ProviderRepositoryInterface
{
    public function find(int $id): ?Provider;

    /** @return list<Provider> */
    public function all(): array;

    /** The enabled default provider (OpenStreetMap unless disabled). */
    public function defaultProvider(): ?Provider;

    public function save(Provider $provider): Provider;

    public function delete(int $id): void;
}
