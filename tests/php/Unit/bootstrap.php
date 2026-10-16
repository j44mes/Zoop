<?php
/**
 * Unit-test stubs for WordPress functions the scorer is allowed to call.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

if ( ! function_exists( '__' ) ) {
	/**
	 * @param string $text
	 * @param string $domain
	 */
	function __( $text, $domain = 'default' ) {
		unset( $domain );
		return $text;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * @param string $hook
	 * @param mixed  $value
	 * @return mixed
	 */
	function apply_filters( $hook, $value ) {
		unset( $hook );
		return $value;
	}
}

require_once dirname( __DIR__, 3 ) . '/vendor/autoload.php';
