<?php

declare(strict_types=1);

namespace SWMaps\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SWMaps\Core\Domain\Provider;
use SWMaps\Core\Exception\NotFoundException;
use SWMaps\Core\Exception\ValidationException;
use SWMaps\Tests\Support\Fixture;

final class ProviderTest extends TestCase
{
    public function testOpenStreetMapPreset(): void
    {
        $p = Provider::openStreetMap(1);
        self::assertTrue($p->enabled);
        self::assertNull($p->secretConstant);
        self::assertSame('https://tile.openstreetmap.org/{z}/{x}/{y}.png', $p->toPublicArray()['urlTemplate']);
        self::assertStringContainsString('OpenStreetMap', $p->attribution);
    }

    /** @return array<string, array{string, bool}> */
    public static function templates(): array
    {
        return [
            'ok' => ['https://tiles.example.org/{z}/{x}/{y}.png', true],
            'http ok' => ['http://tiles.local/{z}/{x}/{y}.png?key={key}', true], // NOSONAR: plain HTTP is allowed for self hosted tile servers
            'tms' => ['https://t.example.org/{z}/{x}/{-y}.png', true],
            'javascript' => ['javascript:alert({z}{x}{y})', false],
            'missing y' => ['https://t.example.org/{z}/{x}.png', false],
            'space' => ['https://t.example.org/{z}/{x}/{y} .png', false],
            'angle' => ['https://t.example.org/<{z}/{x}/{y}', false],
        ];
    }

    #[DataProvider('templates')]
    public function testUrlTemplateValidation(string $url, bool $ok): void
    {
        self::assertSame($ok, Provider::validUrlTemplate($url));
    }

    public function testServiceCreateUpdateDelete(): void
    {
        $f = new Fixture();
        $p = $f->providerService->create(['name' => 'Own', 'enabled' => true, 'secret_constant' => 'SWMAP_OWN_KEY', 'public_config' => ['urlTemplate' => 'https://t.example.org/{z}/{x}/{y}.png?k={key}', 'attribution' => '© [Own](https://example.org)', 'maxZoom' => 17, 'publicKey' => 'a b']]);
        self::assertSame('https://t.example.org/{z}/{x}/{y}.png?k=a%20b', $p->resolvedUrlTemplate());
        self::assertSame('SWMAP_OWN_KEY', $p->toArray()['secret_constant']);
        self::assertArrayNotHasKey('secret_constant', $p->toPublicArray());
        $u = $f->providerService->update($p->id, ['name' => 'Renamed', 'secret_constant' => '', 'enabled' => false]);
        self::assertSame(['Renamed', null, false, 17], [$u->name, $u->secretConstant, $u->enabled, $u->maxZoom]);
        self::assertCount(1, $f->providerService->list());
        $f->providerService->delete($p->id);
        $this->expectException(NotFoundException::class);
        $f->providerService->get($p->id);
    }

    public function testMarkupInAttributionIsStripped(): void
    {
        $f = new Fixture();
        $p = $f->providerService->create(['name' => 'X', 'public_config' => ['urlTemplate' => 'https://t.example.org/{z}/{x}/{y}.png', 'attribution' => '<script>alert(1)</script>© A']]);
        self::assertStringNotContainsString('<', $p->attribution);
    }

    public function testServiceRejectsInvalidInput(): void
    {
        $f = new Fixture();
        $ok = ['urlTemplate' => 'https://t.example.org/{z}/{x}/{y}.png', 'attribution' => 'A'];
        $cases = [
            ['name' => '', 'public_config' => $ok],
            ['name' => 'X', 'public_config' => ['urlTemplate' => 'ftp://x/{z}/{x}/{y}'] + $ok], // NOSONAR: negative test
            ['name' => 'X', 'public_config' => ['attribution' => '[x](javascript:alert(1))'] + $ok],
            ['name' => 'X', 'public_config' => ['maxZoom' => 30] + $ok],
            ['name' => 'X', 'public_config' => ['tileSize' => 300] + $ok],
            ['name' => 'X', 'public_config' => $ok, 'secret_constant' => 'lowercase'],
            ['name' => 'X', 'public_config' => $ok, 'secret_constant' => 'DB_PASSWORD'],
            ['name' => 'X', 'public_config' => $ok, 'secret_constant' => 'AUTH_KEY'],
            ['name' => 'X', 'public_config' => $ok, 'secret_constant' => 'SWMAP_'],
            ['name' => 'X', 'public_config' => $ok, 'secret_constant' => 5],
            ['name' => 'X', 'public_config' => $ok, 'enabled' => 'yes'],
        ];
        foreach ($cases as $case) {
            try {
                $f->providerService->create($case);
                self::fail('accepted ' . json_encode($case));
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
        self::assertSame([], $f->providers->items);
    }
}
