import { __ } from '@wordpress/i18n';
import type { ContentAnalysis } from './score-paper';
import { AssessmentList } from '../shared/ui/AssessmentList';
import { EvidenceLights } from '../shared/ui/EvidenceLights';

interface ContentResultsProps {
	analysis: ContentAnalysis | null;
	unavailable: boolean;
	compact?: boolean;
	onPageVerified?: boolean;
}

export function ContentResults( {
	analysis,
	unavailable,
	compact = false,
	onPageVerified = false,
}: ContentResultsProps ) {
	if ( unavailable ) {
		return (
			<p className="seostudio-note" data-testid="worker-unavailable">
				{ __(
					'The content analysis worker did not load. The on-page score is still shown.',
					'seo-studio'
				) }
			</p>
		);
	}
	if ( ! analysis ) {
		return (
			<p>{ __( 'Content analysis has not run yet.', 'seo-studio' ) }</p>
		);
	}

	const checks = (
		<AssessmentList
			items={ [
				...analysis.seo.assessments,
				...analysis.readability.assessments.map( ( row ) => ( {
					...row,
					category: __( 'Readability', 'seo-studio' ),
				} ) ),
			] }
		/>
	);

	return (
		<div className="seostudio-content">
			<p>
				<EvidenceLights
					verified={ onPageVerified }
					seoRating={ analysis.seo.rating }
					readabilityRating={ analysis.readability.rating }
					testIds
				/>
			</p>
			{ compact ? (
				<details>
					<summary>
						{ __( 'Content and readability checks', 'seo-studio' ) }
					</summary>
					{ checks }
				</details>
			) : (
				checks
			) }
		</div>
	);
}
