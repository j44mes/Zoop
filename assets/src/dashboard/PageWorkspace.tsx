import { Button, Notice, TextControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	analysePost,
	fetchPage,
	fetchPages,
	fetchSeo,
	fetchSettings,
	saveSeo,
	type PageRecord,
	type SeoFields,
	type StudioSettings,
} from '../shared/api';
import { AssessmentList } from '../shared/ui/AssessmentList';
import { assessmentTitle } from '../shared/ui/assessment-labels';
import type { ListedAssessment } from '../shared/ui/AssessmentList';
import { ScoreRing } from '../shared/ui/ScoreRing';
import { SnippetPreview } from '../shared/ui/SnippetPreview';
import { SocialPreview } from '../shared/ui/SocialPreview';
import { editHref, editTarget } from './edit-route';
import {
	snippetCaption,
	socialCaption,
	workspaceOwnership,
} from './ownership-note';

function queryValue( name: string ): string {
	return new URLSearchParams( window.location.search ).get( name ) || '';
}

export function pageWorkspaceUrl( postId: number ): string {
	return `admin.php?page=seostudio&view=page&post=${ postId }`;
}

export function isPageWorkspace(): boolean {
	return queryValue( 'view' ) === 'page';
}

type LoadState = 'loading' | 'ready' | 'missing' | 'error';

function messageOf( error: unknown, fallback: string ): string {
	if ( error && typeof error === 'object' && 'message' in error ) {
		return String( ( error as { message: string } ).message );
	}
	return fallback;
}

/**
 * Full-width analysis for one page. It stays on the Zoop admin screen.
 */
export function PageWorkspace() {
	const postId = Number( queryValue( 'post' ) || 0 );
	const [ load, setLoad ] = useState< LoadState >( 'loading' );
	const [ record, setRecord ] = useState< PageRecord | null >( null );
	const [ seo, setSeo ] = useState< SeoFields | null >( null );
	const [ settings, setSettings ] = useState< StudioSettings | null >( null );
	const [ phrase, setPhrase ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState< 'content' | 'live' | '' >( '' );

	useEffect( () => {
		if ( ! postId ) {
			setLoad( 'missing' );
			setRecord( null );
			return;
		}
		let cancelled = false;
		setLoad( 'loading' );
		setError( '' );
		setRecord( null );
		Promise.all( [
			fetchPages( { object_id: String( postId ), per_page: '1' } ),
			fetchSeo( postId ),
			fetchSettings(),
		] )
			.then( async ( [ list, fields, studio ] ) => {
				if ( cancelled ) {
					return;
				}
				setSeo( fields );
				setPhrase( fields.focus_keyphrase || '' );
				setSettings( studio );
				const item = list.items[ 0 ];
				if ( item ) {
					const stored = await fetchPage( item.page_id );
					if ( ! cancelled ) {
						setRecord( stored );
					}
				}
				if ( ! cancelled ) {
					setLoad( 'ready' );
				}
			} )
			.catch( ( caught ) => {
				if ( cancelled ) {
					return;
				}
				setError(
					messageOf(
						caught,
						__( 'This page could not be loaded.', 'seo-studio' )
					)
				);
				setLoad( 'error' );
			} );
		return () => {
			cancelled = true;
		};
	}, [ postId ] );

	async function analyse( nextMode: 'content' | 'live' ) {
		if ( ! postId ) {
			return;
		}
		if ( ! phrase.trim() ) {
			setError(
				__(
					'Add a target phrase before analysing this page.',
					'seo-studio'
				)
			);
			return;
		}
		setBusy( nextMode );
		setError( '' );
		try {
			const saved = await saveSeo( postId, {
				focus_keyphrase: phrase.trim(),
			} );
			setSeo( saved );
			const next = await analysePost( postId, {
				mode: nextMode,
				keyphrase: phrase.trim(),
			} );
			setRecord( next );
		} catch ( caught ) {
			setError(
				messageOf(
					caught,
					__( 'The page could not be analysed.', 'seo-studio' )
				)
			);
		} finally {
			setBusy( '' );
		}
	}

	const onpage = record?.scores.find( ( row ) => row.engine === 'onpage' );
	const analysed = !! onpage && onpage.denominator > 0;
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
	const phraseMissing = phrase.trim() === '';

	return (
		<div className="seostudio-workspace">
			<p>
				<a href="admin.php?page=seostudio">
					{ __( 'Back to overview', 'seo-studio' ) }
				</a>
			</p>
			<h2>{ __( 'Page analysis', 'seo-studio' ) }</h2>
			{ load === 'loading' ? (
				<p className="seostudio-muted" role="status">
					{ __( 'Loading this page.', 'seo-studio' ) }
				</p>
			) : null }
			{ load === 'missing' ? (
				<p>
					{ __(
						'Choose a page from the Zoop overview.',
						'seo-studio'
					) }
				</p>
			) : null }
			{ load === 'error' ? (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) : null }
			{ load === 'ready' ? (
				<>
					<p data-testid="workspace-ownership">
						{ workspaceOwnership( settings ) }
					</p>
					{ error ? (
						<Notice status="error" isDismissible={ false }>
							{ error }
						</Notice>
					) : null }
					<TextControl
						label={ __( 'Target phrase', 'seo-studio' ) }
						value={ phrase }
						onChange={ setPhrase }
						help={
							phraseMissing
								? __(
										'This page cannot be analysed until a target phrase is saved.',
										'seo-studio'
								  )
								: __(
										'The phrase this page should be understood by.',
										'seo-studio'
								  )
						}
					/>
					<p className="seostudio-actions">
						<Button
							variant="primary"
							disabled={ busy !== '' || phraseMissing }
							onClick={ () => analyse( 'content' ) }
						>
							{ busy === 'content'
								? __( 'Analysing…', 'seo-studio' )
								: __( 'Analyse page', 'seo-studio' ) }
						</Button>
						<Button
							variant="secondary"
							disabled={ busy !== '' || phraseMissing }
							onClick={ () => analyse( 'live' ) }
						>
							{ busy === 'live'
								? __( 'Checking…', 'seo-studio' )
								: __( 'Check published page', 'seo-studio' ) }
						</Button>
						<a href={ `post.php?post=${ postId }&action=edit` }>
							{ __( 'Edit page', 'seo-studio' ) }
						</a>
					</p>
					{ analysed && record ? (
						<>
							{ record.last_error ? (
								<Notice status="error" isDismissible={ false }>
									{ sprintf(
										/* translators: %s: stored analysis error. */
										__(
											'Analysis failed. %s',
											'seo-studio'
										),
										record.last_error
									) }
								</Notice>
							) : null }
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
							<h3>
								{ __( 'What needs attention', 'seo-studio' ) }
							</h3>
							{ findings.length === 0 ? (
								<p>
									{ __(
										'No outstanding on-page finding was stored.',
										'seo-studio'
									) }
								</p>
							) : (
								<ul className="seostudio-findings">
									{ findings.map( ( row ) => {
										const target = editTarget( row.id );
										return (
											<li key={ row.id }>
												{ assessmentTitle( row.id ) }
												{ row.recommendation
													? `. ${ row.recommendation }`
													: '' }{ ' ' }
												{ target.liveCheck ? (
													target.label
												) : (
													<a
														href={ editHref(
															postId,
															row.id
														) }
													>
														{ target.label }
													</a>
												) }
											</li>
										);
									} ) }
								</ul>
							) }
							<details data-section="checks">
								<summary>
									{ __( 'All on-page checks', 'seo-studio' ) }
								</summary>
								<p className="seostudio-muted">
									{ __(
										'Checks marked “Not checked in this mode” need Check published page.',
										'seo-studio'
									) }
								</p>
								<AssessmentList items={ assessments } />
							</details>
							<h3>{ __( 'Search appearance', 'seo-studio' ) }</h3>
							<p className="seostudio-muted">
								{ snippetCaption( record.render_mode || '' ) }
							</p>
							<SnippetPreview
								title={ model?.title_tag || '' }
								url={ model?.url || record.url || '' }
								description={ model?.meta_description || '' }
							/>
							<h3>{ __( 'Saved in Zoop', 'seo-studio' ) }</h3>
							<p className="seostudio-muted">
								{ socialCaption( !! settings?.publishing ) }
							</p>
							<ul className="seostudio-findings">
								<li>
									{ sprintf(
										/* translators: %s: saved SEO title, or a dash when empty. */
										__( 'SEO title: %s', 'seo-studio' ),
										seo?.title || '–'
									) }
								</li>
								<li>
									{ sprintf(
										/* translators: %s: saved description, or a dash when empty. */
										__( 'Description: %s', 'seo-studio' ),
										seo?.description || '–'
									) }
								</li>
							</ul>
							<SocialPreview
								title={ seo?.og_title || '' }
								description={ seo?.og_description || '' }
							/>
							<p>
								<a
									href={ `post.php?post=${ postId }&action=edit&seostudio_section=search` }
								>
									{ __(
										'Edit the SEO title and description',
										'seo-studio'
									) }
								</a>
							</p>
						</>
					) : (
						<p>
							{ __(
								'This page has not been analysed yet. Add a target phrase, then choose Analyse page.',
								'seo-studio'
							) }
						</p>
					) }
				</>
			) : null }
		</div>
	);
}
