<?php
/**
 * Keyphrase match against one text field.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Scoring\OnPage;

/**
 * Result of comparing a keyphrase with a single field.
 */
final class FieldMatch {
	/**
	 * __construct.
	 *
	 * @param string[] $contentWords
	 */
	public function __construct(
		public readonly array $contentWords,
		public readonly bool $entire,
		public readonly bool $partial,
		public readonly float $coverage,
		public readonly ?int $position,
		public readonly int $wordCount,
		public readonly int $entireCount
	) {}
}
