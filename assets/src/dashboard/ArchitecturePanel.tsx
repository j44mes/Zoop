import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	fetchArchitecture,
	trustAlias,
	type ArchitecturePage,
	type ArchitectureReport,
} from '../shared/api';
import { architectureStatus, depthLabel } from './architecture-copy';
import { pageWorkspaceUrl } from './PageWorkspace';

export function ArchitecturePanel() {
	const [ report, setReport ] = useState< ArchitectureReport | null >( null );
	const [ error, setError ] = useState( '' );
	const [ trustError, setTrustError ] = useState( '' );
	const [ pendingHost, setPendingHost ] = useState( '' );

	function load() {
		return fetchArchitecture()
			.then( ( next ) => {
				setReport( next );
				setError( '' );
			} )
			.catch( ( caught ) => {
				const code =
					caught && typeof caught === 'object' && 'code' in caught
						? String( ( caught as { code?: string } ).code )
						: '';
				if (
					code === 'seostudio_forbidden' ||
					code === 'rest_forbidden'
				) {
					setError(
						__(
							'Site architecture is available to administrators.',
							'seo-studio'
						)
					);
					return;
				}
				setError(
					__(
						'The architecture report could not be loaded.',
						'seo-studio'
					)
				);
			} );
	}

	useEffect( () => {
		load();
	}, [] );

	function changeTrust( host: string, trust: boolean ) {
		setPendingHost( host );
		setTrustError( '' );
		trustAlias( host, trust )
			.then( ( next ) => {
				setReport( next );
			} )
			.catch( () => {
				setTrustError(
					__(
						'The domain could not be updated. Reload Zoop and try again.',
						'seo-studio'
					)
				);
			} )
			.finally( () => {
				setPendingHost( '' );
			} );
	}

	if ( error ) {
		return <p role="alert">{ error }</p>;
	}
	if ( ! report ) {
		return (
			<p>{ __( 'Loading the architecture report.', 'seo-studio' ) }</p>
		);
	}

	const orphans =
		report.counts.orphans === null
			? __( 'Hidden', 'seo-studio' )
			: String( report.counts.orphans );

	return (
		<div className="seostudio-architecture">
			<h2>{ __( 'Site architecture', 'seo-studio' ) }</h2>
			<p className="seostudio-lede">
				{ __(
					'Zoop counts internal links from stored analyses and navigation menus. It does not request those addresses and it does not edit pages.',
					'seo-studio'
				) }
			</p>
			<ul className="seostudio-notes">
				{ report.notes.map( ( note ) => (
					<li key={ note }>{ note }</li>
				) ) }
			</ul>
			{ ( report.possible_aliases ?? [] ).map( ( alias ) => (
				<p className="seostudio-alias" key={ alias.host }>
					{ alias.message }{ ' ' }
					<button
						type="button"
						disabled={ pendingHost === alias.host }
						onClick={ () => changeTrust( alias.host, true ) }
					>
						{ __( 'Trust this domain', 'seo-studio' ) }
					</button>
				</p>
			) ) }
			{ ( report.trusted_aliases ?? [] ).map( ( host ) => (
				<p className="seostudio-alias" key={ host }>
					{ sprintf(
						/* translators: %s: trusted hostname. */
						__( '%s is trusted as this site.', 'seo-studio' ),
						host
					) }{ ' ' }
					<button
						type="button"
						disabled={ pendingHost === host }
						onClick={ () => changeTrust( host, false ) }
					>
						{ __( 'Remove trust', 'seo-studio' ) }
					</button>
				</p>
			) ) }
			{ trustError ? <p role="alert">{ trustError }</p> : null }
			{ report.limited ? (
				<p className="seostudio-muted">
					{ __(
						'Each list shows the first 50 pages.',
						'seo-studio'
					) }
				</p>
			) : null }
			<div className="seostudio-summary">
				<article>
					<h2>{ __( 'Inventory', 'seo-studio' ) }</h2>
					<p>{ report.counts.inventory }</p>
				</article>
				<article>
					<h2>{ __( 'Analysed', 'seo-studio' ) }</h2>
					<p>{ report.counts.analysed }</p>
				</article>
				<article>
					<h2>{ __( 'Orphans', 'seo-studio' ) }</h2>
					<p>{ orphans }</p>
				</article>
				<article>
					<h2>{ __( 'Weakly linked', 'seo-studio' ) }</h2>
					<p>{ report.counts.weak }</p>
				</article>
				<article>
					<h2>{ __( 'Template only', 'seo-studio' ) }</h2>
					<p>{ report.counts.template_only }</p>
				</article>
				<article>
					<h2>{ __( 'Unresolved', 'seo-studio' ) }</h2>
					<p>{ report.counts.unresolved }</p>
				</article>
			</div>
			<PageList
				title={ __( 'Orphans', 'seo-studio' ) }
				empty={
					report.counts.orphans === null
						? __(
								'Orphans stay hidden until the inventory is complete.',
								'seo-studio'
						  )
						: __(
								'No orphan pages in this inventory.',
								'seo-studio'
						  )
				}
				pages={ report.orphans }
			/>
			<PageList
				title={ __( 'Weakly linked', 'seo-studio' ) }
				empty={ __(
					'No page has exactly one contextual referring page.',
					'seo-studio'
				) }
				pages={ report.weak }
			/>
			<PageList
				title={ __( 'Linked from the template only', 'seo-studio' ) }
				empty={ __(
					'No page is linked only from navigation or the template.',
					'seo-studio'
				) }
				pages={ report.template_only }
			/>
			{ report.unresolved.length > 0 ||
			( report.unverified ?? [] ).length > 0 ? (
				<p className="seostudio-muted">
					{ __(
						'A confirmed missing destination is separate from an address Zoop could not classify.',
						'seo-studio'
					) }
				</p>
			) : null }
			<h3>{ __( 'Unresolved internal links', 'seo-studio' ) }</h3>
			{ report.unresolved.length === 0 ? (
				<p className="seostudio-muted">
					{ __(
						'No confirmed broken internal links.',
						'seo-studio'
					) }
				</p>
			) : (
				<div className="seostudio-table-wrap">
					<table className="seostudio-table">
						<thead>
							<tr>
								<th>{ __( 'From', 'seo-studio' ) }</th>
								<th>{ __( 'Address', 'seo-studio' ) }</th>
								<th>{ __( 'Anchor', 'seo-studio' ) }</th>
								<th>{ __( 'Kind', 'seo-studio' ) }</th>
								<th>{ __( 'Times linked', 'seo-studio' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ report.unresolved.map( ( link ) => (
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
									<td>{ linkKindLabel( link.kind ) }</td>
									<td>{ link.occurrences }</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }
			<h3>{ __( "Links Zoop couldn't verify", 'seo-studio' ) }</h3>
			{ ( report.unverified ?? [] ).length === 0 ? null : (
				<p className="seostudio-muted">
					{ __(
						'These addresses are on this site and did not match a local WordPress route. They are not confirmed broken links.',
						'seo-studio'
					) }
				</p>
			) }
			{ ( report.unverified ?? [] ).length === 0 ? null : (
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
							{ ( report.unverified ?? [] ).map( ( link ) => (
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
			) }
		</div>
	);
}

function PageList( {
	title,
	empty,
	pages,
}: {
	title: string;
	empty: string;
	pages: ArchitecturePage[];
} ) {
	return (
		<section>
			<h3>{ title }</h3>
			{ pages.length === 0 ? (
				<p className="seostudio-muted">{ empty }</p>
			) : (
				<div className="seostudio-table-wrap">
					<table className="seostudio-table">
						<thead>
							<tr>
								<th>{ __( 'Page', 'seo-studio' ) }</th>
								<th>{ __( 'Depth', 'seo-studio' ) }</th>
								<th>
									{ __( 'Contextual pages', 'seo-studio' ) }
								</th>
								<th>{ __( 'Occurrences', 'seo-studio' ) }</th>
								<th>
									{ __( 'Template pages', 'seo-studio' ) }
								</th>
								<th>{ __( 'Status', 'seo-studio' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ pages.map( ( page ) => (
								<tr key={ page.id }>
									<td>
										{ page.post_id > 0 ? (
											<a
												href={ pageWorkspaceUrl(
													page.post_id
												) }
											>
												{ page.title || page.url }
											</a>
										) : (
											page.title || page.url
										) }
									</td>
									<td>{ depthLabel( page.depth ) }</td>
									<td>{ page.contextual_referrers }</td>
									<td>{ page.contextual_occurrences }</td>
									<td>{ page.template_referrers }</td>
									<td>
										<div>
											{ architectureStatus(
												page.status
											) }
										</div>
										{ page.action ? (
											<div>{ page.action }</div>
										) : null }
										<Why page={ page } />
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }
		</section>
	);
}

function linkKindLabel( kind: string ): string {
	if ( 'template' === kind ) {
		return __( 'Template', 'seo-studio' );
	}
	if ( 'navigation' === kind ) {
		return __( 'Navigation', 'seo-studio' );
	}
	return __( 'Contextual', 'seo-studio' );
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

function Why( { page }: { page: ArchitecturePage } ) {
	const detailed =
		page.observation ||
		page.evidence ||
		page.confidence ||
		page.verify ||
		( page.candidates ?? [] ).length > 0;
	if ( ! detailed ) {
		return null;
	}
	return (
		<details className="seostudio-why">
			<summary>{ __( 'Why?', 'seo-studio' ) }</summary>
			{ page.observation ? (
				<p>
					<strong>{ __( 'Observation', 'seo-studio' ) }</strong>{ ' ' }
					{ page.observation }
				</p>
			) : null }
			{ page.evidence ? (
				<p>
					<strong>{ __( 'Evidence', 'seo-studio' ) }</strong>{ ' ' }
					{ page.evidence }
				</p>
			) : null }
			{ page.confidence ? (
				<p>
					<strong>{ __( 'Confidence', 'seo-studio' ) }</strong>{ ' ' }
					{ page.confidence }
				</p>
			) : null }
			{ page.verify ? (
				<p>
					<strong>{ __( 'Verify', 'seo-studio' ) }</strong>{ ' ' }
					{ page.verify }
				</p>
			) : null }
			{ ( page.candidates ?? [] ).length > 0 ? (
				<>
					<p>{ __( 'Pages that could link here:', 'seo-studio' ) }</p>
					<ul className="seostudio-candidates">
						{ ( page.candidates ?? [] ).map( ( candidate ) => (
							<li key={ candidate.post_id }>
								{ candidate.post_id > 0 ? (
									<a
										href={ pageWorkspaceUrl(
											candidate.post_id
										) }
									>
										{ candidate.title || candidate.url }
									</a>
								) : (
									candidate.title || candidate.url
								) }
								{ candidate.reason
									? ` (${ candidate.reason })`
									: '' }
							</li>
						) ) }
					</ul>
				</>
			) : null }
		</details>
	);
}
