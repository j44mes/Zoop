import { __, sprintf } from '@wordpress/i18n';

interface ScoreRingProps {
	value: number | null;
	denominator: number | null;
	label?: string;
}

export function ScoreRing( {
	value,
	denominator,
	label = '',
}: ScoreRingProps ) {
	if ( value === null || denominator === null || denominator <= 0 ) {
		const text =
			label.trim() !== ''
				? label
				: __( 'Not analysed yet', 'seo-studio' );
		return (
			<div
				className="seostudio-ring is-unavailable"
				data-testid="onpage-score"
				role="img"
				aria-label={ text }
			>
				<span aria-hidden="true">–</span>
				<span className="screen-reader-text">{ text }</span>
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
