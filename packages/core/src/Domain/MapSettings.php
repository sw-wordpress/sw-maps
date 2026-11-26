<?php

declare(strict_types=1);

namespace SWMaps\Core\Domain;

use SWMaps\Core\Exception\ValidationException;

/** Presentation and interaction settings of a map. Scroll zoom is off by default (no scroll trapping). */
final class MapSettings
{
    public const MIN_HEIGHT = 120;
    public const MAX_HEIGHT = 2000;

    public function __construct(
        public readonly int $height,
        public readonly ?string $aspectRatio,
        public readonly bool $scrollZoom,
        public readonly bool $touch,
        public readonly bool $drag,
        public readonly bool $zoomControls,
        public readonly bool $fullscreen,
        public readonly bool $scale,
        public readonly bool $showList,
        public readonly bool $clickToLoad,
        public readonly bool $cluster,
        public readonly bool $ajax,
        public readonly ?int $providerId
    ) {
        $errors = [];
        if ($height < self::MIN_HEIGHT || $height > self::MAX_HEIGHT) {
            $errors['settings.height'] = sprintf('Height must be between %d and %d.', self::MIN_HEIGHT, self::MAX_HEIGHT);
        }
        if ($aspectRatio !== null && preg_match('/^[1-9][0-9]{0,2}:[1-9][0-9]{0,2}$/', $aspectRatio) !== 1) {
            $errors['settings.aspectRatio'] = 'Aspect ratio must look like 16:9.';
        }
        if ($providerId !== null && $providerId < 1) {
            $errors['settings.providerId'] = 'Invalid provider.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    public static function default(): self
    {
        return new self(400, null, false, true, true, true, true, true, true, false, true, false, null);
    }

    private const BOOLEANS = ['scrollZoom', 'touch', 'drag', 'zoomControls', 'fullscreen', 'scale', 'showList', 'clickToLoad', 'cluster', 'ajax'];

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, ?self $base = null): self
    {
        $base ??= self::default();
        $flags = [];
        foreach (self::BOOLEANS as $key) {
            $flags[$key] = $base->{$key};
            if (array_key_exists($key, $data)) {
                if (!is_bool($data[$key])) {
                    throw ValidationException::field('settings.' . $key, 'Must be a boolean.');
                }
                $flags[$key] = $data[$key];
            }
        }
        $height = $base->height;
        if (array_key_exists('height', $data)) {
            if (!is_int($data['height'])) {
                throw ValidationException::field('settings.height', 'Must be an integer.');
            }
            $height = $data['height'];
        }
        $ratio = $base->aspectRatio;
        if (array_key_exists('aspectRatio', $data)) {
            if ($data['aspectRatio'] !== null && !is_string($data['aspectRatio'])) {
                throw ValidationException::field('settings.aspectRatio', 'Must be a string.');
            }
            $ratio = $data['aspectRatio'];
        }
        $provider = $base->providerId;
        if (array_key_exists('providerId', $data)) {
            if ($data['providerId'] !== null && !is_int($data['providerId'])) {
                throw ValidationException::field('settings.providerId', 'Must be an integer.');
            }
            $provider = $data['providerId'];
        }

        return new self(
            $height,
            $ratio,
            $flags['scrollZoom'],
            $flags['touch'],
            $flags['drag'],
            $flags['zoomControls'],
            $flags['fullscreen'],
            $flags['scale'],
            $flags['showList'],
            $flags['clickToLoad'],
            $flags['cluster'],
            $flags['ajax'],
            $provider
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'height' => $this->height,
            'aspectRatio' => $this->aspectRatio,
            'scrollZoom' => $this->scrollZoom,
            'touch' => $this->touch,
            'drag' => $this->drag,
            'zoomControls' => $this->zoomControls,
            'fullscreen' => $this->fullscreen,
            'scale' => $this->scale,
            'showList' => $this->showList,
            'clickToLoad' => $this->clickToLoad,
            'cluster' => $this->cluster,
            'ajax' => $this->ajax,
            'providerId' => $this->providerId,
        ];
    }
}
