<?php
/**
 * Title, description, and canonical another plugin would print.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Content;

/**
 * Rendered head fields from the active SEO plugin.
 */
final class ForeignHead {
	public function __construct(
		public readonly string $title,
		public readonly string $description,
		public readonly string $canonical
	) {}
}
