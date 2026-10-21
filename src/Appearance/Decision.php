<?php
/**
 * Decisions for a title and description proposal.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Appearance;

/**
 * Keeps a proposal apart from the published title and description.
 */
final class Decision {
	public const TITLE_MAX = 300;

	public const DESCRIPTION_MAX = 500;

	/**
	 * Validate the text a person wants to keep as a proposal.
	 *
	 * @return array{ok: true, title: string, description: string}|array{ok: false, field: string, message: string}
	 */
	public function proposal( string $title, string $description ): array {
		$title       = trim( $title );
		$description = trim( $description );
		if ( mb_strlen( $title ) > self::TITLE_MAX ) {
			return array(
				'ok'      => false,
				'field'   => 'title',
				'message' => __( 'The proposed title is too long.', 'seo-studio' ),
			);
		}
		if ( mb_strlen( $description ) > self::DESCRIPTION_MAX ) {
			return array(
				'ok'      => false,
				'field'   => 'description',
				'message' => __( 'The proposed description is too long.', 'seo-studio' ),
			);
		}

		return array(
			'ok'          => true,
			'title'       => $title,
			'description' => $description,
		);
	}

	/**
	 * Apply is available only when Zoop prints the tags.
	 */
	public function canApply( bool $publishing ): bool {
		return $publishing;
	}

	/**
	 * Copy a confirmed proposal onto the published Zoop fields.
	 *
	 * @return array{status: string, title: string, description: string, previous_title: string, previous_description: string}
	 */
	public function apply( bool $publishing, bool $confirmed, string $current_title, string $current_description, string $proposal_title, string $proposal_description, string $seen_title, string $seen_description ): array {
		if ( ! $publishing ) {
			return $this->applyResult( 'unsupported', $current_title, $current_description, $current_title, $current_description );
		}
		if ( ! $confirmed ) {
			return $this->applyResult( 'unconfirmed', $current_title, $current_description, $current_title, $current_description );
		}
		$title_matches       = $this->sameStored( $seen_title, $current_title );
		$description_matches = $this->sameStored( $seen_description, $current_description );
		if ( ! $title_matches || ! $description_matches ) {
			return $this->applyResult( 'stale', $current_title, $current_description, $current_title, $current_description );
		}

		return $this->applyResult( 'applied', $proposal_title, $proposal_description, $current_title, $current_description );
	}

	/**
	 * Put the stored previous Zoop values back.
	 *
	 * @return array{title: string, description: string}
	 */
	public function restore( string $previous_title, string $previous_description ): array {
		return array(
			'title'       => $previous_title,
			'description' => $previous_description,
		);
	}

	/**
	 * Whether the live HTML shows the title and description Zoop intended to print.
	 */
	public function htmlMatches( string $expected_title, string $observed_title, string $expected_description, string $observed_description ): bool {
		return ( $this->fold( $expected_title ) === $this->fold( $observed_title ) )
			&& ( $this->fold( $expected_description ) === $this->fold( $observed_description ) );
	}

	/**
	 * A saved write and a live HTML check are separate results.
	 *
	 * @return array{status: string, html_matches: bool, basis: string, note: string}
	 */
	public function verification( bool $fetched, bool $matches ): array {
		if ( ! $fetched ) {
			return array(
				'status'       => 'not_verified',
				'html_matches' => false,
				'basis'        => 'not_observed',
				'note'         => __( 'The live HTML was not read. Values resolved from saved settings are not a verified publication.', 'seo-studio' ),
			);
		}
		if ( ! $matches ) {
			return array(
				'status'       => 'not_verified',
				'html_matches' => false,
				'basis'        => 'live_html',
				'note'         => __( 'The published HTML was observed and does not match the title and description Zoop saved. A cache can delay that check.', 'seo-studio' ),
			);
		}

		return array(
			'status'       => 'verified',
			'html_matches' => true,
			'basis'        => 'live_html',
			'note'         => __( 'The published HTML was observed and matches the title and description Zoop saved.', 'seo-studio' ),
		);
	}

	/**
	 * The confirmed text must still be the text stored now.
	 */
	public function sameStored( string $seen, string $current ): bool {
		return $seen === $current;
	}

	/**
	 * One apply result, including the values that were current before the change.
	 *
	 * @return array{status: string, title: string, description: string, previous_title: string, previous_description: string}
	 */
	private function applyResult( string $status, string $title, string $description, string $previous_title, string $previous_description ): array {
		return array(
			'status'               => $status,
			'title'                => $title,
			'description'          => $description,
			'previous_title'       => $previous_title,
			'previous_description' => $previous_description,
		);
	}

	private function fold( string $value ): string {
		$folded = preg_replace( '/\s+/u', ' ', trim( $value ) );
		return is_string( $folded ) ? $folded : trim( $value );
	}
}
