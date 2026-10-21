import { __ } from '@wordpress/i18n';

const labels: Record< string, string > = {
	red: __( 'Poor', 'seo-studio' ),
	orange: __( 'Ok', 'seo-studio' ),
	green: __( 'Good', 'seo-studio' ),
	grey: __( 'Not analysed yet', 'seo-studio' ),
};

interface TrafficLightProps {
	rating: string | null;
	label: string;
	testId?: string;
	value?: string;
	detail?: string;
}

export function TrafficLight( {
	rating,
	label,
	testId,
	value,
	detail,
}: TrafficLightProps ) {
	const neutral = typeof value === 'string' && value !== '';
	let key = 'grey';
	if ( ! neutral && rating && labels[ rating ] ) {
		key = rating;
	}
	const word = neutral ? value : labels[ key ];
	return (
		<span
			className="seostudio-chip"
			data-rating={ key }
			data-testid={ testId }
		>
			{ label }: { word }
			{ detail ? ` (${ detail })` : '' }
		</span>
	);
}
