import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { fetchOverlap, type OverlapReport } from '../shared/api';
import { pageWorkspaceUrl } from './PageWorkspace';
import { overlapLevel } from './overlap-copy';

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
					'Zoop compares target phrases, topics, and title words. A shared phrase is not proof that the pages compete, and nothing is edited.',
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
					key={ `${ group.level }-${ group.phrase }-${ index }` }
				>
					<h3>
						{ overlapLevel( group.level ) }
						{ group.phrase ? `: ${ group.phrase }` : '' }
					</h3>
					<p>{ group.reason }</p>
					<p className="seostudio-muted">
						{ __( 'Confidence:', 'seo-studio' ) }{ ' ' }
						{ overlapLevel( group.confidence ) }
					</p>
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
