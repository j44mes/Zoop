import { __ } from '@wordpress/i18n';
import { pageWorkspaceUrl } from './page-link';

interface CoverageReasons {
	unanalysed: number;
	content_only: number;
	stale_links: number;
	navigation_limited: boolean;
	navigation_note: string;
}

interface UnverifiedLink {
	from_id: string;
	from_title: string;
	href: string;
	kind: string;
	anchor: string;
	occurrences: number;
}

/**
 * Next actions for incomplete architecture coverage.
 *
 * A site audit is offered only when pages still need content analysis.
 * Published-link gaps link to the existing page-level check.
 */
export function CoverageActions( {
	reasons,
	onAudit,
}: {
	reasons: CoverageReasons | null;
	onAudit: () => void;
} ) {
	if ( ! reasons ) {
		return null;
	}
	const checkLinks = reasons.content_only > 0 || reasons.stale_links > 0;
	if ( reasons.unanalysed < 1 && ! checkLinks ) {
		return null;
	}
	return (
		<>
			{ reasons.unanalysed > 0 ? (
				<p>
					<button type="button" onClick={ onAudit }>
						{ __( 'Run site audit', 'seo-studio' ) }
					</button>{ ' ' }
					<span className="seostudio-muted">
						{ __(
							'This analyses page content. It does not restore published-link evidence.',
							'seo-studio'
						) }
					</span>
				</p>
			) : null }
			{ checkLinks ? (
				<p>
					<a href="admin.php?page=seostudio&section=pages&score_state=unchecked">
						{ __( 'Check published pages', 'seo-studio' ) }
					</a>
				</p>
			) : null }
		</>
	);
}

/**
 * Unverified destinations. The heading is omitted when there are none.
 */
export function UnverifiedLinks( { links }: { links: UnverifiedLink[] } ) {
	if ( links.length === 0 ) {
		return null;
	}
	return (
		<>
			<h3>{ __( "Links Zoop couldn't verify", 'seo-studio' ) }</h3>
			<p className="seostudio-muted">
				{ __(
					'These addresses are on this site and did not match a local WordPress route. They are not confirmed broken links.',
					'seo-studio'
				) }
			</p>
			<div className="seostudio-table-wrap">
				<table className="seostudio-table">
					<thead>
						<tr>
							<th>{ __( 'From', 'seo-studio' ) }</th>
							<th>{ __( 'Address', 'seo-studio' ) }</th>
							<th>{ __( 'Anchor', 'seo-studio' ) }</th>
							<th>{ __( 'Times linked', 'seo-studio' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ links.map( ( link ) => (
							<tr
								key={ `${ link.from_id }-${ link.href }-${ link.kind }` }
							>
								<td>
									<SourceLink
										fromId={ link.from_id }
										title={ link.from_title }
									/>
								</td>
								<td>{ link.href }</td>
								<td>{ link.anchor || '' }</td>
								<td>{ link.occurrences }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
		</>
	);
}

function sourcePostId( fromId: string ): number {
	const match = /post:(\d+)$/.exec( fromId );
	return match ? Number( match[ 1 ] ) : 0;
}

function SourceLink( { fromId, title }: { fromId: string; title: string } ) {
	const postId = sourcePostId( fromId );
	if ( postId < 1 ) {
		return <>{ title }</>;
	}
	return <a href={ pageWorkspaceUrl( postId ) }>{ title || fromId }</a>;
}
