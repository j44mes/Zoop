<?php
/**
 * On-page score for one keyphrase.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Scoring\OnPage;

/**
 * Totals plus the assessment breakdown.
 */
final class Score {
	/**
	 * __construct.
	 *
	 * @param AssessmentResult[]                              $assessments
	 * @param array<string, array{earned: int, max: int}>    $categories
	 * @param array<int, array{id: string, message: string}> $advisories
	 */
	public function __construct(
		public readonly int $earned,
		public readonly int $denominator,
		public readonly array $assessments,
		public readonly array $categories,
		public readonly array $advisories
	) {}

	/**
	 * To array.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'earned'           => $this->earned,
			'denominator'      => $this->denominator,
			'evidence_version' => 2,
			'categories'       => $this->categories,
			'assessments'      => array_map(
				static function ( AssessmentResult $result ): array {
					return $result->toArray();
				},
				$this->assessments
			),
			'advisories'       => $this->advisories,
		);
	}
}
