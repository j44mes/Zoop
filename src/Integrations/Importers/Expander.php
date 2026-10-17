<?php
/**
 * Expands the common title variables an importer understands.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Integrations\Importers;

/**
 * Supported tokens stay live and expand at output time. Unknown tokens are left in place so an editor can review them.
 */
final class Expander {
	/**
	 * Vendor tokens mapped to SEO Studio's own. Identity entries stop strtr from matching a
	 * shorter Rank Math token inside an existing SEO Studio token.
	 */
	private const TOKENS = array(
		'%%title%%'        => '%%title%%',
		'%%sitename%%'     => '%%sitename%%',
		'%%sep%%'          => '%%sep%%',
		'%%excerpt%%'      => '%%excerpt%%',
		'%%category%%'     => '%%category%%',
		'%%tagline%%'      => '%%tagline%%',
		'%%sitedesc%%'     => '%%tagline%%',
		'%%post_title%%'   => '%%title%%',
		'%%sitetitle%%'    => '%%sitename%%',
		'%%post_excerpt%%' => '%%excerpt%%',
		'%title%'          => '%%title%%',
		'%sitename%'       => '%%sitename%%',
		'%sep%'            => '%%sep%%',
		'%excerpt%'        => '%%excerpt%%',
		'%category%'       => '%%category%%',
		'%sitedesc%'       => '%%tagline%%',
	);

	/**
	 * Rewrite a vendor template into SEO Studio tokens without expanding it, so the
	 * stored title keeps following the post title, site name and separator.
	 */
	public function convert( string $template ): string {
		$collapsed = preg_replace( '/\s+/u', ' ', trim( $template ) );
		return strtr( is_string( $collapsed ) ? $collapsed : trim( $template ), self::TOKENS );
	}

	/**
	 * Whether a converted template still holds a token SEO Studio cannot expand.
	 */
	public function needsReview( string $template ): bool {
		$unknown = str_replace( array_unique( array_values( self::TOKENS ) ), '', $template );
		return 1 === preg_match( '/%%[^%]+%%/', $unknown ) || 1 === preg_match( '/%[a-z0-9_]+%/', $unknown );
	}
}
