<?php
/**
 * Validates the per-post SEO payload.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Rest;

use SEOStudio\Content\Urls;

/**
 * Field contract for PUT /posts/{id}/seo.
 */
final class SeoValidator {
	public const FIELDS = array(
		'focus_keyphrase'    => '_seostudio_focus_keyphrase',
		'related_keyphrases' => '_seostudio_related_keyphrases',
		'synonyms'           => '_seostudio_synonyms',
		'title'              => '_seostudio_title',
		'description'        => '_seostudio_description',
		'canonical'          => '_seostudio_canonical',
		'robots'             => '_seostudio_robots',
		'og_title'           => '_seostudio_og_title',
		'og_description'     => '_seostudio_og_description',
		'og_image'           => '_seostudio_og_image',
		'cornerstone'        => '_seostudio_cornerstone',
		'content_selector'   => '_seostudio_content_selector',
		'video_url'          => '_seostudio_video_url',
		'is_news'            => '_seostudio_is_news',
	);

	/**
	 * Validate a partial payload.
	 *
	 * @param array<string, mixed> $params
	 * @return array{field: string, message: string}|null
	 */
	public function validate( array $params, string $homeUrl ): ?array {
		foreach ( self::FIELDS as $name => $meta_key ) {
			unset( $meta_key );
			if ( ! array_key_exists( $name, $params ) ) {
				continue;
			}
			$error = $this->check( $name, $params[ $name ], $homeUrl );
			if ( null !== $error ) {
				return $error;
			}
		}

		return null;
	}

	/**
	 * Check one field.
	 *
	 * @return array{field: string, message: string}|null
	 */
	private function check( string $field, mixed $value, string $homeUrl ): ?array {
		return match ( $field ) {
			'focus_keyphrase' => $this->boundedString( $field, $value, 255, true ),
			'title', 'og_title' => $this->boundedString( $field, $value, 300, true ),
			'description', 'og_description' => $this->boundedString( $field, $value, 500, true ),
			'content_selector' => $this->selector( $value ),
			'canonical' => $this->canonical( $value, $homeUrl ),
			'related_keyphrases' => $this->related( $value ),
			'synonyms' => $this->synonyms( $value ),
			'robots' => $this->robots( $value ),
			'og_image' => $this->image( $value ),
			'cornerstone' => is_bool( $value ) ? null : $this->error( $field, 'Cornerstone must be true or false.' ),
			'video_url' => $this->videoUrl( $value ),
			'is_news' => is_bool( $value ) ? null : $this->error( $field, 'News must be true or false.' ),
			default => null,
		};
	}

	/**
	 * Require a string within the field's maximum length.
	 *
	 * @return array{field: string, message: string}|null
	 */
	private function boundedString( string $field, mixed $value, int $max, bool $allowEmpty ): ?array {
		if ( ! is_string( $value ) ) {
			return $this->error( $field, 'This field must be a string.' );
		}
		$length = mb_strlen( trim( $value ) );
		if ( ! $allowEmpty && $length < 1 ) {
			return $this->error( $field, 'This field is required.' );
		}
		if ( $length > $max ) {
			return $this->error( $field, 'This field is too long.' );
		}

		return null;
	}

	/**
	 * A video URL may live on another host. Only http and https are accepted.
	 *
	 * @return array{field: string, message: string}|null
	 */
	private function videoUrl( mixed $value ): ?array {
		$error = $this->boundedString( 'video_url', $value, 2048, true );
		if ( null !== $error || ! is_string( $value ) || '' === trim( $value ) ) {
			return $error;
		}

		$parts  = wp_parse_url( $value );
		$scheme = is_array( $parts ) ? strtolower( (string) ( $parts['scheme'] ?? '' ) ) : '';
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return $this->error( 'video_url', 'The video URL is not valid.' );
		}

		return null;
	}

	/**
	 * Require a CSS selector without raw HTML.
	 *
	 * @return array{field: string, message: string}|null
	 */
	private function selector( mixed $value ): ?array {
		$error = $this->boundedString( 'content_selector', $value, 255, true );
		if ( null !== $error ) {
			return $error;
		}
		if ( is_string( $value ) && str_contains( $value, '<' ) ) {
			return $this->error( 'content_selector', 'The selector is not valid.' );
		}

		return null;
	}

	/**
	 * Require an empty canonical or one on this site's scheme and host.
	 *
	 * @return array{field: string, message: string}|null
	 */
	private function canonical( mixed $value, string $homeUrl ): ?array {
		if ( ! is_string( $value ) ) {
			return $this->error( 'canonical', 'Canonical must be a string.' );
		}
		if ( '' === trim( $value ) ) {
			return null;
		}

		$canonical = Urls::parts( trim( $value ) );
		$home      = Urls::parts( $homeUrl );
		if ( ! is_array( $canonical ) || ! is_array( $home ) || empty( $canonical['host'] ) || empty( $home['host'] ) ) {
			return $this->error( 'canonical', 'Canonical must use this site host.' );
		}

		$same_scheme = strtolower( (string) ( $canonical['scheme'] ?? '' ) ) === strtolower( (string) ( $home['scheme'] ?? '' ) );
		$same_host   = strtolower( (string) $canonical['host'] ) === strtolower( (string) $home['host'] );
		if ( ! $same_scheme || ! $same_host || isset( $canonical['user'] ) || isset( $canonical['pass'] ) ) {
			return $this->error( 'canonical', 'Canonical must use this site host.' );
		}

		return null;
	}

	/**
	 * Require at most four related keyphrases.
	 *
	 * @return array{field: string, message: string}|null
	 */
	private function related( mixed $value ): ?array {
		if ( ! is_array( $value ) || count( $value ) > 4 ) {
			return $this->error( 'related_keyphrases', 'Provide at most four related keyphrases.' );
		}

		foreach ( $value as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['keyphrase'] ) || ! is_string( $item['keyphrase'] ) ) {
				return $this->error( 'related_keyphrases', 'Each related keyphrase needs a string keyphrase.' );
			}
			if ( mb_strlen( trim( $item['keyphrase'] ) ) > 255 ) {
				return $this->error( 'related_keyphrases', 'A related keyphrase is too long.' );
			}
			$synonyms = $item['synonyms'] ?? array();
			if ( ! is_array( $synonyms ) ) {
				return $this->error( 'related_keyphrases', 'Related synonyms must be a list.' );
			}
			foreach ( $synonyms as $synonym ) {
				if ( ! is_string( $synonym ) || mb_strlen( $synonym ) > 255 ) {
					return $this->error( 'related_keyphrases', 'A related synonym is not valid.' );
				}
			}
		}

		return null;
	}

	/**
	 * Require at most ten synonym strings.
	 *
	 * @return array{field: string, message: string}|null
	 */
	private function synonyms( mixed $value ): ?array {
		if ( ! is_array( $value ) || count( $value ) > 10 ) {
			return $this->error( 'synonyms', 'Provide at most ten synonyms.' );
		}
		foreach ( $value as $synonym ) {
			if ( ! is_string( $synonym ) || mb_strlen( $synonym ) > 255 ) {
				return $this->error( 'synonyms', 'A synonym is not valid.' );
			}
		}

		return null;
	}

	/**
	 * Require the robots object shape.
	 *
	 * @return array{field: string, message: string}|null
	 */
	private function robots( mixed $value ): ?array {
		if ( ! is_array( $value ) ) {
			return $this->error( 'robots', 'Robots must be an object.' );
		}
		foreach ( array( 'index', 'follow' ) as $flag ) {
			if ( array_key_exists( $flag, $value ) && null !== $value[ $flag ] && ! is_bool( $value[ $flag ] ) ) {
				return $this->error( 'robots', 'Robot flags must be true, false, or null.' );
			}
		}
		if ( array_key_exists( 'max_snippet', $value ) && null !== $value['max_snippet'] && ! is_int( $value['max_snippet'] ) ) {
			return $this->error( 'robots', 'max_snippet must be an integer or null.' );
		}
		$preview = $value['max_image_preview'] ?? null;
		if ( null !== $preview && ! in_array( $preview, array( 'none', 'standard', 'large' ), true ) ) {
			return $this->error( 'robots', 'max_image_preview is not a supported value.' );
		}

		return null;
	}

	/**
	 * Require a non-negative attachment ID.
	 *
	 * @return array{field: string, message: string}|null
	 */
	private function image( mixed $value ): ?array {
		if ( ! is_int( $value ) || $value < 0 ) {
			return $this->error( 'og_image', 'The social image must be an attachment ID.' );
		}

		return null;
	}

	/**
	 * Validation error for one field.
	 *
	 * @return array{field: string, message: string}
	 */
	private function error( string $field, string $message ): array {
		return array(
			'field'   => $field,
			'message' => $message,
		);
	}
}
