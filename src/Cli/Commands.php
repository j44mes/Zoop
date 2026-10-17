<?php
/**
 * WP-CLI commands.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Cli;

use SEOStudio\Content\Renderer;
use SEOStudio\Core\Upgrader;
use SEOStudio\Scoring\OnPage\AssessmentResult;
use SEOStudio\Scoring\OnPage\Scorer;
use SEOStudio\Storage\KeyphrasesIndex;
use SEOStudio\Storage\PagesRepository;
use SEOStudio\Storage\ScoresRepository;

/**
 * `wp seostudio analyse`, `detect`, and `openapi`.
 */
final class Commands {
	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command( 'seostudio analyse', array( self::class, 'analyse' ) );
		\WP_CLI::add_command( 'seostudio detect', array( self::class, 'detect' ) );
		\WP_CLI::add_command( 'seostudio openapi', array( self::class, 'openapi' ) );
	}

	/**
	 * Score one post and print the breakdown.
	 *
	 * @param string[] $args
	 */
	public static function analyse( array $args ): void {
		$post_id   = isset( $args[0] ) ? (int) $args[0] : 0;
		$keyphrase = isset( $args[1] ) ? (string) $args[1] : (string) get_post_meta( $post_id, '_seostudio_focus_keyphrase', true );
		if ( $post_id <= 0 || '' === $keyphrase ) {
			\WP_CLI::error( 'Usage: wp seostudio analyse <post_id> <keyphrase>' );
		}

		Upgrader::maybeUpgrade();
		$model   = ( new Renderer() )->renderPost( $post_id );
		$score   = ( new Scorer() )->score( $model, $keyphrase );
		$page_id = ( new PagesRepository() )->storeModel( $post_id, $model, $model->inputHash() );
		( new ScoresRepository() )->insert( $page_id, $keyphrase, $score, SEOSTUDIO_VERSION );
		( new KeyphrasesIndex() )->replacePrimary( $post_id, $keyphrase );
		\WP_CLI::line( sprintf( 'On-page score: %d/%d', $score->earned, $score->denominator ) );
		\WP_CLI::line( sprintf( 'Page id: %d', $page_id ) );
		foreach ( $score->categories as $name => $category ) {
			\WP_CLI::line( sprintf( '%s: %d/%d', $name, $category['earned'], $category['max'] ) );
		}
		foreach ( $score->assessments as $result ) {
			if ( ! $result instanceof AssessmentResult ) {
				continue;
			}
			\WP_CLI::line( sprintf( '- %s: %d/%d %s', $result->id, $result->earned, $result->max, $result->status ) );
		}
		\WP_CLI::success( 'Analysis complete.' );
	}

	public static function detect(): void {
		$plugins = array(
			'yoast'     => defined( 'WPSEO_VERSION' ) ? (string) WPSEO_VERSION : '',
			'rank_math' => defined( 'RANK_MATH_VERSION' ) ? (string) RANK_MATH_VERSION : '',
			'seopress'  => defined( 'SEOPRESS_VERSION' ) ? (string) SEOPRESS_VERSION : '',
			'aioseo'    => defined( 'AIOSEO_VERSION' ) ? (string) AIOSEO_VERSION : '',
			'tsf'       => defined( 'THE_SEO_FRAMEWORK_VERSION' ) ? (string) THE_SEO_FRAMEWORK_VERSION : '',
		);

		foreach ( $plugins as $slug => $version ) {
			\WP_CLI::line( $slug . ': ' . ( '' === $version ? 'inactive' : $version ) );
		}
	}

	public static function openapi(): void {
		$routes = array(
			'GET /seostudio/v1/pages',
			'GET /seostudio/v1/pages/{page_id}',
			'POST /seostudio/v1/analyse/{object_type}/{object_id}',
			'POST /seostudio/v1/pages/{page_id}/analyse',
			'GET /seostudio/v1/posts/{post_id}/seo',
			'PUT /seostudio/v1/posts/{post_id}/seo',
			'GET /seostudio/v1/keyphrases/usage',
		);
		\WP_CLI::line( (string) wp_json_encode( $routes, JSON_PRETTY_PRINT ) );
	}
}
