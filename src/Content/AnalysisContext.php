<?php
/**
 * Inputs the metadata resolver needs for one post.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Content;

/**
 * Mode, SEO Studio fields, and the active plugin's rendered head.
 */
final class AnalysisContext {
	/**
	 * Metadata inputs, with authoritative homepage values when applicable.
	 *
	 * @param array{title_tag: string, meta_description: string, canonical: string}|null $homepage Resolved homepage settings.
	 */
	public function __construct(
		public readonly string $mode,
		public readonly bool $competitorActive,
		public readonly string $postTitle,
		public readonly string $excerpt,
		public readonly string $permalink,
		public readonly TemplateValues $values,
		public readonly string $seoTitle,
		public readonly string $seoDescription,
		public readonly string $seoCanonical,
		public readonly ?ForeignHead $foreign,
		public readonly ?array $homepage = null
	) {}

	public function publishesOwnMetadata(): bool {
		return 'owner' === $this->mode && ! $this->competitorActive;
	}
}
