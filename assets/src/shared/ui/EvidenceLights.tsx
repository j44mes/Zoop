import { __ } from '@wordpress/i18n';
import { TrafficLight } from './TrafficLight';

// SEO stays neutral until the on-page result is definitive.
// Readability can keep its own rating, labelled as a content-level check.
export function EvidenceLights( {
	verified,
	seoRating,
	readabilityRating,
	testIds = false,
}: {
	verified: boolean;
	seoRating: string | null;
	readabilityRating: string | null;
	testIds?: boolean;
} ) {
	if ( ! verified ) {
		return (
			<>
				<TrafficLight
					rating={ null }
					label={ __( 'SEO', 'seo-studio' ) }
					value={ __( 'Not verified', 'seo-studio' ) }
					testId={ testIds ? 'seo-light' : undefined }
				/>{ ' ' }
				<TrafficLight
					rating={ readabilityRating }
					label={ __( 'Readability', 'seo-studio' ) }
					detail={ __( 'Content check', 'seo-studio' ) }
					testId={ testIds ? 'readability-light' : undefined }
				/>
			</>
		);
	}

	return (
		<>
			<TrafficLight
				rating={ seoRating }
				label={ __( 'SEO', 'seo-studio' ) }
				testId={ testIds ? 'seo-light' : undefined }
			/>{ ' ' }
			<TrafficLight
				rating={ readabilityRating }
				label={ __( 'Readability', 'seo-studio' ) }
				testId={ testIds ? 'readability-light' : undefined }
			/>
		</>
	);
}
