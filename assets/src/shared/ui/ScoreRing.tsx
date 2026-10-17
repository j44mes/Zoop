import { __, sprintf } from '@wordpress/i18n';

interface ScoreRingProps {
	value: number | null;
	denominator: number | null;
}

export function ScoreRing( { value, denominator }: ScoreRingProps ) {
	if ( value === null || denominator === null || denominator <= 0 ) {
		return (
			<div
				className="seostudio-ring"
				data-testid="onpage-score"
				role="img"
				aria-label={ __( 'Not scored', 'seo-studio' ) }
			>
				{ __( 'Not scored', 'seo-studio' ) }
			</div>
		);
	}

	return (
		<div
			className="seostudio-ring"
			data-testid="onpage-score"
			role="img"
			aria-label={ sprintf(
				/* translators: 1: points earned, 2: points available. */
				__( '%1$d of %2$d', 'seo-studio' ),
				value,
				denominator
			) }
		>
			{ value }
			<span className="screen-reader-text">
				{ sprintf(
					/* translators: 1: points earned, 2: points available. */
					__( '%1$d of %2$d', 'seo-studio' ),
					value,
					denominator
				) }
			</span>
		</div>
	);
}
