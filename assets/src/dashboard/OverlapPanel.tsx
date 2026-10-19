import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { fetchOverlap, type OverlapReport } from '../shared/api';
import { pageWorkspaceUrl } from './PageWorkspace';
import {
	evidenceEstablishes,
	evidenceSignal,
	overlapConclusion,
	overlapConfidence,
	overlapObservation,
} from './overlap-copy';

export function OverlapPanel() {
	const [ report, setReport ] = useState< OverlapReport | null >( null );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		let cancelled = false;
		fetchOverlap()
			.then( ( next ) => {
				if ( ! cancelled ) {
					setReport( next );
				}
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setError(
						__(
							'The overlap report could not be loaded.',
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
		return <p>{ __( 'Loading the overlap report.', 'seo-studio' ) }</p>;
	}

	return (
		<div className="seostudio-overlap">
			<h2>{ __( 'Overlap', 'seo-studio' ) }</h2>
			<p className="seostudio-lede">
				{ __(
					'Zoop shows what it observed and, separately, what that observation can conclude. A shared phrase is not proof that the pages compete, and nothing is edited.',
					'seo-studio'
				) }
			</p>
			<ul className="seostudio-notes">
				{ report.notes.map( ( note ) => (
					<li key={ note }>{ note }</li>
				) ) }
			</ul>
			{ report.groups.map( ( group, index ) => (
				<article
					className="seostudio-suggestion"
					key={ `${ group.observation }-${ group.phrase }-${ index }` }
				>
					<h3>
						{ overlapObservation( group.observation ) }
						{ group.phrase ? `: ${ group.phrase }` : '' }
					</h3>
					<dl className="seostudio-evidence">
						<div>
							<dt>{ __( 'Observation', 'seo-studio' ) }</dt>
							<dd>
								{ overlapObservation( group.observation ) }
								{ ' — ' }
								{ overlapConfidence(
									group.observation_confidence
								) }
							</dd>
						</div>
						<div>
							<dt>{ __( 'Conclusion', 'seo-studio' ) }</dt>
							<dd>
								{ overlapConclusion( group.conclusion ) }
								{ ' — ' }
								{ overlapConfidence(
									group.conclusion_confidence
								) }
							</dd>
						</div>
					</dl>
					<p>{ group.reason }</p>
					{ group.evidence.length > 0 ? (
						<ul>
							{ group.evidence.map( ( row ) => (
								<li key={ `${ row.signal }-${ row.detail }` }>
									<strong>
										{ evidenceSignal( row.signal ) }
									</strong>
									{ ': ' }
									{ row.detail } (
									{ evidenceEstablishes( row.establishes ) }
									{ row.independent
										? ''
										: __(
												'; not independent evidence',
												'seo-studio'
										  ) }
									)
								</li>
							) ) }
						</ul>
					) : null }
					{ group.pages.length > 0 ? (
						<ul>
							{ group.pages.map( ( page ) => (
								<li key={ page.post_id }>
									<a
										href={ pageWorkspaceUrl(
											page.post_id
										) }
									>
										{ page.title ||
											__( 'Untitled', 'seo-studio' ) }
									</a>
								</li>
							) ) }
						</ul>
					) : null }
				</article>
			) ) }
		</div>
	);
}
