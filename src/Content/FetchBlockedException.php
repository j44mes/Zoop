<?php
/**
 * Thrown when a full-page fetch is refused.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Content;

use RuntimeException;

/**
 * The URL failed the same-host allow-list or the response was unusable.
 */
final class FetchBlockedException extends RuntimeException {
	public function __construct( string $message, public readonly string $reason = 'blocked' ) {
		parent::__construct( $message );
	}
}
