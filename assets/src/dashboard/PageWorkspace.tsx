import { Button, Notice } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	analysePost,
	fetchPage,
	fetchPages,
	fetchSeo,
	type PageRecord,
	type SeoFields,
} from '../shared/api';
import { AssessmentList } from '../shared/ui/AssessmentList';
import { assessmentTitle } from '../shared/ui/assessment-labels';
import { ScoreRing } from '../shared/ui/ScoreRing';
import { SnippetPreview } from '../shared/ui/SnippetPreview';
import { SocialPreview } from '../shared/ui/SocialPreview';
import type { ListedAssessment } from '../shared/ui/AssessmentList';

function queryValue( name: string ): string {
	return new URLSearchParams( window.location.search ).get( name ) || '';
}

export function pageWorkspaceUrl( postId: number ): string {
	return `admin.php?page=seostudio&view=page&post=${ postId }`;
}

export function isPageWorkspace(): boolean {
	return queryValue( 'view' ) === 'page';
}

/**
 * Full-width analysis for one stored page. It stays on the Zoop admin screen.
 */
export function PageWorkspace() {
	const postId = Number( queryValue( 'post' ) || 0 );
	const [ record, setRecord ] = useState< PageRecord | null >( null );
	const [ seo, setSeo ] = useState< SeoFields | null >( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	useEffect( () => {
		if ( ! postId ) {
			setError(
				__( 'Choose a page from the Zoop overview.', 'seo-studio' )
			);
			return;
		}
		fetchPages( { object_id: String( postId ), per_page: '1' } )
			.then( ( list ) => {
				const item = list.items[ 0 ];
				if ( ! item ) {
					setRecord( null );
					return null;
				}
				return fetchPage( item.page_id );
			} )
			.then( ( next ) => {
				if ( next ) {
					setRecord( next );
				}
				return fetchSeo( postId );
			} )
			.then( ( fields ) => setSeo( fields ) )
			.catch( () =>
				setError( __( 'This page could not be loaded.', 'seo-studio' ) )
			);
	}, [ postId ] );

	async function analyse() {
		if ( ! postId ) {
			return;
		}
		setBusy( true );
		setError( '' );
		try {
			const next = await analysePost( postId, { mode: 'content' } );
			setRecord( next );
		} catch ( caught ) {
			const message =
				caught && typeof caught === 'object' && 'message' in caught
					? String( ( caught as { message: string } ).message )
					: __( 'The page could not be analysed.', 'seo-studio' );
			setError( message );
		} finally {
			setBusy( false );
		}
	}

	const onpage = record?.scores.find( ( row ) => row.engine === 'onpage' );
	const details = onpage?.details as
		| { assessments?: ListedAssessment[] }
		| undefined;
	const assessments = details?.assessments || [];
	const findings = assessments
		.filter(
			( row ) => row.status !== 'pass' && row.status !== 'not_checked'
		)
		.slice( 0, 3 );
	const model = record?.page_model;

	return (
		<div className="seostudio-workspace">
			<p>
				<a href="admin.php?page=seostudio">
					{ __( 'Back to overview', 'seo-studio' ) }
				</a>
			</p>
			<h2>{ __( 'Page analysis', 'seo-studio' ) }</h2>
			{ error ? (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) : null }
			{ ! record ? (
				<p>
					{ __(
						'This page has not been analysed yet.',
						'seo-studio'
					) }
				</p>
			) : (
				<>
					<p className="seostudio-muted">
						{ seo?.focus_keyphrase
							? sprintf(
									/* translators: %s: saved target phrase. */
									__( 'Target phrase: %s', 'seo-studio' ),
									seo.focus_keyphrase
							  )
							: __( 'No target phrase is saved.', 'seo-studio' ) }
					</p>
					<p>
						<Button
							variant="primary"
							disabled={ busy }
							onClick={ analyse }
						>
							{ busy
								? __( 'Analysing…', 'seo-studio' )
								: __( 'Analyse page', 'seo-studio' ) }
						</Button>{ ' ' }
						{ postId ? (
							<a href={ `post.php?post=${ postId }&action=edit` }>
								{ __( 'Edit page', 'seo-studio' ) }
							</a>
						) : null }
					</p>
					<div className="seostudio-summary">
						<article>
							<h2>{ __( 'On-page', 'seo-studio' ) }</h2>
							<ScoreRing
								value={ onpage ? onpage.score : null }
								denominator={
									onpage ? onpage.denominator : null
								}
							/>
						</article>
					</div>
					<p className="seostudio-muted">
						{ __(
							'SEO and readability scores are saved from the editor. A website audit does not refresh them.',
							'seo-studio'
						) }
					</p>
					<h3>{ __( 'What needs attention', 'seo-studio' ) }</h3>
					{ findings.length === 0 ? (
						<p>
							{ __(
								'No outstanding on-page finding was stored.',
								'seo-studio'
							) }
						</p>
					) : (
						<ul className="seostudio-findings">
							{ findings.map( ( row ) => (
								<li key={ row.id }>
									{ assessmentTitle( row.id ) }
									{ row.recommendation
										? `. ${ row.recommendation }`
										: '' }
								</li>
							) ) }
						</ul>
					) }
					<details>
						<summary>
							{ __( 'All on-page checks', 'seo-studio' ) }
						</summary>
						<AssessmentList items={ assessments } />
					</details>
					<h3>{ __( 'Search appearance', 'seo-studio' ) }</h3>
					<SnippetPreview
						title={ model?.title_tag || '' }
						url={ model?.url || record.url || '' }
						description={ model?.meta_description || '' }
					/>
					<h3>{ __( 'Social appearance', 'seo-studio' ) }</h3>
					<SocialPreview
						title={ seo?.og_title || model?.title_tag || '' }
						description={
							seo?.og_description || model?.meta_description || ''
						}
					/>
					{ record.last_error ? (
						<Notice status="error" isDismissible={ false }>
							{ record.last_error }
						</Notice>
					) : null }
				</>
			) }
		</div>
	);
}
