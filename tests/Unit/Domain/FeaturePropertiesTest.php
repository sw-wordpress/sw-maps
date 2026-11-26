<?php

declare(strict_types=1);

namespace SWMaps\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SWMaps\Core\Domain\FeatureProperties;
use SWMaps\Core\Exception\ValidationException;

final class FeaturePropertiesTest extends TestCase
{
    public function testTagsAreStrippedFromAllTextFields(): void
    {
        $p = FeaturePropertiesTest::make(['title' => '<script>alert(1)</script>Rathaus', 'description' => "a<img src=x onerror=alert(1)>\nb", 'address' => '<b>Markt 1</b>']);
        self::assertSame('alert(1)Rathaus', $p->title);
        self::assertSame("a\nb", $p->description);
        self::assertSame('Markt 1', $p->address);
    }

    /** @param array<string, mixed> $data */
    private static function make(array $data): FeatureProperties
    {
        return FeatureProperties::fromArray($data);
    }

    /** @return array<string, array{string, bool}> */
    public static function links(): array
    {
        return [
            'https' => ['https://example.org/a?b=1#c', true],
            'http' => ['http://example.org', true],
            'mailto' => ['mailto:a@example.org', true],
            'tel' => ['tel:+4912345', true],
            'javascript' => ['javascript:alert(1)', false],
            'data' => ['data:text/html,<script>', false],
            'protocol relative' => ['//evil.example', false],
            'whitespace' => ['https://example.org/ onclick=x', false],
            'quote' => ['https://example.org/"x', false],
            'vbscript mixed case' => ['JaVaScRiPt:alert(1)', false],
        ];
    }

    #[DataProvider('links')]
    public function testLinkAllowlist(string $url, bool $ok): void
    {
        if (!$ok) {
            $this->expectException(ValidationException::class);
        }
        $p = self::make(['link' => $url]);
        self::assertSame($url, $p->link);
    }

    public function testEmptyLinkClearsValue(): void
    {
        $p = FeatureProperties::fromArray(['link' => ''], self::make(['link' => 'https://a.example']));
        self::assertNull($p->link);
    }

    public function testIcons(): void
    {
        self::assertSame('media:42', self::make(['icon' => 'media:42'])->icon);
        self::assertSame('builtin:star', self::make(['icon' => 'builtin:star'])->icon);
        foreach (['builtin:../x', 'media:0', 'http://x', 'media:1;drop', 5] as $bad) {
            try {
                self::make(['icon' => $bad]);
                self::fail('accepted ' . var_export($bad, true));
            } catch (ValidationException $e) {
                self::assertArrayHasKey('properties.icon', $e->errors());
            }
        }
    }

    public function testLengthLimitsAndTypes(): void
    {
        try {
            self::make(['title' => str_repeat('x', 201), 'description' => 5, 'image_id' => 0]);
            self::fail('expected exception');
        } catch (ValidationException $e) {
            self::assertEqualsCanonicalizing(['properties.title', 'properties.description', 'properties.image_id'], array_keys($e->errors()));
        }
    }

    public function testPatchKeepsOtherFields(): void
    {
        $base = self::make(['title' => 'A', 'address' => 'B', 'image_id' => 7]);
        $next = FeatureProperties::fromArray(['title' => 'C'], $base);
        self::assertSame(['C', 'B', 7], [$next->title, $next->address, $next->imageId]);
        self::assertNull(FeatureProperties::fromArray(['image_id' => null], $base)->imageId);
    }

    public function testControlCharactersAreRemoved(): void
    {
        self::assertSame('ab', FeatureProperties::plain("a\x00\x07b", false));
        self::assertSame('a b', FeatureProperties::plain("a \n\t b", false));
    }

    public function testLenientReadingNeverThrowsAndKeepsValidFields(): void
    {
        $p = FeatureProperties::lenient(['title' => 'Ok', 'link' => 'javascript:alert(1)', 'icon' => 'evil', 'image_id' => -4, 'description' => ['x'], 'unknown' => 1]);
        self::assertSame('Ok', $p->title);
        self::assertNull($p->link);
        self::assertSame('builtin:pin', $p->icon);
        self::assertNull($p->imageId);
        self::assertSame('', $p->description);
    }
}
