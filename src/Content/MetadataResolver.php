<?php
/**
 * Chooses the title, description, and canonical a crawler would receive.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Content;

/**
 * Owner mode uses SEO Studio fields. Analysis-only uses the active plugin.
 */
final class MetadataResolver {
	/**
	 * Resolve title, description, and canonical.
	 *
	 * @return array{title_tag: string, meta_description: string, canonical: string}
	 */
	public function resolve( AnalysisContext $context ): array {
		if ( $context->publishesOwnMetadata() ) {
			if ( null !== $context->homepage ) {
				return $context->homepage;
			}
			return array(
				'title_tag'        => $this->ownOrFallback( $context->values->expand( $context->seoTitle ), $context->postTitle ),
				'meta_description' => $this->ownOrFallback( $context->values->expand( $context->seoDescription ), $context->excerpt ),
				'canonical'        => $this->ownOrFallback( trim( $context->seoCanonical ), $context->permalink ),
			);
		}

		if ( $context->foreign instanceof ForeignHead ) {
			return array(
				'title_tag'        => $this->ownOrFallback( $context->foreign->title, $context->postTitle ),
				'meta_description' => $this->ownOrFallback( $context->foreign->description, $context->excerpt ),
				'canonical'        => $this->ownOrFallback( $context->foreign->canonical, $context->permalink ),
			);
		}

		return array(
			'title_tag'        => $context->postTitle,
			'meta_description' => $context->excerpt,
			'canonical'        => $context->permalink,
		);
	}

	private function ownOrFallback( string $preferred, string $fallback ): string {
		return '' === trim( $preferred ) ? $fallback : trim( $preferred );
	}
}
