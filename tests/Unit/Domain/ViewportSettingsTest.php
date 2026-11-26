<?php

declare(strict_types=1);

namespace SWMaps\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use SWMaps\Core\Domain\MapSettings;
use SWMaps\Core\Domain\Viewport;
use SWMaps\Core\Exception\InvalidGeometryException;
use SWMaps\Core\Exception\ValidationException;

final class ViewportSettingsTest extends TestCase
{
    public function testViewportRoundtrip(): void
    {
        $v = Viewport::fromArray(['center' => [8.4, 49.0], 'zoom' => 12, 'minZoom' => 3, 'maxZoom' => 18, 'bounds' => [8, 48, 9, 50], 'autoBounds' => false]);
        self::assertSame($v->toArray(), Viewport::fromArray($v->toArray())->toArray());
        self::assertFalse($v->autoBounds);
    }

    public function testViewportPatchKeepsBase(): void
    {
        $base = Viewport::fromArray(['zoom' => 7]);
        $next = Viewport::fromArray(['bounds' => null], $base);
        self::assertSame(7.0, $next->zoom);
        self::assertNull($next->bounds);
    }

    public function testZoomMustBeWithinLimits(): void
    {
        $this->expectException(ValidationException::class);
        Viewport::fromArray(['zoom' => 25]);
    }

    public function testZoomLimitsOrder(): void
    {
        $this->expectException(ValidationException::class);
        Viewport::fromArray(['minZoom' => 10, 'maxZoom' => 5, 'zoom' => 7]);
    }

    public function testViewportTypeErrors(): void
    {
        foreach ([['zoom' => 'x'], ['autoBounds' => 1]] as $bad) {
            try {
                Viewport::fromArray($bad);
                self::fail('accepted');
            } catch (ValidationException $e) {
                self::assertNotEmpty($e->errors());
            }
        }
    }

    public function testViewportRejectsInvalidCenter(): void
    {
        $this->expectException(InvalidGeometryException::class);
        Viewport::fromArray(['center' => [500, 0]]);
    }

    public function testSettingsDefaultsDoNotTrapScroll(): void
    {
        $s = MapSettings::default();
        self::assertFalse($s->scrollZoom);
        self::assertTrue($s->touch && $s->drag);
        self::assertSame($s->toArray(), MapSettings::fromArray($s->toArray())->toArray());
    }

    public function testSettingsPatch(): void
    {
        $s = MapSettings::fromArray(['height' => 600, 'aspectRatio' => '16:9', 'scrollZoom' => true, 'providerId' => 3]);
        self::assertSame([600, '16:9', true, 3], [$s->height, $s->aspectRatio, $s->scrollZoom, $s->providerId]);
        $n = MapSettings::fromArray(['aspectRatio' => null, 'providerId' => null], $s);
        self::assertNull($n->aspectRatio);
        self::assertNull($n->providerId);
        self::assertSame(600, $n->height);
    }

    public function testSettingsValidation(): void
    {
        foreach ([['height' => 5], ['height' => '9'], ['aspectRatio' => 'wide'], ['aspectRatio' => 4], ['scrollZoom' => 'yes'], ['providerId' => 0], ['providerId' => 'x']] as $bad) {
            try {
                MapSettings::fromArray($bad);
                self::fail('accepted ' . json_encode($bad));
            } catch (ValidationException $e) {
                self::assertNotEmpty($e->errors());
            }
        }
    }
}
