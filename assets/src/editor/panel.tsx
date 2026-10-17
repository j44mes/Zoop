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
	const [ indexable, setIndexable ] = useState( true );
	const [ follow, setFollow ] = useState( true );
	const [ ogTitle, setOgTitle ] = useState( '' );
	const [ ogDescription, setOgDescription ] = useState( '' );
	const [ onpage, setOnpage ] = useState< OnPageScore | null >( null );
	const [ analysis, setAnalysis ] = useState< ContentAnalysis | null >(
		null
	);
	const [ live, setLive ] = useState( false );
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
				title: title || editorTitle,
				excerpt: description || editorExcerpt,
				keyphrase,
			} )
				.then( async ( next ) => {
					setModel( next );
					setOnpage( next.onpage || null );
					setLive( true );
					setSelector( next.content_selector_used || '' );
					await runWorker( next, false );
				} )
				.catch( () => {
					setLive( false );
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
			setError( __( 'Add a keyphrase first.', 'seo-studio' ) );
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
			setLive( false );
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
			robots: { index: indexable, follow },
			og_title: ogTitle,
			og_description: ogDescription,
		};
	}

	const previewTitle = model?.title_tag || title || editorTitle;
	const previewDescription =
		model?.meta_description || description || editorExcerpt;
	const previewUrl = model?.url || permalink;

	return (
		<div className="seostudio-panel">
			{ live ? (
				<p className="seostudio-live" data-testid="live-badge">
					{ __( 'Live', 'seo-studio' ) }
				</p>
			) : null }
			{ ! id ? (
				<p>
					{ __(
						'Save the draft before analysing it.',
						'seo-studio'
					) }
				</p>
			) : null }
			<TextControl
				label={ __( 'Keyphrase', 'seo-studio' ) }
				value={ keyphrase }
				onChange={ setKeyphrase }
			/>
			<TextControl
				label={ __( 'Related keyphrases', 'seo-studio' ) }
				value={ related }
				onChange={ setRelated }
			/>
			<TextControl
				label={ __( 'Synonyms', 'seo-studio' ) }
				value={ synonyms }
				onChange={ setSynonyms }
			/>
			<div className="seostudio-scores">
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
					/>
				</article>
			</div>
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
			) : null }
			<h2>{ __( 'Google', 'seo-studio' ) }</h2>
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
			<h2>{ __( 'Social', 'seo-studio' ) }</h2>
			<SocialPreview
				title={ ogTitle || previewTitle }
				description={ ogDescription || previewDescription }
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
			{ error ? (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) : null }
			<p>
				<Button
					variant="primary"
					disabled={ busy || ! id }
					onClick={ () => persist( 'content' ) }
				>
					{ __( 'Analyse now', 'seo-studio' ) }
				</Button>{ ' ' }
				<Button
					variant="secondary"
					disabled={ busy || ! id }
					onClick={ () => persist( 'live' ) }
				>
					{ __( 'Verify live', 'seo-studio' ) }
				</Button>
			</p>
			<p>
				{ __( 'Render:', 'seo-studio' ) } { mode }
				{ selector
					? `. ${ __( 'Selector:', 'seo-studio' ) } ${ selector }`
					: '' }
				{ '. ' }
				{ blockEditor
					? __( 'Block editor', 'seo-studio' )
					: __( 'Classic editor', 'seo-studio' ) }
			</p>
			<p>
				{ __(
					'Scores use the metadata a crawler would receive.',
					'seo-studio'
				) }
			</p>
		</div>
	);
}

function splitList( value: string ): string[] {
	return value
		.split( ',' )
		.map( ( part ) => part.trim() )
		.filter( ( part ) => part !== '' );
}
