<?php

declare(strict_types=1);

namespace SWMaps\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SWMaps\Core\Domain\Provider;
use SWMaps\Core\Domain\ProviderType;
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

    /** @return iterable<string, array{string, bool}> */
    public static function pmtilesUrls(): iterable
    {
        yield 'https file' => ['https://example.org/maps/world.pmtiles', true];
        yield 'with query' => ['https://cdn.example.org/a.pmtiles?v=2', true];
        yield 'upper case' => ['https://example.org/A.PMTILES', true];
        yield 'http' => ['http://example.org/a.pmtiles', true];
        yield 'wrong extension' => ['https://example.org/a.png', false];
        yield 'extension only in query' => ['https://example.org/a?x=.pmtiles', false];
        yield 'tile template' => ['https://example.org/{z}/{x}/{y}.pmtiles', false];
        yield 'javascript' => ['javascript:alert(1).pmtiles', false];
        yield 'whitespace' => ['https://example.org/a b.pmtiles', false];
        yield 'empty' => ['', false];
    }

    #[DataProvider('pmtilesUrls')]
    public function testPmtilesUrlValidation(string $url, bool $valid): void
    {
        self::assertSame($valid, Provider::validPmtilesUrl($url));
        $service = (new Fixture())->providerService;
        $input = ['type' => 'pmtiles', 'name' => 'Archive', 'public_config' => ['urlTemplate' => $url, 'attribution' => '© Protomaps', 'maxZoom' => 14]];
        if ($valid) {
            $p = $service->create($input);
            self::assertSame(ProviderType::Pmtiles, $p->type);
            self::assertSame($url, $p->toPublicArray()['urlTemplate'], 'no key placeholder handling for archives');
            self::assertSame([(string) parse_url($url, PHP_URL_HOST)], $p->externalHosts());
            self::assertSame('pmtiles', $p->toArray()['type']);
        } else {
            $this->expectException(ValidationException::class);
            $service->create($input);
        }
    }

    public function testTypeIsValidatedAndFixed(): void
    {
        $service = (new Fixture())->providerService;
        $p = $service->create(['type' => 'pmtiles', 'name' => 'A', 'public_config' => ['urlTemplate' => 'https://example.org/a.pmtiles', 'attribution' => 'x']]);
        self::assertSame('x', $service->update($p->id, ['public_config' => ['attribution' => 'x']])->attribution);
        self::assertSame(ProviderType::Pmtiles, $service->update($p->id, ['name' => 'B', 'type' => 'pmtiles'])->type);
        foreach ([['type' => 'xyz'], ['type' => 'wms']] as $patch) {
            try {
                $service->update($p->id, $patch);
                self::fail('expected ValidationException');
            } catch (ValidationException $e) {
                self::assertArrayHasKey('type', $e->errors());
            }
        }
        self::assertSame(ProviderType::Pmtiles, Provider::pmtiles('P', 'https://example.org/a.pmtiles', 'x', 12)->type);
    }
}
