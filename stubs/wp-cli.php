<?php
/**
 * Minimal WP-CLI symbols so static analysis can see the command API.
 *
 * @package SEOStudio
 */

if ( ! class_exists( 'WP_CLI' ) ) {
	/**
	 * Stub of the WP-CLI command facade.
	 */
	class WP_CLI {
		/**
		 * @param string              $name
		 * @param callable|class-string $callable
		 * @param array<string,mixed> $args
		 */
		public static function add_command( $name, $callable, $args = array() ): void {}

		/**
		 * @param string $message
		 */
		public static function line( $message ): void {}

		/**
		 * @param string $message
		 */
		public static function log( $message ): void {}

		/**
		 * @param string $message
		 */
		public static function success( $message ): void {}

		/**
		 * @param string $message
		 */
		public static function warning( $message ): void {}

		/**
		 * @param string $message
		 * @return never
		 */
		public static function error( $message ) {
			exit( 1 );
		}
	}
}

if ( ! class_exists( 'WP_CLI_Command' ) ) {
	/**
	 * Stub base class for WP-CLI commands.
	 */
	abstract class WP_CLI_Command {}
}
