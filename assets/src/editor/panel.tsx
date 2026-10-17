import {
	Button,
	CheckboxControl,
	Notice,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { metadataNote, type MetadataOwner } from './metadata-note';
export type { MetadataOwner } from './metadata-note';
import { scoreInWorker } from '../analysis/client';
import { paperFromModel } from '../analysis/paper';
import type { ContentAnalysis } from '../analysis/score-paper';
import { ContentResults } from '../analysis/results';
import {
	analysePost,
	fetchSeo,
	keyphraseUsage,
	previewModel,
	saveContentScores,
	saveSeo,
	type OnPageScore,
	type PageModelResponse,
} from '../shared/api';
import { AssessmentList } from '../shared/ui/AssessmentList';
import { assessmentTitle } from '../shared/ui/assessment-labels';
import { CategoryBar } from '../shared/ui/CategoryBar';
import { ScoreRing } from '../shared/ui/ScoreRing';
import { SnippetPreview } from '../shared/ui/SnippetPreview';
import { SocialPreview } from '../shared/ui/SocialPreview';
import { VariableInput } from '../shared/ui/VariableInput';
import '../shared/ui/style.css';

interface PanelProps {
	postId: number;
	blockEditor: boolean;
	workerUrl: string;
	permalink: string;
	metadata?: MetadataOwner;
}

/**
 * Read a classic-editor field, used only when the block editor store is absent.
 *
 * @param id Element ID of the classic editor field.
 */
function classicField( id: string ): string | undefined {
	const field = document.getElementById( id ) as
		| HTMLInputElement
		| HTMLTextAreaElement
		| null;
	return field ? field.value : undefined;
}

function messageOf( error: unknown ): string {
	if ( error && typeof error === 'object' && 'message' in error ) {
		return String( ( error as { message: string } ).message );
	}
	return __( 'The request failed.', 'seo-studio' );
}

export function Panel( {
	postId,
	blockEditor,
	workerUrl,
	permalink,
	metadata,
}: PanelProps ) {
	const editorContent = useSelect( ( select ): string => {
		const editor = select( 'core/editor' ) as
			| { getEditedPostContent?: () => string }
			| undefined;
		return editor?.getEditedPostContent?.() ?? '';
	}, [] );
	const editorTitle = useSelect( ( select ): string => {
		const editor = select( 'core/editor' ) as
			| { getEditedPostAttribute?: ( key: string ) => string }
			| undefined;
		return editor?.getEditedPostAttribute?.( 'title' ) ?? '';
	}, [] );
	const editorExcerpt = useSelect( ( select ): string => {
		const editor = select( 'core/editor' ) as
			| { getEditedPostAttribute?: ( key: string ) => string }
			| undefined;
		return editor?.getEditedPostAttribute?.( 'excerpt' ) ?? '';
	}, [] );
	const editorId = useSelect( ( select ): number => {
		const editor = select( 'core/editor' ) as
			| { getCurrentPostId?: () => number }
			| undefined;
		return editor?.getCurrentPostId?.() ?? 0;
	}, [] );
	const id = postId || editorId;
	const [ keyphrase, setKeyphrase ] = useState( '' );
	const [ related, setRelated ] = useState( '' );
	const [ synonyms, setSynonyms ] = useState( '' );
	const [ title, setTitle ] = useState( '' );
	const [ description, setDescription ] = useState( '' );
	const [ canonical, setCanonical ] = useState( '' );
	const [ videoUrl, setVideoUrl ] = useState( '' );
	const [ isNews, setIsNews ] = useState( false );
	const [ indexable, setIndexable ] = useState( true );
	const [ follow, setFollow ] = useState( true );
	const [ ogTitle, setOgTitle ] = useState( '' );
	const [ ogDescription, setOgDescription ] = useState( '' );
	const [ onpage, setOnpage ] = useState< OnPageScore | null >( null );
	const [ analysis, setAnalysis ] = useState< ContentAnalysis | null >(
		null
	);
	const [ preview, setPreview ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ workerDown, setWorkerDown ] = useState( false );
	const [ selector, setSelector ] = useState( '' );
	const [ mode, setMode ] = useState( 'content' );
	const [ model, setModel ] = useState< PageModelResponse | null >( null );

	useEffect( () => {
		if ( ! id ) {
			return;
		}
		fetchSeo( id )
			.then( ( seo ) => {
				setKeyphrase( seo.focus_keyphrase || '' );
				setRelated(
					( seo.related_keyphrases || [] )
						.map( ( item ) => item.keyphrase )
						.join( ', ' )
				);
				setSynonyms( ( seo.synonyms || [] ).join( ', ' ) );
				setTitle( seo.title || '' );
				setDescription( seo.description || '' );
				setCanonical( seo.canonical || '' );
				setVideoUrl( seo.video_url || '' );
				setIsNews(
					seo.is_news === true ||
						seo.is_news === 1 ||
						seo.is_news === '1'
				);
				setIndexable( seo.robots?.index !== false );
				setFollow( seo.robots?.follow !== false );
				setOgTitle( seo.og_title || '' );
				setOgDescription( seo.og_description || '' );
			} )
			.catch( () =>
				setError(
					__( 'The SEO fields could not be loaded.', 'seo-studio' )
				)
			);
	}, [ id ] );

	const runWorker = useCallback(
		async ( next: PageModelResponse, persistPageId: number | false ) => {
			if ( ! workerUrl ) {
				setWorkerDown( true );
				return;
			}
			try {
				const usage = await keyphraseUsage( keyphrase );
				const used = ( usage.posts || [] )
					.map( ( post ) => post.post_id )
					.filter( ( post ) => post !== id );
				const result = await scoreInWorker(
					workerUrl,
					paperFromModel( next, {
						keyphrase,
						related: splitList( related ),
						synonyms: splitList( synonyms ),
						usedElsewhere: used,
					} )
				);
				setAnalysis( result );
				setWorkerDown( false );
				if ( persistPageId ) {
					await saveContentScores( persistPageId, {
						keyphrase,
						seo: result.seo,
						readability: result.readability,
					} );
				}
			} catch ( caught ) {
				setWorkerDown( true );
				setError( messageOf( caught ) );
			}
		},
		[ id, keyphrase, related, synonyms, workerUrl ]
	);

	useEffect( () => {
		if ( ! id || ! keyphrase ) {
			return;
		}
		const timer = window.setTimeout( () => {
			const html =
				editorContent ||
				(
					document.getElementById(
						'content'
					) as HTMLTextAreaElement | null
				 )?.value ||
				'';
			previewModel( id, {
				content_html: html,
				title: blockEditor ? editorTitle : classicField( 'title' ),
				excerpt: blockEditor
					? editorExcerpt
					: classicField( 'excerpt' ),
				seo_title: title,
				seo_description: description,
				keyphrase,
			} )
				.then( async ( next ) => {
					setModel( next );
					setOnpage( next.onpage || null );
					setPreview( true );
					setSelector( next.content_selector_used || '' );
					await runWorker( next, false );
				} )
				.catch( () => {
					setPreview( false );
				} );
		}, 500 );
		return () => window.clearTimeout( timer );
	}, [
		id,
		keyphrase,
		title,
		description,
		editorContent,
		editorTitle,
		editorExcerpt,
		blockEditor,
		runWorker,
	] );

	async function persist( nextMode: 'content' | 'live' ) {
		if ( ! id ) {
			setError(
				__( 'Save the draft before analysing it.', 'seo-studio' )
			);
			return;
		}
		if ( ! keyphrase.trim() ) {
			setError( __( 'Add a target phrase first.', 'seo-studio' ) );
			return;
		}
		setBusy( true );
		setError( '' );
		try {
			await saveSeo( id, seoPayload() );
			const record = await analysePost( id, {
				mode: nextMode,
				keyphrase,
			} );
			const score = record.scores.find(
				( row ) => row.engine === 'onpage'
			);
			setOnpage( ( score?.details as OnPageScore ) || null );
			setModel( record.page_model );
			setMode( record.render_mode || nextMode );
			setSelector( record.content_selector || '' );
			setPreview( false );
			if ( record.last_error ) {
				setError( String( record.last_error ) );
			}
			await runWorker( record.page_model, record.page_id );
		} catch ( caught ) {
			setError( messageOf( caught ) );
		} finally {
			setBusy( false );
		}
	}

	function seoPayload() {
		return {
			focus_keyphrase: keyphrase,
			related_keyphrases: splitList( related ).map( ( phrase ) => ( {
				keyphrase: phrase,
				synonyms: [],
			} ) ),
			synonyms: splitList( synonyms ),
			title,
			description,
			canonical,
			video_url: videoUrl,
			is_news: isNews,
			robots: { index: indexable, follow },
			og_title: ogTitle,
			og_description: ogDescription,
		};
	}

	const home =
		metadata?.publishesOwn && metadata.isHomepage
			? metadata.homepage
			: null;
	const previewTitle = model
		? model.title_tag ?? ''
		: home?.title_tag ?? ( title || editorTitle );
	const previewDescription = model
		? model.meta_description ?? ''
		: home?.meta_description ?? ( description || editorExcerpt );
	const previewUrl = home?.canonical || model?.url || permalink;

	const findings = ( onpage?.assessments || [] ).filter(
		( row ) => row.status !== 'pass' && row.status !== 'not_checked'
	);
	const analysisState = analysisStateOf( preview, mode, onpage );
	const analysisLabel = analysisLabelOf( analysisState );

	return (
		<div className="seostudio-panel">
			<p className="seostudio-status" data-state={ analysisState }>
				{ analysisLabel }
			</p>
			{ preview ? (
				<p className="seostudio-live" data-testid="preview-badge">
					{ __( 'Unsaved preview', 'seo-studio' ) }
				</p>
			) : null }
			<p data-testid="metadata-note">{ metadataNote( metadata ) }</p>
			{ ! id ? (
				<p>
					{ __(
						'Save the draft before analysing it.',
						'seo-studio'
					) }
				</p>
			) : null }
			<TextControl
				label={ __( 'Target phrase', 'seo-studio' ) }
				value={ keyphrase }
				onChange={ setKeyphrase }
				help={ __(
					'The phrase this page should be understood by.',
					'seo-studio'
				) }
			/>
			<p className="seostudio-actions">
				<Button
					variant="primary"
					disabled={ busy || ! id }
					onClick={ () => persist( 'content' ) }
				>
					{ busy
						? __( 'Analysing…', 'seo-studio' )
						: __( 'Analyse page', 'seo-studio' ) }
				</Button>
				<Button
					variant="secondary"
					disabled={ busy || ! id }
					onClick={ () => persist( 'live' ) }
				>
					{ __( 'Check published page', 'seo-studio' ) }
				</Button>
			</p>
			{ error ? (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) : null }
			<div className="seostudio-summary">
				<article className="seostudio-card">
					<h2>{ __( 'On-page', 'seo-studio' ) }</h2>
					<ScoreRing
						value={ onpage ? onpage.earned : null }
						denominator={ onpage ? onpage.denominator : null }
					/>
				</article>
				<article className="seostudio-card">
					<h2>{ __( 'Content analysis', 'seo-studio' ) }</h2>
					<ContentResults
						analysis={ analysis }
						unavailable={ workerDown }
						compact
					/>
				</article>
			</div>
			{ id ? (
				<p>
					<a
						href={ `admin.php?page=seostudio&view=page&post=${ id }` }
					>
						{ __( 'Open full analysis', 'seo-studio' ) }
					</a>
				</p>
			) : null }
			{ findings.length > 0 ? (
				<>
					<h2>{ __( 'Important findings', 'seo-studio' ) }</h2>
					<ul className="seostudio-findings">
						{ findings.slice( 0, 3 ).map( ( row ) => (
							<li key={ row.id }>
								{ assessmentTitle( row.id ) }
								{ row.recommendation
									? `. ${ row.recommendation }`
									: '' }
							</li>
						) ) }
					</ul>
				</>
			) : null }
			<details>
				<summary>{ __( 'More phrases', 'seo-studio' ) }</summary>
				<TextControl
					label={ __( 'Related phrases', 'seo-studio' ) }
					value={ related }
					onChange={ setRelated }
					help={ __( 'Separate phrases with commas.', 'seo-studio' ) }
				/>
				<TextControl
					label={ __( 'Synonyms', 'seo-studio' ) }
					value={ synonyms }
					onChange={ setSynonyms }
					help={ __(
						'Separate synonyms with commas.',
						'seo-studio'
					) }
				/>
			</details>
			<details>
				<summary>{ __( 'All on-page checks', 'seo-studio' ) }</summary>
				{ onpage
					? Object.entries( onpage.categories || {} ).map(
							( [ name, bar ] ) => (
								<CategoryBar
									key={ name }
									label={ name }
									earned={ bar.earned }
									max={ bar.max }
								/>
							)
					  )
					: null }
				{ onpage ? (
					<AssessmentList items={ onpage.assessments || [] } />
				) : (
					<p>
						{ __(
							'Analyse the page to see the checks.',
							'seo-studio'
						) }
					</p>
				) }
				<p className="seostudio-muted">
					{ __( 'Render:', 'seo-studio' ) } { mode }
					{ selector
						? `. ${ __( 'Selector:', 'seo-studio' ) } ${ selector }`
						: '' }
					{ '. ' }
					{ blockEditor
						? __( 'Block editor', 'seo-studio' )
						: __( 'Classic editor', 'seo-studio' ) }
				</p>
			</details>
			<details>
				<summary>{ __( 'Search appearance', 'seo-studio' ) }</summary>
				{ metadata?.publishesOwn && metadata.isHomepage ? (
					<Notice status="info" isDismissible={ false }>
						{ metadataNote( metadata ) }
					</Notice>
				) : null }
				<SnippetPreview
					title={ previewTitle }
					url={ previewUrl }
					description={ previewDescription }
				/>
				<SnippetPreview
					title={ previewTitle }
					url={ previewUrl }
					description={ previewDescription }
					viewport="mobile"
				/>
				<VariableInput
					id="seostudio-title"
					label={ __( 'SEO title', 'seo-studio' ) }
					value={ title }
					onChange={ setTitle }
					hint={ `${ title.length } / 300` }
				/>
				<VariableInput
					id="seostudio-description"
					label={ __( 'Description', 'seo-studio' ) }
					value={ description }
					onChange={ setDescription }
					multiline
					hint={ `${ description.length }` }
				/>
				<TextControl
					label={ __( 'Canonical', 'seo-studio' ) }
					value={ canonical }
					onChange={ setCanonical }
				/>
				<CheckboxControl
					label={ __( 'Index', 'seo-studio' ) }
					checked={ indexable }
					onChange={ setIndexable }
				/>
				<CheckboxControl
					label={ __( 'Follow', 'seo-studio' ) }
					checked={ follow }
					onChange={ setFollow }
				/>
			</details>
			<details>
				<summary>{ __( 'Social appearance', 'seo-studio' ) }</summary>
				<SocialPreview
					title={ ogTitle || previewTitle }
					description={ ogDescription || previewDescription }
				/>
				<TextControl
					label={ __( 'Social title', 'seo-studio' ) }
					value={ ogTitle }
					onChange={ setOgTitle }
				/>
				<TextareaControl
					label={ __( 'Social description', 'seo-studio' ) }
					value={ ogDescription }
					onChange={ setOgDescription }
				/>
			</details>
			<details>
				<summary>{ __( 'Advanced', 'seo-studio' ) }</summary>
				<TextControl
					label={ __( 'Video URL', 'seo-studio' ) }
					value={ videoUrl }
					onChange={ setVideoUrl }
					help={ __(
						'Adds a VideoObject node when Zoop is printing tags.',
						'seo-studio'
					) }
				/>
				<CheckboxControl
					label={ __( 'News article', 'seo-studio' ) }
					checked={ isNews }
					onChange={ setIsNews }
					help={ __(
						'Prints NewsArticle instead of Article when Zoop is printing tags.',
						'seo-studio'
					) }
				/>
			</details>
		</div>
	);
}

function analysisStateOf(
	preview: boolean,
	mode: string,
	onpage: OnPageScore | null
): string {
	if ( preview ) {
		return 'preview';
	}
	if ( mode === 'live' ) {
		return 'live';
	}
	if ( onpage ) {
		return 'saved';
	}
	return 'empty';
}

function analysisLabelOf( state: string ): string {
	if ( state === 'preview' ) {
		return __( 'Unsaved preview', 'seo-studio' );
	}
	if ( state === 'live' ) {
		return __( 'Checked the published page', 'seo-studio' );
	}
	if ( state === 'saved' ) {
		return __( 'Saved analysis', 'seo-studio' );
	}
	return __( 'Not analysed yet', 'seo-studio' );
}

function splitList( value: string ): string[] {
	return value
		.split( ',' )
		.map( ( part ) => part.trim() )
		.filter( ( part ) => part !== '' );
}
