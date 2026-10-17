import { __, sprintf } from '@wordpress/i18n';

interface CategoryBarProps {
	label: string;
	earned: number;
	max: number;
}

export function CategoryBar( { label, earned, max }: CategoryBarProps ) {
	const width = max > 0 ? Math.round( ( earned / max ) * 100 ) : 0;
	return (
		<div className="seostudio-category">
			<span>
				{ sprintf(
					/* translators: 1: category name, 2: points earned, 3: points available */
					__( '%1$s, %2$d of %3$d', 'seo-studio' ),
					label,
					earned,
					max
				) }
			</span>
			<div className="seostudio-bar" aria-hidden="true">
				<span style={ { width: `${ width }%` } } />
			</div>
		</div>
	);
}
