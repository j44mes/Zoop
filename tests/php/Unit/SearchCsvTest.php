<?php
/**
 * Search Console CSV tests.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOStudio\Search\CsvImport;

/**
 * An export is evidence. It is not a connection to Search Console.
 */
final class SearchCsvTest extends TestCase {
	public function testPerformanceExportKeepsQueryPageAndIgnoresTheClickRate(): void {
		$csv    = "Query,Page,Clicks,Impressions,CTR,Position\n"
			. "\"fire door installation\",https://example.test/doors,12,\"1,000\",1.2%,4.5\n"
			. ",https://example.test/empty,1,10,10%,1\n";
		$parsed = ( new CsvImport() )->parse( $csv );

		$this->assertSame( '', $parsed['error'] );
		$this->assertCount( 1, $parsed['rows'] );
		$this->assertSame( 'fire door installation', $parsed['rows'][0]['query'] );
		$this->assertSame( 'https://example.test/doors', $parsed['rows'][0]['page'] );
		$this->assertSame( 12, $parsed['rows'][0]['clicks'] );
		$this->assertSame( 1000, $parsed['rows'][0]['impressions'] );
		$this->assertSame( 4.5, $parsed['rows'][0]['position'] );
	}

	public function testQueryOnlyExportIsAcceptedAndAMissingQueryColumnIsRejected(): void {
		$queries = ( new CsvImport() )->parse( "Top queries,Clicks,Impressions,CTR,Position\nhow to install,3,40,7.5%,8\n" );
		$invalid = ( new CsvImport() )->parse( "Page,Clicks\nhttps://example.test,1\n" );

		$this->assertSame( '', $queries['rows'][0]['page'] );
		$this->assertSame( 'how to install', $queries['rows'][0]['query'] );
		$this->assertStringContainsString( 'query, clicks, and impressions', $invalid['error'] );
	}
}
