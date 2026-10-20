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

if ( ! function_exists( '_n' ) ) {
	/**
	 * @param string $single
	 * @param string $plural
	 * @param int    $number
	 * @param string $domain
	 */
	function _n( $single, $plural, $number, $domain = 'default' ) {
		unset( $domain );
		return 1 === (int) $number ? $single : $plural;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * @param string $hook
	 * @param mixed  $value
	 * @return mixed
	 */
	function apply_filters( $hook, $value ) {
		if ( 'seostudio/function_words' === $hook && array_key_exists( 'seostudio_function_words_filter', $GLOBALS ) ) {
			return $GLOBALS['seostudio_function_words_filter'];
		}
		unset( $hook );
		return $value;
	}
}

require_once __DIR__ . '/WpHtmlTagProcessorStub.php';
require_once __DIR__ . '/WpStubs.php';

require_once dirname( __DIR__, 3 ) . '/vendor/autoload.php';
