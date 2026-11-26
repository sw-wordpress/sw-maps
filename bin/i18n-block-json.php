<?php
/**
 * Writes the JED JSON catalogs for the block editor script (blocks/map/index.js) from languages/sw-maps-<locale>.po.
 * WordPress loads languages/sw-maps-<locale>-<md5 of the script path>.json for scripts translated with
 * wp_set_script_translations() (done by WordPress for block.json "editorScript" with a "textdomain").
 * Development machine only.
 */

declare(strict_types=1);

$root    = dirname( __DIR__ );
$scripts = array( 'blocks/map/index.js' );

foreach ( glob( $root . '/languages/sw-maps-*.po' ) ?: array() as $po_file ) {
	if ( 1 !== preg_match( '/sw-maps-([a-zA-Z_]+)\.po$/', $po_file, $m ) ) {
		continue;
	}
	$locale  = $m[1];
	$entries = parse_po( (string) file_get_contents( $po_file ) );
	foreach ( $scripts as $script ) {
		$messages = array(
			'' => array(
				'domain'       => 'messages',
				'lang'         => $locale,
				'plural-forms' => 'nplurals=2; plural=n != 1;',
			),
		);
		foreach ( $entries as $entry ) {
			if ( '' !== $entry['msgstr'] && in_array( $script, $entry['references'], true ) ) {
				$messages[ $entry['msgid'] ] = array( $entry['msgstr'] );
			}
		}
		$json = json_encode(
			array(
				'translation-revision-date' => gmdate( 'Y-m-d H:iO', 0 ),
				'generator'                 => 'sw-maps bin/i18n-block-json.php',
				'domain'                    => 'messages',
				'locale_data'               => array( 'messages' => $messages ),
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
		);
		file_put_contents( sprintf( '%s/languages/sw-maps-%s-%s.json', $root, $locale, md5( $script ) ), $json . "\n" ); // NOSONAR: md5 is dictated by WordPress for script translation file names, not a security use
	}
}

/**
 * Minimal .po parser (single and multi line strings, no plurals; enough for this project's catalogs).
 *
 * @return list<array{msgid: string, msgstr: string, references: list<string>}>
 */
function parse_po( string $po ): array {
	$entries = array();
	foreach ( preg_split( '/\R\R+/', trim( $po ) ) ?: array() as $block ) {
		$refs = array();
		$id   = null;
		$str  = null;
		$mode = '';
		foreach ( preg_split( '/\R/', $block ) ?: array() as $line ) {
			if ( str_starts_with( $line, '#:' ) ) {
				foreach ( preg_split( '/\s+/', trim( substr( $line, 2 ) ) ) ?: array() as $ref ) {
					$refs[] = preg_replace( '/:\d+$/', '', $ref );
				}
			} elseif ( str_starts_with( $line, 'msgid ' ) ) {
				$mode = 'id';
				$id   = unquote( substr( $line, 6 ) );
			} elseif ( str_starts_with( $line, 'msgstr ' ) ) {
				$mode = 'str';
				$str  = unquote( substr( $line, 7 ) );
			} elseif ( str_starts_with( $line, '"' ) ) {
				if ( 'id' === $mode ) {
					$id .= unquote( $line );
				} elseif ( 'str' === $mode ) {
					$str .= unquote( $line );
				}
			}
		}
		if ( null !== $id && '' !== $id && null !== $str ) {
			$entries[] = array(
				'msgid'      => $id,
				'msgstr'     => $str,
				'references' => $refs,
			);
		}
	}
	return $entries;
}

function unquote( string $quoted ): string {
	$decoded = json_decode( trim( $quoted ), true );
	return is_string( $decoded ) ? $decoded : '';
}
