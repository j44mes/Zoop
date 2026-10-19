import { __ } from '@wordpress/i18n';

/**
 * Visible name for what Zoop actually observed.
 *
 * @param observation Observation id.
 */
export function overlapObservation( observation: string ): string {
	switch ( observation ) {
		case 'phrase':
			return __( 'Confirmed phrase overlap', 'seo-studio' );
		case 'topic':
			return __( 'Topic overlap', 'seo-studio' );
		case 'title':
			return __( 'Shared title words', 'seo-studio' );
		default:
			return __( 'No overlap established', 'seo-studio' );
	}
}

/**
 * Visible name for the decision, kept apart from the observation.
 *
 * @param conclusion Conclusion id.
 */
export function overlapConclusion( conclusion: string ): string {
	switch ( conclusion ) {
		case 'investigation':
			return __( 'Needs investigation', 'seo-studio' );
		default:
			return __( 'Insufficient evidence', 'seo-studio' );
	}
}

/**
 * Visible confidence label. It applies only to the claim beside it.
 *
 * @param confidence Confidence id.
 */
export function overlapConfidence( confidence: string ): string {
	switch ( confidence ) {
		case 'high':
			return __( 'High', 'seo-studio' );
		case 'medium':
			return __( 'Medium', 'seo-studio' );
		case 'low':
			return __( 'Low', 'seo-studio' );
		default:
			return __( 'Insufficient evidence', 'seo-studio' );
	}
}

/**
 * What one evidence row is allowed to establish.
 *
 * @param establishes Claim id.
 */
export function evidenceEstablishes( establishes: string ): string {
	switch ( establishes ) {
		case 'confirmed_overlap':
			return __( 'Confirmed overlap', 'seo-studio' );
		case 'same_observation':
			return __( 'Same observation', 'seo-studio' );
		case 'supports_investigation':
			return __( 'Supports investigation', 'seo-studio' );
		default:
			return __( 'Not established', 'seo-studio' );
	}
}

/**
 * Short name for an evidence signal.
 *
 * @param signal Signal id.
 */
export function evidenceSignal( signal: string ): string {
	switch ( signal ) {
		case 'target_phrase':
			return __( 'Target phrase', 'seo-studio' );
		case 'primary_topic':
			return __( 'Primary topic', 'seo-studio' );
		case 'search_intent':
			return __( 'Search intent', 'seo-studio' );
		case 'title_words':
			return __( 'Title words', 'seo-studio' );
		case 'cannibalisation':
			return __( 'Cannibalisation', 'seo-studio' );
		default:
			return signal;
	}
}
