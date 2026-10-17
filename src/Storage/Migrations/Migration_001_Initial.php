<?php
/**
 * Initial schema.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Storage\Migrations;

use SEOStudio\Storage\Tables;

/**
 * Creates the Phase 1 tables.
 */
final class Migration_001_Initial {
	public function version(): int {
		return 1;
	}

	public function up(): void {
		Tables::install();
	}
}
