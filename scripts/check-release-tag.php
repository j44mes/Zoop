<?php
/**
 * Fail when a release tag disagrees with the plugin version strings.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

require_once __DIR__ . '/validate-version.php';

/**
 * Compare a Git tag with the three version strings. An empty string means they agree.
 */
function seostudio_release_identity_error( string $tag, string $header, string $constant, string $stable ): string {
	$tag = preg_replace( '/\Av/', '', trim( $tag ) );
	$tag = is_string( $tag ) ? $tag : '';
	if ( ! seostudio_version_valid( $tag ) ) {
		return "Tag '{$tag}' is not a controlled version.";
	}
	if ( $tag !== $header || $tag !== $constant || $tag !== $stable ) {
		return "Tag '{$tag}' does not match header '{$header}', constant '{$constant}', stable tag '{$stable}'.";
	}

	return '';
}

/**
 * Read the checkout and compare it with the tag argument.
 */
function seostudio_release_tag_main( array $argv ): int {
	$tag = $argv[1] ?? '';
	$root = dirname( __DIR__ );
	$plugin = (string) file_get_contents( $root . '/seo-studio.php' );
	$readme = (string) file_get_contents( $root . '/readme.txt' );
	$header = '';
	$constant = '';
	$stable = '';
	if ( preg_match( '/^\s*\*\s*Version:\s*(\S+)\s*$/m', $plugin, $found ) ) {
		$header = $found[1];
	}
	if ( preg_match( "/define\\(\\s*'SEOSTUDIO_VERSION'\\s*,\\s*'([^']+)'\\s*\\)/", $plugin, $found ) ) {
		$constant = $found[1];
	}
	if ( preg_match( '/^Stable tag:\s*(\S+)\s*$/m', $readme, $found ) ) {
		$stable = $found[1];
	}

	$error = seostudio_release_identity_error( $tag, $header, $constant, $stable );
	if ( '' !== $error ) {
		fwrite( STDERR, $error . "\n" );
		return 1;
	}

	fwrite( STDOUT, "Tag {$tag} matches {$constant}.\n" );
	return 0;
}

if ( isset( $argv[0] ) && 'check-release-tag.php' === basename( (string) $argv[0] ) ) {
	exit( seostudio_release_tag_main( $argv ) );
}
