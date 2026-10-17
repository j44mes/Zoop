<?php
/**
 * Reads the active SEO plugin and renders the head fields it would print.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Integrations;

use SEOStudio\Content\ForeignHead;
use SEOStudio\Content\TemplateValues;

/**
 * WordPress adapter around PluginHead. Unit tests call PluginHead directly.
 */
final class ActiveHead {
	public function __construct(
		private readonly Detector $detector = new Detector(),
		private readonly PluginHead $heads = new PluginHead()
	) {}

	public function forPost( int $postId, TemplateValues $values, string $permalink ): ?ForeignHead {
		$plugin = $this->detector->first();
		if ( null === $plugin ) {
			return null;
		}

		$stored = $this->stored( $plugin['slug'], $postId );
		if ( 'yoast' === $plugin['slug'] && '' !== $stored['separator'] ) {
			$values = new TemplateValues(
				$values->title,
				$values->siteName,
				$stored['separator'],
				$values->excerpt,
				$values->category,
				$values->tagline
			);
		}

		return $this->heads->render( $plugin['slug'], $stored, $values, $permalink );
	}

	/**
	 * Stored fields and default templates for one plugin.
	 *
	 * @return array{title: string, description: string, canonical: string, title_template: string, description_template: string, append_site_name: bool, separator: string}
	 */
	private function stored( string $slug, int $postId ): array {
		$empty = array(
			'title'                => '',
			'description'          => '',
			'canonical'            => '',
			'title_template'       => '',
			'description_template' => '',
			'append_site_name'     => false,
			'separator'            => '',
		);

		return match ( $slug ) {
			'yoast' => $this->yoast( $postId, $empty ),
			'rank_math' => $this->rankMath( $postId, $empty ),
			'seopress' => $this->seopress( $postId, $empty ),
			'aioseo' => $this->aioseo( $postId, $empty ),
			'tsf' => $this->tsf( $postId, $empty ),
			default => $empty,
		};
	}

	/**
	 * Yoast stored title, description, canonical, and post templates.
	 *
	 * @param array{title: string, description: string, canonical: string, title_template: string, description_template: string, append_site_name: bool, separator: string} $row
	 * @return array{title: string, description: string, canonical: string, title_template: string, description_template: string, append_site_name: bool, separator: string}
	 */
	private function yoast( int $postId, array $row ): array {
		$options                     = $this->optionArray( 'wpseo_titles' );
		$row['title']                = $this->metaString( $postId, '_yoast_wpseo_title' );
		$row['description']          = $this->metaString( $postId, '_yoast_wpseo_metadesc' );
		$row['canonical']            = $this->metaString( $postId, '_yoast_wpseo_canonical' );
		$row['title_template']       = isset( $options[ 'title-' . get_post_type( $postId ) ] ) ? (string) $options[ 'title-' . get_post_type( $postId ) ] : '';
		$row['description_template'] = isset( $options[ 'metadesc-' . get_post_type( $postId ) ] ) ? (string) $options[ 'metadesc-' . get_post_type( $postId ) ] : '';
		$row['separator']            = $this->yoastSeparator( isset( $options['separator'] ) ? (string) $options['separator'] : '' );
		return $row;
	}

	/**
	 * Rank Math stored fields and post-type templates.
	 *
	 * @param array{title: string, description: string, canonical: string, title_template: string, description_template: string, append_site_name: bool, separator: string} $row
	 * @return array{title: string, description: string, canonical: string, title_template: string, description_template: string, append_site_name: bool, separator: string}
	 */
	private function rankMath( int $postId, array $row ): array {
		$options                     = $this->optionArray( 'rank-math-options-titles' );
		$type                        = get_post_type( $postId );
		$type                        = is_string( $type ) && '' !== $type ? $type : 'post';
		$row['title']                = $this->metaString( $postId, 'rank_math_title' );
		$row['description']          = $this->metaString( $postId, 'rank_math_description' );
		$row['canonical']            = $this->metaString( $postId, 'rank_math_canonical_url' );
		$title_key                   = 'pt_' . $type . '_title';
		$desc_key                    = 'pt_' . $type . '_description';
		$row['title_template']       = isset( $options[ $title_key ] ) ? (string) $options[ $title_key ] : '';
		$row['description_template'] = isset( $options[ $desc_key ] ) ? (string) $options[ $desc_key ] : '';
		return $row;
	}

	/**
	 * SEOPress stored fields and single-post templates.
	 *
	 * @param array{title: string, description: string, canonical: string, title_template: string, description_template: string, append_site_name: bool, separator: string} $row
	 * @return array{title: string, description: string, canonical: string, title_template: string, description_template: string, append_site_name: bool, separator: string}
	 */
	private function seopress( int $postId, array $row ): array {
		$options                     = $this->optionArray( 'seopress_titles_option_name' );
		$templates                   = $options['seopress_titles_single_titles'][ get_post_type( $postId ) ] ?? array();
		$templates                   = is_array( $templates ) ? $templates : array();
		$row['title']                = $this->metaString( $postId, '_seopress_titles_title' );
		$row['description']          = $this->metaString( $postId, '_seopress_titles_desc' );
		$row['canonical']            = $this->metaString( $postId, '_seopress_robots_canonical' );
		$row['title_template']       = isset( $templates['title'] ) ? (string) $templates['title'] : '';
		$row['description_template'] = isset( $templates['description'] ) ? (string) $templates['description'] : '';
		return $row;
	}

	/**
	 * All in One SEO row for this post, when that table exists.
	 *
	 * @param array{title: string, description: string, canonical: string, title_template: string, description_template: string, append_site_name: bool, separator: string} $row
	 * @return array{title: string, description: string, canonical: string, title_template: string, description_template: string, append_site_name: bool, separator: string}
	 */
	private function aioseo( int $postId, array $row ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'aioseo_posts';
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $found !== $table ) {
			return $row;
		}

		$record = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT title, description, canonical_url FROM {$table} WHERE post_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal.
				$postId
			),
			ARRAY_A
		);
		if ( ! is_array( $record ) ) {
			return $row;
		}

		$row['title']       = isset( $record['title'] ) ? (string) $record['title'] : '';
		$row['description'] = isset( $record['description'] ) ? (string) $record['description'] : '';
		$row['canonical']   = isset( $record['canonical_url'] ) ? (string) $record['canonical_url'] : '';
		return $row;
	}

	/**
	 * The SEO Framework stored fields and site-name addition flag.
	 *
	 * @param array{title: string, description: string, canonical: string, title_template: string, description_template: string, append_site_name: bool, separator: string} $row
	 * @return array{title: string, description: string, canonical: string, title_template: string, description_template: string, append_site_name: bool, separator: string}
	 */
	private function tsf( int $postId, array $row ): array {
		$options                 = $this->optionArray( 'autodescription-site-settings' );
		$row['title']            = $this->metaString( $postId, '_genesis_title' );
		$row['description']      = $this->metaString( $postId, '_genesis_description' );
		$row['canonical']        = $this->metaString( $postId, '_genesis_canonical_uri' );
		$row['append_site_name'] = empty( $options['title_rem_additions'] );
		return $row;
	}

	/**
	 * Option value as an array.
	 *
	 * @return array<string, mixed>
	 */
	private function optionArray( string $name ): array {
		$option = get_option( $name, array() );
		return is_array( $option ) ? $option : array();
	}

	private function metaString( int $postId, string $key ): string {
		$value = get_post_meta( $postId, $key, true );
		return is_string( $value ) ? $value : '';
	}

	private function yoastSeparator( string $name ): string {
		$symbols = array(
			'sc-dash'   => '-',
			'sc-ndash'  => '–',
			'sc-mdash'  => '—',
			'sc-colon'  => ':',
			'sc-middot' => '·',
			'sc-bull'   => '•',
			'sc-star'   => '*',
			'sc-pipe'   => '|',
		);

		return $symbols[ $name ] ?? '';
	}
}
