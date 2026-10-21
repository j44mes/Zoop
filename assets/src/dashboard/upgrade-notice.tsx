import { __ } from '@wordpress/i18n';
import type { UpgradeNoticePayload } from '../shared/api';

// Ruleset-retirement banner. The count and link come from PHP.
export function UpgradeNoticeBanner( {
	notice,
	onDismiss,
}: {
	notice: UpgradeNoticePayload;
	onDismiss: () => void;
} ) {
	return (
		<section
			className="seostudio-upgrade-notice"
			role="status"
			data-testid="ruleset-upgrade-notice"
			aria-label={ __( 'Scoring rules update', 'seo-studio' ) }
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
