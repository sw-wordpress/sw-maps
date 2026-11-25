<?php

declare(strict_types=1);

namespace SWMaps\Core\Domain;

use SWMaps\Core\Exception\ValidationException;

/** Basemap provider (raster XYZ). The secret itself lives in wp-config; only its constant name is stored. */
final class Provider
{
    public const OSM_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
    public const OSM_ATTRIBUTION = '© [OpenStreetMap](https://www.openstreetmap.org/copyright) contributors';

    public function __construct(
        public readonly int $id,
        public readonly ProviderType $type,
        public readonly string $name,
        public readonly string $urlTemplate,
        public readonly string $attribution,
        public readonly int $minZoom,
        public readonly int $maxZoom,
        public readonly int $tileSize,
        public readonly string $publicKey,
        public readonly ?string $secretConstant,
        public readonly bool $enabled
    ) {
        $errors = [];
        if (trim($name) === '' || mb_strlen($name) > 190) {
            $errors['name'] = 'Name is required (max. 190 characters).';
        }
        if (!self::validUrlTemplate($urlTemplate)) {
            $errors['public_config.urlTemplate'] = 'Must be an http(s) URL containing {z}, {x} and {y}.';
        }
        if (trim($attribution) === '' || mb_strlen($attribution) > 500 || preg_match('/[<>]/', $attribution) === 1) {
            $errors['public_config.attribution'] = 'Attribution is required (plain text, max. 500 characters, no markup).';
        } elseif (preg_match_all('/\]\(([^)]*)\)/', $attribution, $links) > 0) {
            foreach ($links[1] as $href) {
                if (preg_match('#^https?://[^\s"<>\\\\]+$#i', $href) !== 1) {
                    $errors['public_config.attribution'] = 'Attribution links must be http(s) URLs.';
                }
            }
        }
        if ($minZoom < 0 || $maxZoom > Viewport::MAX_ZOOM || $minZoom > $maxZoom) {
            $errors['public_config.zoom'] = 'Invalid zoom limits.';
        }
        if (!in_array($tileSize, [256, 512], true)) {
            $errors['public_config.tileSize'] = 'Tile size must be 256 or 512.';
        }
        if ($secretConstant !== null && preg_match('/^[A-Z][A-Z0-9_]{2,63}$/', $secretConstant) !== 1) {
            $errors['secret_constant'] = 'Must be an upper case constant name.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    public static function validUrlTemplate(string $url): bool
    {
        return strlen($url) <= 2000
            && preg_match('#^https?://[^\s"<>\\\\]+$#i', $url) === 1
            && str_contains($url, '{z}') && str_contains($url, '{x}') && (str_contains($url, '{y}') || str_contains($url, '{-y}'));
    }

    public static function openStreetMap(int $id = 0): self
    {
        return new self($id, ProviderType::Xyz, 'OpenStreetMap', self::OSM_URL, self::OSM_ATTRIBUTION, 0, 19, 256, '', null, true);
    }

    /** The key placeholder is resolved here. Attribution is plain text with optional [label](https://url) links, rendered as DOM nodes by the renderer. */
    public function resolvedUrlTemplate(): string
    {
        return str_replace('{key}', rawurlencode($this->publicKey), $this->urlTemplate);
    }

    /**
     * Hosts the visitor's browser contacts for tiles (shown to the admin before activation). `{s}` subdomain patterns
     * are reported as written.
     *
     * @return list<string>
     */
    public function externalHosts(): array
    {
        $host = parse_url(str_replace(['{', '}'], ['', ''], $this->urlTemplate), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? [$host] : [];
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        return [
            'type' => $this->type->value,
            'urlTemplate' => $this->resolvedUrlTemplate(),
            'attribution' => $this->attribution,
            'minZoom' => $this->minZoom,
            'maxZoom' => $this->maxZoom,
            'tileSize' => $this->tileSize,
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'name' => $this->name,
            'public_config' => [
                'urlTemplate' => $this->urlTemplate,
                'attribution' => $this->attribution,
                'minZoom' => $this->minZoom,
                'maxZoom' => $this->maxZoom,
                'tileSize' => $this->tileSize,
                'publicKey' => $this->publicKey,
            ],
            'secret_constant' => $this->secretConstant,
            'enabled' => $this->enabled,
        ];
    }
}
