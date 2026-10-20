import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	fetchRecommendations,
	type RecommendationItem,
	type RecommendationPage,
} from '../shared/api';
import {
	overlapConclusion,
	overlapConfidence,
	overlapObservation,
} from './overlap-copy';
import { pageWorkspaceUrl } from './PageWorkspace';
import { priorityKind } from './priorities-copy';

export function PrioritiesPanel() {
	const [ report, setReport ] = useState< {
		items: RecommendationItem[];
		notes: string[];
		limited: boolean;
	} | null >( null );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		let cancelled = false;
		fetchRecommendations()
			.then( ( next ) => {
				if ( ! cancelled ) {
					setReport( next );
				}
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setError(
						__(
							'The priority list could not be loaded.',
							'seo-studio'
						)
					);
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [] );

	if ( error ) {
		return <p role="alert">{ error }</p>;
	}
	if ( ! report ) {
		return <p>{ __( 'Loading the priority list.', 'seo-studio' ) }</p>;
	}

	return (
		<div className="seostudio-priorities">
			<h2>{ __( 'Priorities', 'seo-studio' ) }</h2>
			<p className="seostudio-lede">
				{ __(
					'Zoop orders structural issues ahead of phrase overlap. A shared phrase is an observation, not an urgent problem. Imported impressions are not commercial value or lost traffic. Nothing on the site is edited.',
					'seo-studio'
				) }
			</p>
			<ul className="seostudio-notes">
				{ report.notes.map( ( note ) => (
					<li key={ note }>{ note }</li>
				) ) }
			</ul>
			{ report.items.length === 0 ? (
				<p>
					{ __(
						'Nothing in the stored reports needs to be ordered ahead of ordinary review.',
						'seo-studio'
					) }
				</p>
			) : null }
			{ report.items.map( ( item, index ) => (
				<article
					className="seostudio-suggestion"
					key={ `${ item.kind }-${ item.title }-${ index }` }
				>
					<h3>
						{ sprintf(
							/* translators: 1: priority number, 2: item title. */
							__( 'Priority %1$d: %2$s', 'seo-studio' ),
							item.priority,
							item.title
						) }
					</h3>
					<p className="seostudio-muted">
						{ priorityKind( item.kind ) }
					</p>
					{ item.observation ? (
						<dl className="seostudio-evidence">
							<div>
								<dt>{ __( 'Observation', 'seo-studio' ) }</dt>
								<dd>
									{ overlapObservation( item.observation ) }
									{ ' — ' }
									{ overlapConfidence(
										item.observation_confidence ||
											'insufficient'
									) }
								</dd>
							</div>
							<div>
								<dt>{ __( 'Conclusion', 'seo-studio' ) }</dt>
								<dd>
									{ overlapConclusion(
										item.conclusion || 'investigation'
									) }
									{ ' — ' }
									{ overlapConfidence(
										item.conclusion_confidence ||
											'insufficient'
									) }
								</dd>
							</div>
						</dl>
					) : null }
					<p>{ item.action }</p>
					<p>{ item.reason }</p>
					{ item.pages.length > 0 ? (
						<ul>
							{ item.pages.map( ( page ) => (
								<li
									key={ `${ page.post_id }-${ page.url }-${
										page.redacted ? 'hidden' : 'open'
									}` }
								>
									<PriorityPage page={ page } />
								</li>
							) ) }
						</ul>
					) : null }
				</article>
			) ) }
		</div>
	);
}

function PriorityPage( { page }: { page: RecommendationPage } ) {
	if ( page.redacted ) {
		return (
			<>
				{ __(
					'Another page is part of this finding. Its details are hidden.',
					'seo-studio'
				) }
			</>
		);
	}
	if ( page.post_id > 0 ) {
		return (
			<a href={ pageWorkspaceUrl( page.post_id ) }>
				{ page.title || __( 'Untitled', 'seo-studio' ) }
			</a>
		);
	}
	return <>{ page.title }</>;
}
