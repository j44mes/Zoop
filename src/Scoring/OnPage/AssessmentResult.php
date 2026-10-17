<?php
/**
 * Outcome of one assessment.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Scoring\OnPage;

/**
 * Points, status, and the explanation shown for one check.
 */
final class AssessmentResult {
	/**
	 * __construct.
	 *
	 * @param array<string, mixed> $observed
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $category,
		public readonly int $max,
		public readonly int $earned,
		public readonly string $status,
		public readonly array $observed,
		public readonly string $explanation,
		public readonly string $recommendation,
		public readonly string $evidence
	) {}

	/**
	 * Build a result and derive its status from the points.
	 *
	 * @param array<string, mixed> $observed
	 */
	public static function score(
		Assessment $assessment,
		int $earned,
		array $observed,
		string $explanation,
		string $recommendation,
		string $evidence = '',
		?string $status = null
	): self {
		$earned = max( 0, min( $assessment->max(), $earned ) );
		if ( null === $status ) {
			if ( $earned === $assessment->max() ) {
				$status = 'pass';
			} elseif ( 0 === $earned ) {
				$status = 'fail';
			} else {
				$status = 'partial';
			}
		}

		return new self(
			$assessment->id(),
			$assessment->category(),
			$assessment->max(),
			$earned,
			$status,
			$observed,
			$explanation,
			$recommendation,
			$evidence
		);
	}

	/**
	 * To array.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'id'             => $this->id,
			'category'       => $this->category,
			'max'            => $this->max,
			'earned'         => $this->earned,
			'status'         => $this->status,
			'observed'       => $this->observed,
			'explanation'    => $this->explanation,
			'recommendation' => $this->recommendation,
			'evidence'       => $this->evidence,
		);
	}
}
