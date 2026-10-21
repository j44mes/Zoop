<?php
/**
 * Renders another plugin's title, description, and canonical from stored fields.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Integrations;

use SEOStudio\Content\ForeignHead;
use SEOStudio\Content\TemplateValues;

/**
 * Applies each plugin's default template when the stored field is empty.
 */
final class PluginHead {
	/**
	 * Render one plugin's head fields.
	 *
	 * @param array{title?: string, description?: string, canonical?: string, title_template?: string, description_template?: string, append_site_name?: bool} $stored
	 */
	public function render( string $slug, array $stored, TemplateValues $values, string $permalink ): ForeignHead {
		$title_template = (string) ( $stored['title_template'] ?? '' );
		$desc_template  = (string) ( $stored['description_template'] ?? '' );
		if ( '' === $title_template ) {
			$title_template = $this->defaultTitle( $slug );
		}

		$title = (string) ( $stored['title'] ?? '' );
		if ( '' === trim( $title ) ) {
			$title = $title_template;
		}

		$description = (string) ( $stored['description'] ?? '' );
		if ( '' === trim( $description ) ) {
			$description = trim( $desc_template );
		}

		if ( 'tsf' === $slug && '' === trim( (string) ( $stored['title'] ?? '' ) ) && ! empty( $stored['append_site_name'] ) ) {
			$title = trim( $values->title . ' ' . $values->separator . ' ' . $values->siteName );
		}

		$canonical = trim( (string) ( $stored['canonical'] ?? '' ) );

		return new ForeignHead(
			$values->expand( $title ),
			$values->expand( $description ),
			'' === $canonical ? $permalink : $canonical
		);
	}

	private function defaultTitle( string $slug ): string {
		return match ( $slug ) {
			'rank_math' => '%title% %sep% %sitename%',
			'seopress' => '%%post_title%% %%sep%% %%sitetitle%%',
			'aioseo' => '#post_title #separator_sa #site_title',
			'tsf' => '%%title%%',
			default => '%%title%% %%sep%% %%sitename%%',
		};
	}
}
