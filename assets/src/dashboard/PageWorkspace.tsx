import type { KeyboardEvent } from 'react';
import { Button, Notice, TextControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	analysePost,
	fetchPage,
	fetchPages,
	fetchPostIdentity,
	fetchSeo,
	fetchSettings,
	saveSeo,
	type PageListItem,
	type PageRecord,
	type SeoFields,
	type StudioSettings,
} from '../shared/api';
import { AssessmentList } from '../shared/ui/AssessmentList';
import { assessmentTitle } from '../shared/ui/assessment-labels';
import type { ListedAssessment } from '../shared/ui/AssessmentList';
import { SnippetPreview } from '../shared/ui/SnippetPreview';
import { SocialPreview } from '../shared/ui/SocialPreview';
import { TrafficLight } from '../shared/ui/TrafficLight';
import { editHref, editTarget } from './edit-route';
import {
	snippetCaption,
	socialCaption,
	workspaceOwnership,
} from './ownership-note';
import {
	imageKind,
	imageKindLabel,
	imageModeNote,
	imageNote,
	type ReportedImage,
} from './image-report';
import { recommendationHeading, whyItMatters } from './recommendation-copy';
import { TopicPanel } from './TopicPanel';

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
type WorkspaceTab =
	| 'recommendations'
	| 'checks'
	| 'images'
	| 'topic'
	| 'search'
	| 'advanced';

function messageOf( error: unknown, fallback: string ): string {
	if ( error && typeof error === 'object' && 'message' in error ) {
		return String( ( error as { message: string } ).message );
	}
	return fallback;
}

function scoreWord( rating: string | undefined ): string {
	if ( rating === 'red' ) {
		return __( 'Needs attention', 'seo-studio' );
	}
	if ( rating === 'orange' ) {
		return __( 'Worth reviewing', 'seo-studio' );
	}
	if ( rating === 'green' ) {
		return __( 'In good shape', 'seo-studio' );
	}
	return __( 'Scored', 'seo-studio' );
}

function RecommendationCards( {
	cards,
	postId,
	openWhy,
	setOpenWhy,
}: {
	cards: ListedAssessment[];
	postId: number;
	openWhy: string;
	setOpenWhy: ( id: string ) => void;
} ) {
	if ( cards.length === 0 ) {
		return (
			<p>
				{ __(
					'No outstanding on-page finding was stored.',
					'seo-studio'
				) }
			</p>
		);
	}
	return (
		<ol className="seostudio-cards">
			{ cards.map( ( row, index ) => {
				const target = editTarget( row.id );
				const body =
					row.explanation ||
					row.recommendation ||
					assessmentTitle( row.id );
				return (
					<li className="seostudio-panel-card" key={ row.id }>
						<p className="seostudio-kicker">
							{ String( index + 1 ).padStart( 2, '0' ) }
						</p>
						<h3>{ recommendationHeading( row.id ) }</h3>
						<p>{ body }</p>
						{ row.recommendation ? (
							<p>
								<strong>
									{ __( 'Suggested action:', 'seo-studio' ) }
								</strong>{ ' ' }
								{ row.recommendation }
							</p>
						) : null }
						<p className="seostudio-card-links">
							<button
								type="button"
								onClick={ () =>
									setOpenWhy(
										openWhy === row.id ? '' : row.id
									)
								}
							>
								{ __( 'Why this matters', 'seo-studio' ) }
							</button>
							{ target.liveCheck ? (
								<span>{ target.label }</span>
							) : (
								<a href={ editHref( postId, row.id ) }>
									{ target.label }
								</a>
							) }
						</p>
						{ openWhy === row.id ? (
							<p className="seostudio-muted">
								{ whyItMatters( row.id ) }
							</p>
						) : null }
					</li>
				);
			} ) }
		</ol>
	);
}

function ImageReport( {
	images,
	renderMode,
}: {
	images: ReportedImage[];
	renderMode: string;
} ) {
	const counts: Record< string, number > = {
		html: 0,
		template: 0,
		css_background: 0,
		unverified: 0,
	};
	images.forEach( ( image ) => {
		counts[ imageKind( image ) ] += 1;
	} );
	return (
		<section>
			<h3>{ __( 'Images on this page', 'seo-studio' ) }</h3>
			<p className="seostudio-muted">{ imageModeNote( renderMode ) }</p>
			<ul className="seostudio-saved">
				<li>
					{ sprintf(
						/* translators: %d: number of HTML images. */
						__( 'HTML images: %d', 'seo-studio' ),
						counts.html
					) }
				</li>
				<li>
					{ sprintf(
						/* translators: %d: number of template images. */
						__( 'Template images: %d', 'seo-studio' ),
						counts.template
					) }
				</li>
				<li>
					{ sprintf(
						/* translators: %d: number of CSS background images. */
						__( 'CSS backgrounds: %d', 'seo-studio' ),
						counts.css_background
					) }
				</li>
				<li>
					{ sprintf(
						/* translators: %d: number of images that could not be verified. */
						__( 'Not verified: %d', 'seo-studio' ),
						counts.unverified
					) }
				</li>
			</ul>
			{ images.length === 0 ? (
				<p>
					{ __(
						'No images were found in this analysis.',
						'seo-studio'
					) }
				</p>
			) : (
				<ol className="seostudio-cards">
					{ images.map( ( image, index ) => (
						<li
							className="seostudio-panel-card"
							key={ `${ image.kind || 'image' }-${ index }` }
						>
							<p className="seostudio-kicker">
								{ imageKindLabel( imageKind( image ) ) }
							</p>
							{ image.src ? (
								<p className="seostudio-url">{ image.src }</p>
							) : null }
							<p>{ imageNote( image ) }</p>
						</li>
					) ) }
				</ol>
			) }
		</section>
	);
}

const tabs: Array< { id: WorkspaceTab; label: string } > = [
	{ id: 'recommendations', label: __( 'Recommendations', 'seo-studio' ) },
	{ id: 'checks', label: __( 'All checks', 'seo-studio' ) },
	{ id: 'images', label: __( 'Images', 'seo-studio' ) },
	{ id: 'topic', label: __( 'Topic', 'seo-studio' ) },
	{ id: 'search', label: __( 'Search appearance', 'seo-studio' ) },
	{ id: 'advanced', label: __( 'Advanced', 'seo-studio' ) },
];

/**
 * Full-width analysis for one page. It stays on the Zoop admin screen.
 */
export function PageWorkspace() {
	const postId = Number( queryValue( 'post' ) || 0 );
	const [ load, setLoad ] = useState< LoadState >( 'loading' );
	const [ record, setRecord ] = useState< PageRecord | null >( null );
	const [ item, setItem ] = useState< PageListItem | null >( null );
	const [ identity, setIdentity ] = useState( { title: '', link: '' } );
	const [ seo, setSeo ] = useState< SeoFields | null >( null );
	const [ settings, setSettings ] = useState< StudioSettings | null >( null );
	const [ phrase, setPhrase ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState< 'content' | 'live' | '' >( '' );
	const [ tab, setTab ] = useState< WorkspaceTab >( 'recommendations' );
	const [ openWhy, setOpenWhy ] = useState( '' );

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
			fetchPostIdentity( postId ),
		] )
			.then( async ( [ list, fields, studio, post ] ) => {
				if ( cancelled ) {
					return;
				}
				setSeo( fields );
				setPhrase( fields.focus_keyphrase || '' );
				setSettings( studio );
				setIdentity( post );
				const listed = list.items[ 0 ] || null;
				setItem( listed );
				if ( listed ) {
					const stored = await fetchPage( listed.page_id );
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
		if ( ! postId || ! phrase.trim() ) {
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
			setTab( 'recommendations' );
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
	const findings = assessments.filter(
		( row ) => row.status !== 'pass' && row.status !== 'not_checked'
	);
	const cards = findings.slice( 0, 3 );
	const model = record?.page_model;
	const phraseMissing = phrase.trim() === '';
	const title =
		identity.title || item?.title || __( 'Untitled', 'seo-studio' );
	const url = model?.url || item?.url || identity.link || record?.url || '';

	function onTabKey( event: KeyboardEvent< HTMLDivElement > ) {
		if ( event.key !== 'ArrowRight' && event.key !== 'ArrowLeft' ) {
			return;
		}
		event.preventDefault();
		const order = tabs.map( ( entry ) => entry.id );
		const index = order.indexOf( tab );
		const next =
			event.key === 'ArrowRight'
				? order[ ( index + 1 ) % order.length ]
				: order[ ( index - 1 + order.length ) % order.length ];
		setTab( next );
	}

	return (
		<div className="seostudio-workspace">
			{ load === 'loading' ? (
				<section className="seostudio-stage" role="status">
					<p className="seostudio-kicker">
						{ __( 'Page analysis', 'seo-studio' ) }
					</p>
					<h2>{ __( 'Loading this page.', 'seo-studio' ) }</h2>
				</section>
			) : null }
			{ load === 'missing' ? (
				<section className="seostudio-stage">
					<h2>
						{ __(
							'Choose a page from the Zoop overview.',
							'seo-studio'
						) }
					</h2>
				</section>
			) : null }
			{ load === 'error' ? (
				<section className="seostudio-stage">
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				</section>
			) : null }
			{ load === 'ready' ? (
				<>
					<p className="seostudio-crumb">
						<a href="admin.php?page=seostudio&section=pages">
							{ __( 'Pages', 'seo-studio' ) }
						</a>
						<span aria-hidden="true"> / </span>
						{ title }
					</p>
					<div className="seostudio-identity">
						<div>
							<h2>{ title }</h2>
							{ url ? (
								<p className="seostudio-url">
									<a href={ url }>{ url }</a>
								</p>
							) : null }
							<p data-testid="workspace-ownership">
								{ workspaceOwnership( settings ) }
							</p>
						</div>
						<a
							className="seostudio-text-button"
							href={ `post.php?post=${ postId }&action=edit` }
						>
							{ __( 'Edit page', 'seo-studio' ) }
						</a>
					</div>
					{ error ? (
						<Notice status="error" isDismissible={ false }>
							{ error }
						</Notice>
					) : null }
					{ analysed && record ? (
						<>
							<div className="seostudio-command">
								<TextControl
									label={ __(
										'Target phrase',
										'seo-studio'
									) }
									value={ phrase }
									onChange={ setPhrase }
									help={ __(
										'The phrase this page should be understood by.',
										'seo-studio'
									) }
								/>
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
										: __(
												'Check published page',
												'seo-studio'
										  ) }
								</Button>
							</div>
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
							<div className="seostudio-workspace-split">
								<article className="seostudio-panel-card">
									<p className="seostudio-kicker">
										{ __(
											'On-page analysis',
											'seo-studio'
										) }
									</p>
									<p
										className="seostudio-score-figure"
										data-testid="onpage-score"
									>
										{ onpage?.score }{ ' ' }
										<span>
											{ sprintf(
												/* translators: %d: points available for this page. */
												__( 'of %d', 'seo-studio' ),
												onpage?.denominator || 0
											) }
										</span>
										<span className="screen-reader-text">
											{ sprintf(
												/* translators: 1: points earned, 2: points available. */
												__(
													'%1$d of %2$d',
													'seo-studio'
												),
												onpage?.score || 0,
												onpage?.denominator || 0
											) }
										</span>
									</p>
									<p
										className="seostudio-pill"
										data-rating={
											onpage?.rating || 'orange'
										}
									>
										{ scoreWord( onpage?.rating ) }
									</p>
									<p className="seostudio-muted">
										{ __(
											'Points from the checks Zoop could score. This is not a ranking.',
											'seo-studio'
										) }
									</p>
									<div className="seostudio-statuses">
										<TrafficLight
											rating={ item?.seo_rating || null }
											label={ __( 'SEO', 'seo-studio' ) }
										/>
										<TrafficLight
											rating={
												item?.readability_rating || null
											}
											label={ __(
												'Readability',
												'seo-studio'
											) }
										/>
									</div>
								</article>
								<article className="seostudio-panel-card">
									<p className="seostudio-kicker">
										{ sprintf(
											/* translators: %d: number of recommendation cards. */
											__(
												'%d things to review',
												'seo-studio'
											),
											cards.length
										) }
									</p>
									{ cards.length === 0 ? (
										<p>
											{ __(
												'No outstanding on-page finding was stored.',
												'seo-studio'
											) }
										</p>
									) : (
										<ol className="seostudio-action-index">
											{ cards.map( ( row, index ) => (
												<li key={ row.id }>
													<span>{ index + 1 }</span>
													{ assessmentTitle(
														row.id
													) }
												</li>
											) ) }
										</ol>
									) }
									<Button
										variant="secondary"
										onClick={ () =>
											setTab( 'recommendations' )
										}
									>
										{ __(
											'Review findings',
											'seo-studio'
										) }
									</Button>
								</article>
							</div>
							<div
								className="seostudio-tabs"
								role="tablist"
								aria-label={ __(
									'Page analysis',
									'seo-studio'
								) }
								tabIndex={ 0 }
								onKeyDown={ onTabKey }
							>
								{ tabs.map( ( entry ) => (
									<button
										key={ entry.id }
										type="button"
										role="tab"
										id={ `seostudio-tab-${ entry.id }` }
										aria-selected={ tab === entry.id }
										aria-controls={ `seostudio-panel-${ entry.id }` }
										tabIndex={ tab === entry.id ? 0 : -1 }
										onClick={ () => setTab( entry.id ) }
									>
										{ entry.label }
									</button>
								) ) }
							</div>
							<div
								role="tabpanel"
								id={ `seostudio-panel-${ tab }` }
								aria-labelledby={ `seostudio-tab-${ tab }` }
								className="seostudio-tabpanel"
							>
								{ tab === 'recommendations' ? (
									<RecommendationCards
										cards={ cards }
										postId={ postId }
										openWhy={ openWhy }
										setOpenWhy={ setOpenWhy }
									/>
								) : null }
								{ tab === 'checks' ? (
									<>
										<p className="seostudio-muted">
											{ __(
												'Checks marked “Not checked in this mode” need Check published page. Open a group to read each result.',
												'seo-studio'
											) }
										</p>
										<AssessmentList items={ assessments } />
									</>
								) : null }
								{ tab === 'images' ? (
									<ImageReport
										images={ model?.images || [] }
										renderMode={ record.render_mode || '' }
									/>
								) : null }
								{ tab === 'topic' ? (
									<TopicPanel postId={ postId } />
								) : null }
								{ tab === 'search' ? (
									<>
										<h3>
											{ __(
												'Search appearance',
												'seo-studio'
											) }
										</h3>
										<p className="seostudio-muted">
											{ snippetCaption(
												record.render_mode || ''
											) }
										</p>
										<div className="seostudio-preview-row">
											<SnippetPreview
												title={ model?.title_tag || '' }
												url={ url }
												description={
													model?.meta_description ||
													''
												}
											/>
											<SnippetPreview
												title={ model?.title_tag || '' }
												url={ url }
												description={
													model?.meta_description ||
													''
												}
												viewport="mobile"
											/>
										</div>
										<h3>
											{ __(
												'Saved in Zoop',
												'seo-studio'
											) }
										</h3>
										<p className="seostudio-muted">
											{ socialCaption(
												!! settings?.publishing
											) }
										</p>
										<ul className="seostudio-saved">
											<li>
												{ sprintf(
													/* translators: %s: saved SEO title, or a dash when empty. */
													__(
														'SEO title: %s',
														'seo-studio'
													),
													seo?.title || '–'
												) }
											</li>
											<li>
												{ sprintf(
													/* translators: %s: saved description, or a dash when empty. */
													__(
														'Description: %s',
														'seo-studio'
													),
													seo?.description || '–'
												) }
											</li>
										</ul>
										<SocialPreview
											title={ seo?.og_title || '' }
											description={
												seo?.og_description || ''
											}
										/>
									</>
								) : null }
								{ tab === 'advanced' ? (
									<>
										<p className="seostudio-muted">
											{ __(
												'SEO and readability scores are saved from the editor. A website audit does not refresh them.',
												'seo-studio'
											) }
										</p>
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
										<p>
											<a
												href={ `post.php?post=${ postId }&action=edit&seostudio_section=advanced` }
											>
												{ __(
													'Open advanced fields',
													'seo-studio'
												) }
											</a>
										</p>
									</>
								) : null }
							</div>
						</>
					) : (
						<section className="seostudio-stage">
							<p className="seostudio-kicker">
								{ __( 'First analysis', 'seo-studio' ) }
							</p>
							<h2>{ __( 'Analyse this page', 'seo-studio' ) }</h2>
							<p className="seostudio-lede">
								{ __(
									'See how this page addresses a target phrase, and what is worth reviewing.',
									'seo-studio'
								) }
							</p>
							<TextControl
								label={ __( 'Target phrase', 'seo-studio' ) }
								value={ phrase }
								onChange={ setPhrase }
								help={
									phraseMissing
										? __(
												'What might someone search for to find this page? Analysis stays off until a phrase is saved.',
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
										: __(
												'Check published page',
												'seo-studio'
										  ) }
								</Button>
							</p>
							<p className="seostudio-muted">
								{ __(
									'Zoop analyses this content without changing what is published.',
									'seo-studio'
								) }
							</p>
							<p className="seostudio-muted">
								{ __(
									'This page has not been analysed yet. Add a target phrase, then choose Analyse page.',
									'seo-studio'
								) }
							</p>
						</section>
					) }
				</>
			) : null }
		</div>
	);
}
