import { Button, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export function FetchFailureNotice( {
	message,
	retainedNotice,
	guidanceUrl,
	busy,
	onRetry,
}: {
	message: string;
	retainedNotice?: string;
	guidanceUrl?: string;
	busy: boolean;
	onRetry: () => void;
} ) {
	return (
		<Notice status="error" isDismissible={ false }>
			<p>{ message }</p>
			{ retainedNotice ? <p>{ retainedNotice }</p> : null }
			<p className="seostudio-actions">
				<Button
					variant="secondary"
					disabled={ busy }
					onClick={ onRetry }
				>
					{ __( 'Try again', 'seo-studio' ) }
				</Button>
				{ guidanceUrl ? (
					<a
						className="seostudio-text-button"
						href={ guidanceUrl }
						target="_blank"
						rel="noreferrer"
					>
						{ __( 'How to let Zoop through', 'seo-studio' ) }
					</a>
				) : null }
			</p>
		</Notice>
	);
}
