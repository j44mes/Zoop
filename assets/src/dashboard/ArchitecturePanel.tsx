import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	fetchArchitecture,
	type ArchitecturePage,
	type ArchitectureReport,
} from '../shared/api';
import { architectureStatus, depthLabel } from './architecture-copy';
import { pageWorkspaceUrl } from './PageWorkspace';

export function ArchitecturePanel() {
	const [ report, setReport ] = useState< ArchitectureReport | null >( null );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		let cancelled = false;
		fetchArchitecture()
			.then( ( next ) => {
				if ( ! cancelled ) {
					setReport( next );
				}
			} )
			.catch( ( caught ) => {
				if ( cancelled ) {
					return;
				}
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
		return () => {
			cancelled = true;
		};
	}, [] );

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
			<h3>{ __( 'Unresolved internal links', 'seo-studio' ) }</h3>
			{ report.unresolved.length === 0 ? (
				<p className="seostudio-muted">
					{ __(
						'Every internal link matches a published page in this inventory.',
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
								<th>{ __( 'Kind', 'seo-studio' ) }</th>
								<th>{ __( 'Occurrences', 'seo-studio' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ report.unresolved.map( ( link ) => (
								<tr
									key={ `${ link.from_id }-${ link.href }-${ link.kind }` }
								>
									<td>{ link.from_title }</td>
									<td>{ link.href }</td>
									<td>
										{ link.kind === 'template'
											? __( 'Template', 'seo-studio' )
											: __( 'Contextual', 'seo-studio' ) }
									</td>
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
										{ architectureStatus( page.status ) }
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
