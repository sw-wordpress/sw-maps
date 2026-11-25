<?php

declare(strict_types=1);

namespace SWMaps\Tests\Integration;

use PHPUnit\Framework\TestCase;

/** The gettext catalogs must be complete and compiled (docs/development.md, bin/i18n.sh). */
final class I18nTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testCatalogsAreCompleteAndUpToDate(): void
    {
        exec('command -v xgettext msgfmt msgmerge msgcat', $tools, $code);
        if ($code !== 0) {
            self::markTestSkipped('GNU gettext is not installed.');
        }
        exec(sprintf('bash %s check 2>&1', escapeshellarg($this->root . '/bin/i18n.sh')), $output, $status);
        self::assertSame(0, $status, implode("\n", $output));
    }

    public function testGermanCatalogCoversEverySourceString(): void
    {
        $pot = (string) file_get_contents($this->root . '/languages/sw-maps.pot');
        $po = (string) file_get_contents($this->root . '/languages/sw-maps-de_DE.po');
        preg_match_all('/^msgid "(.+)"$/m', $pot, $source);
        self::assertNotEmpty($source[1]);
        foreach ($source[1] as $msgid) {
            self::assertStringContainsString('msgid "' . $msgid . '"', $po, $msgid);
        }
        self::assertDoesNotMatchRegularExpression('/^msgstr ""\n(?!")/m', preg_replace('/^msgid ""\nmsgstr ""\n(".*\n)+/m', '', $po) ?? '');
    }

    public function testCompiledCatalogsAndBlockJsonExist(): void
    {
        self::assertFileExists($this->root . '/languages/sw-maps-de_DE.mo');
        $json = $this->root . '/languages/sw-maps-de_DE-' . md5('blocks/map/index.js') . '.json';
        self::assertFileExists($json);
        $data = json_decode((string) file_get_contents($json), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Karte auswählen', $data['locale_data']['messages']['Select a map'][0]);
    }

    public function testEveryTranslatedStringOfTheSpaExistsInBothLanguages(): void
    {
        // Parity of the SPA catalogs is enforced by apps/admin/tests/config.test.ts; this guards the files' presence.
        self::assertFileExists($this->root . '/apps/admin/src/i18n/de.ts');
        self::assertFileExists($this->root . '/apps/admin/src/i18n/en.ts');
    }
}
