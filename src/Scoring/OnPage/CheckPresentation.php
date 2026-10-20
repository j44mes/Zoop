<?php
/**
 * User-facing state for one stored assessment.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Scoring\OnPage;

/**
 * Maps a stored assessment onto the five readiness states.
 */
final class CheckPresentation {
	/**
	 * One check in the five user-facing states.
	 *
	 * @param array<string, mixed> $assessment Stored assessment.
	 * @return array{id: string, state: string, label: string, reason: string, action: string, action_label: string}
	 */
	public static function fromAssessment( array $assessment, bool $current ): array {
		$id     = (string) ( $assessment['id'] ?? '' );
		$status = (string) ( $assessment['status'] ?? '' );
		$reason = (string) ( $assessment['explanation'] ?? '' );
		if ( ! $current ) {
			return self::row( $id, 'out_of_date', __( 'Out of date', 'seo-studio' ), $reason, 'check_published' );
		}
		if ( 'pass' === $status ) {
			return self::row( $id, 'passed', __( 'Passed', 'seo-studio' ), $reason, 'view_evidence' );
		}
		if ( 'partial' === $status || 'fail' === $status ) {
			return self::row( $id, 'needs_work', __( 'Needs work', 'seo-studio' ), $reason, 'view_fix' );
		}
		if ( 'not_applicable' === $status ) {
			return self::row( $id, 'not_applicable', __( "Doesn't apply", 'seo-studio' ), $reason, 'view_explanation' );
		}

		$action = str_contains( strtolower( $reason ), 'keyphrase' ) ? 'add_keyphrase' : 'check_published';
		return self::row( $id, 'not_checked', __( 'Not checked', 'seo-studio' ), $reason, $action );
	}

	/**
	 * Assemble one presented check.
	 *
	 * @return array{id: string, state: string, label: string, reason: string, action: string, action_label: string}
	 */
	private static function row( string $id, string $state, string $label, string $reason, string $action ): array {
		return array(
			'id'           => $id,
			'state'        => $state,
			'label'        => $label,
			'reason'       => $reason,
			'action'       => $action,
			'action_label' => self::actionLabel( $action ),
		);
	}

	public static function actionLabel( string $action ): string {
		$labels = array(
			'view_evidence'    => __( 'View evidence', 'seo-studio' ),
			'view_fix'         => __( 'View safe fix', 'seo-studio' ),
			'check_published'  => __( 'Check published page', 'seo-studio' ),
			'add_keyphrase'    => __( 'Add keyphrase', 'seo-studio' ),
			'reanalyse'        => __( 'Re-analyse', 'seo-studio' ),
			'view_explanation' => __( 'View explanation', 'seo-studio' ),
			'refresh_content'  => __( 'Refresh content analysis', 'seo-studio' ),
		);

		return $labels[ $action ] ?? $action;
	}
}
