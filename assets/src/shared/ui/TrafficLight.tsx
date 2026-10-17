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
}

export function TrafficLight( { rating, label, testId }: TrafficLightProps ) {
	const key = rating && labels[ rating ] ? rating : 'grey';
	return (
		<span
			className="seostudio-chip"
			data-rating={ key }
			data-testid={ testId }
		>
			{ label }: { labels[ key ] }
		</span>
	);
}
