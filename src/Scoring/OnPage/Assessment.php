<?php
/**
 * One on-page assessment.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Scoring\OnPage;

use SEOStudio\Content\PageModel;

/**
 * A single deterministic check in the 100-point rubric.
 */
interface Assessment {
	public function id(): string;

	public function category(): string;

	public function max(): int;

	public function evaluate( PageModel $page, string $keyphrase, Normaliser $normaliser ): AssessmentResult;
}
