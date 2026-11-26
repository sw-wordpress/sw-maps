<?php

declare(strict_types=1);

namespace SWMaps\Tests\Support;

use SWMaps\Core\Contracts\ProviderRepositoryInterface;
use SWMaps\Core\Domain\Provider;

final class InMemoryProviders implements ProviderRepositoryInterface
{
    /** @var array<int, Provider> */
    public array $items = [];
    private int $seq = 0;

    public function find(int $id): ?Provider
    {
        return $this->items[$id] ?? null;
    }

    public function all(): array
    {
        return array_values($this->items);
    }

    public function defaultProvider(): ?Provider
    {
        foreach ($this->items as $p) {
            if ($p->enabled) {
                return $p;
            }
        }

        return null;
    }

    public function save(Provider $provider): Provider
    {
        $saved = $provider->id === 0 ? new Provider(++$this->seq, $provider->type, $provider->name, $provider->urlTemplate, $provider->attribution, $provider->minZoom, $provider->maxZoom, $provider->tileSize, $provider->publicKey, $provider->secretConstant, $provider->enabled) : $provider;
        $this->items[$saved->id] = $saved;

        return $saved;
    }

    public function delete(int $id): void
    {
        unset($this->items[$id]);
    }
}
