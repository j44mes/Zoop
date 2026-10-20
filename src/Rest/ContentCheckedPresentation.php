<?php
/**
 * Presentation for a content check that is not yet a definitive on-page score.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Rest;

use SEOStudio\Scoring\OnPage\CheckPresentation;

/**
 * Adjusts the stored headline after scoring. It does not change points or engines.
 */
final class ContentCheckedPresentation {
	/**
	 * Withhold on-page rating colours until the result is definitive.
	 *
	 * A current content-mode row explains that a published-page check is still required.
	 *
	 * @param array<string, mixed> $presentation Score presentation.
	 * @param array<string, mixed> $row          Page row.
	 * @param bool                 $scored       Whether an on-page score was stored for this check.
	 * @return array<string, mixed>
	 */
	public static function apply( array $presentation, array $row, bool $scored = false ): array {
		$ready = ! empty( $presentation['definitive'] );
		if ( ! $ready ) {
			$presentation['content_rating'] = '';
		}

		$state   = (string) ( $row['onpage_state'] ?? 'not_analysed' );
		$mode    = (string) ( $row['render_mode'] ?? '' );
		$checked = 'current' === $state || $scored;
		if ( $ready || 'content' !== $mode || 'needs_reanalysis' === $state || ! $checked ) {
			return $presentation;
		}

		$presentation['headline'] = __( 'Content checked. Needs a published-page check to score.', 'seo-studio' );
		$missing                  = isset( $presentation['missing'] ) && is_array( $presentation['missing'] ) ? $presentation['missing'] : array();
		$presentation['missing']  = self::withPublishedCheck( $missing );

		return $presentation;
	}

	/**
	 * Keep an existing published-page action, or add the standard one.
	 *
	 * @param array<int, mixed> $missing Missing-evidence items.
	 * @return array<int, array<string, mixed>>
	 */
	private static function withPublishedCheck( array $missing ): array {
		$items = array();
		$found = false;
		foreach ( $missing as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$items[] = $item;
			if ( 'check_published' === (string) ( $item['action'] ?? '' ) ) {
				$found = true;
			}
		}
		if ( $found ) {
			return $items;
		}

		$items[] = array(
			'id'           => 'published',
			'label'        => __( 'The published page has not been checked.', 'seo-studio' ),
			'action'       => 'check_published',
			'action_label' => CheckPresentation::actionLabel( 'check_published' ),
		);

		return $items;
	}
}
