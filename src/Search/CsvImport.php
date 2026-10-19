<?php
/**
 * Search Console CSV parser.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Search;

/**
 * Reads an exported performance file. It does not connect to Search Console.
 */
final class CsvImport {
	public const LIMIT = 5000;

	/**
	 * Parse query, page, clicks, impressions, and position columns.
	 *
	 * @return array{rows: list<array{query: string, page: string, clicks: int, impressions: int, position: float|null}>, skipped: int, error: string}
	 */
	public function parse( string $csv ): array {
		$csv   = preg_replace( '/^\xEF\xBB\xBF/', '', $csv ) ?? $csv;
		$lines = preg_split( "/\r\n|\n|\r/", $csv );
		if ( ! is_array( $lines ) ) {
			return $this->failure( __( 'The file could not be read.', 'seo-studio' ) );
		}
		$lines = array_values( array_filter( $lines, static fn( string $line ): bool => '' !== trim( $line ) ) );
		if ( array() === $lines ) {
			return $this->failure( __( 'The file has no rows.', 'seo-studio' ) );
		}

		$header = str_getcsv( (string) array_shift( $lines ) );
		$map    = $this->map( $header );
		foreach ( array( 'query', 'clicks', 'impressions' ) as $required ) {
			if ( ! isset( $map[ $required ] ) ) {
				return $this->failure( __( 'The file needs query, clicks, and impressions columns.', 'seo-studio' ) );
			}
		}

		$rows    = array();
		$skipped = 0;
		foreach ( $lines as $line ) {
			if ( count( $rows ) >= self::LIMIT ) {
				++$skipped;
				continue;
			}
			$cells = str_getcsv( $line );
			$query = trim( (string) ( $cells[ $map['query'] ] ?? '' ) );
			if ( '' === $query ) {
				continue;
			}
			$rows[] = array(
				'query'       => mb_substr( $query, 0, 500 ),
				'page'        => isset( $map['page'] ) ? trim( (string) ( $cells[ $map['page'] ] ?? '' ) ) : '',
				'clicks'      => $this->integer( (string) ( $cells[ $map['clicks'] ] ?? '0' ) ),
				'impressions' => $this->integer( (string) ( $cells[ $map['impressions'] ] ?? '0' ) ),
				'position'    => isset( $map['position'] ) ? $this->decimal( (string) ( $cells[ $map['position'] ] ?? '' ) ) : null,
			);
		}
		if ( array() === $rows ) {
			return $this->failure( __( 'The file has no query rows.', 'seo-studio' ) );
		}

		return array(
			'rows'    => $rows,
			'skipped' => $skipped,
			'error'   => '',
		);
	}

	/**
	 * Match export headings to the fields Zoop stores.
	 *
	 * @param string[] $header
	 * @return array<string, int>
	 */
	private function map( array $header ): array {
		$aliases = array(
			'query'       => array( 'query', 'top queries', 'queries' ),
			'page'        => array( 'page', 'top pages', 'landing page' ),
			'clicks'      => array( 'clicks' ),
			'impressions' => array( 'impressions' ),
			'position'    => array( 'position', 'average position' ),
		);
		$map     = array();
		foreach ( $header as $index => $label ) {
			$label = strtolower( trim( (string) $label ) );
			foreach ( $aliases as $name => $options ) {
				if ( in_array( $label, $options, true ) ) {
					$map[ $name ] = (int) $index;
				}
			}
		}
		return $map;
	}

	private function integer( string $value ): int {
		$digits = preg_replace( '/[^0-9-]/', '', $value );
		return (int) $digits;
	}

	private function decimal( string $value ): ?float {
		$clean = str_replace( array( '%', ',' ), '', trim( $value ) );
		if ( '' === $clean || ! is_numeric( $clean ) ) {
			return null;
		}
		return (float) $clean;
	}

	/**
	 * A file that cannot be used.
	 *
	 * @return array{rows: list<array{query: string, page: string, clicks: int, impressions: int, position: float|null}>, skipped: int, error: string}
	 */
	private function failure( string $error ): array {
		return array(
			'rows'    => array(),
			'skipped' => 0,
			'error'   => $error,
		);
	}
}
