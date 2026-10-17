<?php
/**
 * Values substituted into SEO title templates.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Content;

/**
 * The post and site strings a template may insert.
 */
final class TemplateValues {
	public function __construct(
		public readonly string $title,
		public readonly string $siteName,
		public readonly string $separator,
		public readonly string $excerpt,
		public readonly string $category,
		public readonly string $tagline
	) {}

	/**
	 * Expand the tokens SEO Studio and the detected plugins use.
	 */
	public function expand( string $template ): string {
		$pairs = array(
			'%%post_excerpt%%' => $this->excerpt,
			'%%post_title%%'   => $this->title,
			'%%sitetitle%%'    => $this->siteName,
			'%%excerpt%%'      => $this->excerpt,
			'%%category%%'     => $this->category,
			'%%sitename%%'     => $this->siteName,
			'%%tagline%%'      => $this->tagline,
			'%%title%%'        => $this->title,
			'%%sep%%'          => $this->separator,
			'%excerpt%'        => $this->excerpt,
			'%category%'       => $this->category,
			'%sitename%'       => $this->siteName,
			'%title%'          => $this->title,
			'%sep%'            => $this->separator,
			'#separator_sa'    => $this->separator,
			'#post_excerpt'    => $this->excerpt,
			'#post_title'      => $this->title,
			'#site_title'      => $this->siteName,
		);

		$expanded  = strtr( $template, $pairs );
		$collapsed = preg_replace( '/\s+/u', ' ', $expanded );
		return trim( is_string( $collapsed ) ? $collapsed : $expanded );
	}
}
