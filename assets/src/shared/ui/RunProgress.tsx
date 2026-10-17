import { __, sprintf } from '@wordpress/i18n';

interface RunProgressProps {
	processed: number;
	total: number;
	message?: string;
}

export function RunProgress( { processed, total, message }: RunProgressProps ) {
	const width = total > 0 ? Math.round( ( processed / total ) * 100 ) : 0;
	return (
		<div className="seostudio-progress" role="status">
			<p>
				{ message ||
					sprintf(
						/* translators: 1: completed count, 2: total count */
						__( '%1$d of %2$d', 'seo-studio' ),
						processed,
						total
					) }
			</p>
			<div className="seostudio-bar" aria-hidden="true">
				<span style={ { width: `${ width }%` } } />
			</div>
		</div>
	);
}
