import { __ } from '@wordpress/i18n';
import type { UpgradeNoticePayload } from '../shared/api';

// Ruleset-retirement banner. The count and link come from PHP.
export function UpgradeNoticeBanner( {
	notice,
	onDismiss,
	testId = 'ruleset-upgrade-notice',
	label,
}: {
	notice: UpgradeNoticePayload;
	onDismiss: () => void;
	testId?: string;
	label?: string;
} ) {
	return (
		<section
			className="seostudio-upgrade-notice"
			role="status"
			data-testid={ testId }
			aria-label={ label ?? __( 'Scoring rules update', 'seo-studio' ) }
		>
			<p>{ notice.message }</p>
			<p>
				<a href={ notice.review_url }>
					{ __( 'Review pages needing checks', 'seo-studio' ) }
				</a>{ ' ' }
				<button type="button" onClick={ onDismiss }>
					{ __( 'Dismiss', 'seo-studio' ) }
				</button>
			</p>
		</section>
	);
}
