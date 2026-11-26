<?php

declare(strict_types=1);

namespace SWMaps\Core\Application;

use SWMaps\Core\Contracts\ProviderRepositoryInterface;
use SWMaps\Core\Domain\Provider;
use SWMaps\Core\Domain\ProviderType;
use SWMaps\Core\Exception\NotFoundException;
use SWMaps\Core\Exception\ValidationException;

final class ProviderService
{
    public function __construct(private readonly ProviderRepositoryInterface $providers)
    {
    }

    /** @return list<Provider> */
    public function list(): array
    {
        return $this->providers->all();
    }

    public function get(int $id): Provider
    {
        return $this->providers->find($id) ?? throw NotFoundException::for('Provider', $id);
    }

    /** @param array<string, mixed> $input */
    public function create(array $input): Provider
    {
        return $this->providers->save($this->build(0, $input, null));
    }

    /** @param array<string, mixed> $patch */
    public function update(int $id, array $patch): Provider
    {
        return $this->providers->save($this->build($id, $patch, $this->get($id)));
    }

    public function delete(int $id): void
    {
        $this->get($id);
        $this->providers->delete($id);
    }

    /** @param array<string, mixed> $data */
    private function build(int $id, array $data, ?Provider $base): Provider
    {
        $config = Input::object($data, 'public_config') ?? [];
        $name = Input::string($data, 'name', $base === null, 190) ?? $base->name ?? '';
        $secret = $base?->secretConstant;
        if (array_key_exists('secret_constant', $data)) {
            $raw = $data['secret_constant'];
            if ($raw !== null && !is_string($raw)) {
                throw ValidationException::field('secret_constant', 'Must be a string.');
            }
            $secret = $raw === '' ? null : $raw;
        }
        $string = static fn (string $key, string $fallback, int $max): string => array_key_exists($key, $config)
            ? (Input::string($config, $key, false, $max) ?? $fallback) : $fallback;
        $int = static fn (string $key, int $fallback): int => Input::int($config, $key, 0, 22) ?? $fallback;

        return new Provider(
            $id,
            ProviderType::Xyz,
            $name,
            array_key_exists('urlTemplate', $config) ? (is_string($config['urlTemplate']) ? trim($config['urlTemplate']) : '') : ($base->urlTemplate ?? ''),
            $string('attribution', $base->attribution ?? '', 500),
            $int('minZoom', $base->minZoom ?? 0),
            $int('maxZoom', $base->maxZoom ?? 19),
            Input::int($config, 'tileSize', 256, 512) ?? $base->tileSize ?? 256,
            $string('publicKey', $base->publicKey ?? '', 200),
            $secret,
            Input::bool($data, 'enabled') ?? $base->enabled ?? false
        );
    }
}
