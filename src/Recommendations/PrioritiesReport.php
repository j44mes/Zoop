<?php
/**
 * Site-wide priority list.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Recommendations;

use SEOStudio\Architecture\SiteSurvey;
use SEOStudio\Search\SearchRepository;
use SEOStudio\Topics\OverlapReport;

/**
 * Reads the stored reports and orders them. It does not edit pages.
 */
final class PrioritiesReport {
	public function __construct( private readonly Priorities $priorities = new Priorities() ) {}

	/**
	 * Ordered review items for an administrator.
	 *
	 * @return array{items: list<array<string, mixed>>, notes: string[], limited: bool}
	 */
	public function report(): array {
		return $this->priorities->rank(
			( new SiteSurvey() )->report(),
			( new OverlapReport() )->report(),
			( new SearchRepository() )->allRows()
		);
	}
}
