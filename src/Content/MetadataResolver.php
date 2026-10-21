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

	/**
	 * Values the scorer may use, with the evidence for each one.
	 *
	 * Publication still uses resolve(). A theme title or a third-party excerpt is not calculated here.
	 *
	 * @return array{title_tag: string, meta_description: string, canonical: string, title_evidence: string, description_evidence: string}
	 */
	public function evidence( AnalysisContext $context ): array {
		$resolved = $this->resolve( $context );
		if ( $context->publishesOwnMetadata() && null !== $context->homepage ) {
			return $this->withEvidence( $context->homepage['title_tag'], $context->homepage['meta_description'], $resolved['canonical'] );
		}

		if ( $context->publishesOwnMetadata() ) {
			$title       = MetadataEvidence::calculated( $context->values->expand( $context->seoTitle ) );
			$description = MetadataEvidence::calculated( $context->values->expand( $context->seoDescription ) );
			if ( '' === trim( $context->seoTitle ) ) {
				$title = array( '', MetadataEvidence::NOT_OBSERVED );
			}
			if ( '' === trim( $context->seoDescription ) ) {
				$description = array( '', MetadataEvidence::NOT_OBSERVED );
			}

			return array(
				'title_tag'            => $title[0],
				'meta_description'     => $description[0],
				'canonical'            => $resolved['canonical'],
				'title_evidence'       => $title[1],
				'description_evidence' => $description[1],
			);
		}

		if ( $context->foreign instanceof ForeignHead ) {
			return $this->withEvidence( $context->foreign->title, $context->foreign->description, $resolved['canonical'] );
		}

		return array(
			'title_tag'            => '',
			'meta_description'     => '',
			'canonical'            => $resolved['canonical'],
			'title_evidence'       => MetadataEvidence::NOT_OBSERVED,
			'description_evidence' => MetadataEvidence::NOT_OBSERVED,
		);
	}

	/**
	 * Pair a title and description with calculated or not-observed evidence.
	 *
	 * @return array{title_tag: string, meta_description: string, canonical: string, title_evidence: string, description_evidence: string}
	 */
	private function withEvidence( string $title, string $description, string $canonical ): array {
		$title       = MetadataEvidence::calculated( $title );
		$description = MetadataEvidence::calculated( $description );

		return array(
			'title_tag'            => $title[0],
			'meta_description'     => $description[0],
			'canonical'            => $canonical,
			'title_evidence'       => $title[1],
			'description_evidence' => $description[1],
		);
	}

	private function ownOrFallback( string $preferred, string $fallback ): string {
		return '' === trim( $preferred ) ? $fallback : trim( $preferred );
	}
}
