import {
	Button,
	CheckboxControl,
	Notice,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { createRoot, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	cancelAudit,
	fetchAudit,
	fetchPages,
	fetchSettings,
	startAudit,
	type AuditRun,
	type PageList,
	type PageListItem,
	type StudioSettings,
} from '../shared/api';
import { assessmentTitle } from '../shared/ui/assessment-labels';
import { RunProgress } from '../shared/ui/RunProgress';
import { TrafficLight } from '../shared/ui/TrafficLight';
import '../shared/ui/style.css';
import {
	isPageWorkspace,
	PageWorkspace,
	pageWorkspaceUrl,
} from './PageWorkspace';
import { ArchitecturePanel } from './ArchitecturePanel';
import { SettingsPanel } from './settings/SettingsPanel';

export function Dashboard() {
	const [ list, setList ] = useState< PageList | null >( null );
	const [ suggestions, setSuggestions ] = useState< PageListItem[] >( [] );
	const [ settings, setSettings ] = useState< StudioSettings | null >( null );
	const [ search, setSearch ] = useState( '' );
	const [ orderby, setOrderby ] = useState( 'last_rendered_at' );
	const [ order, setOrder ] = useState( 'desc' );
	const [ rating, setRating ] = useState( '' );
	const [ postType, setPostType ] = useState( '' );
	const [ scoreMin, setScoreMin ] = useState( '' );
	const [ scoreMax, setScoreMax ] = useState( '' );
	const [ hasKeyphrase, setHasKeyphrase ] = useState( false );
	const [ page, setPage ] = useState( 1 );
	const [ notice, setNotice ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ view, setView ] = useState<
		'overview' | 'pages' | 'settings' | 'architecture'
	>( sectionView );
	const [ run, setRun ] = useState< AuditRun | null >( null );

	useEffect( () => {
		fetchSettings()
			.then( setSettings )
			.catch( () => undefined );
	}, [] );

	useEffect( () => {
		let cancelled = false;
		const query: Record< string, string > = {
			page: String( page ),
			per_page: '20',
			orderby,
			order,
		};
		if ( search ) {
			query.search = search;
		}
		if ( rating ) {
			query.rating = rating;
		}
		if ( postType.trim() ) {
			query.post_type = postType.trim();
		}
		if ( /^\d+$/.test( scoreMin ) ) {
			query.score_min = scoreMin;
		}
		if ( /^\d+$/.test( scoreMax ) ) {
			query.score_max = scoreMax;
		}
		if ( hasKeyphrase ) {
			query.has_keyphrase = '1';
		}
		fetchPages( query )
			.then( ( next ) => {
				if ( cancelled ) {
					return;
				}
				setList( next );
				setError( '' );
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setError(
						__( 'The page list could not be loaded.', 'seo-studio' )
					);
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [
		search,
		rating,
		postType,
		scoreMin,
		scoreMax,
		hasKeyphrase,
		orderby,
		order,
		page,
		run?.status,
	] );

	useEffect( () => {
		fetchPages( {
			per_page: '3',
			orderby: 'score',
			order: 'asc',
			page: '1',
			score_min: '0',
		} )
			.then( ( next ) => setSuggestions( next.items.slice( 0, 3 ) ) )
			.catch( () => setSuggestions( [] ) );
	}, [ run?.status, list?.total ] );

	useEffect( () => {
		if ( ! run?.run_id ) {
			return;
		}
		if ( [ 'done', 'failed', 'cancelled' ].includes( run.status || '' ) ) {
			return;
		}
		const timer = window.setInterval( () => {
			fetchAudit( run.run_id )
				.then( setRun )
				.catch( () => undefined );
		}, 2000 );
		return () => window.clearInterval( timer );
	}, [ run?.run_id, run?.status ] );

	async function reanalyse() {
		setNotice( '' );
		try {
			const started = await startAudit();
			setRun( started );
			setNotice( __( 'Audit queued.', 'seo-studio' ) );
		} catch ( caught ) {
			const message =
				caught && typeof caught === 'object' && 'message' in caught
					? String( ( caught as { message: string } ).message )
					: __( 'The site audit could not be queued.', 'seo-studio' );
			setNotice( message );
		}
	}

	async function cancel() {
		if ( ! run?.run_id ) {
			return;
		}
		try {
			await cancelAudit( run.run_id );
			setRun( { ...run, status: 'cancelled' } );
		} catch ( caught ) {
			const message =
				caught && typeof caught === 'object' && 'message' in caught
					? String( ( caught as { message: string } ).message )
					: __( 'The audit could not be cancelled.', 'seo-studio' );
			setNotice( message );
		}
	}

	if ( isPageWorkspace() ) {
		return (
			<div className="seostudio-dashboard">
				<AppBar
					current="pages"
					mode={ shortMode( settings ) }
					onNavigate={ () => undefined }
				/>
				<PageWorkspace />
			</div>
		);
	}

	const summary = list?.summary;
	const items = list?.items ?? [];
	const modeLine = metadataModeLine( settings );

	return (
		<div className="seostudio-dashboard">
			<AppBar current={ view } mode={ modeLine } onNavigate={ setView } />
			{ view === 'settings' ? <SettingsPanel /> : null }
			{ view === 'architecture' ? <ArchitecturePanel /> : null }
			{ view !== 'settings' && view !== 'architecture' ? (
				<>
					{ view === 'overview' && summary ? (
						<div className="seostudio-summary">
							<article>
								<h2>{ __( 'Stored', 'seo-studio' ) }</h2>
								<p>{ summary.analysed }</p>
							</article>
							<article>
								<h2>{ __( 'Scored', 'seo-studio' ) }</h2>
								<p>{ summary.scored ?? 0 }</p>
							</article>
							<article>
								<h2>
									{ __( 'Not analysed yet', 'seo-studio' ) }
								</h2>
								<p>{ summary.unscored ?? 0 }</p>
							</article>
							<article>
								<h2>{ __( 'Errors', 'seo-studio' ) }</h2>
								<p>{ summary.failed ?? 0 }</p>
							</article>
						</div>
					) : null }
					{ view === 'overview' ? (
						<>
							<p className="seostudio-muted">
								{ summary?.average === null ||
								summary?.average === undefined
									? __(
											'No on-page average yet.',
											'seo-studio'
									  )
									: sprintf(
											/* translators: %d: average on-page score as a percentage of the available points. */
											__(
												'Average on-page, as a percentage of the available points: %d%%.',
												'seo-studio'
											),
											summary.average
									  ) }{ ' ' }
								{ sprintf(
									/* translators: 1: poor pages, 2: ok pages, 3: good pages. */
									__(
										'Poor %1$d, Ok %2$d, Good %3$d.',
										'seo-studio'
									),
									summary?.red ?? 0,
									summary?.orange ?? 0,
									summary?.green ?? 0
								) }
							</p>
							<h2>
								{ __( 'Suggested next steps', 'seo-studio' ) }
							</h2>
							<p className="seostudio-muted">
								{ __(
									'These are the lowest saved on-page results. They are not a ranking forecast.',
									'seo-studio'
								) }
							</p>
							<div className="seostudio-suggestions">
								{ suggestions.length === 0 ? (
									<p>
										{ __(
											'No stored pages yet. Open a page and choose Analyse page.',
											'seo-studio'
										) }
									</p>
								) : (
									suggestions.map( ( item ) => (
										<article
											className="seostudio-suggestion"
											key={ item.page_id }
										>
											<h3>
												{ item.title ||
													__(
														'Untitled',
														'seo-studio'
													) }
											</h3>
											<p>{ suggestionText( item ) }</p>
											<p>
												<a
													href={ pageWorkspaceUrl(
														item.object_id
													) }
												>
													{ __(
														'Open analysis',
														'seo-studio'
													) }
												</a>
											</p>
										</article>
									) )
								) }
							</div>
						</>
					) : null }
					<h2>
						{ view === 'pages'
							? __( 'Pages', 'seo-studio' )
							: __( 'All pages', 'seo-studio' ) }
					</h2>
					<p className="seostudio-actions">
						<Button variant="primary" onClick={ reanalyse }>
							{ __( 'Run website audit', 'seo-studio' ) }
						</Button>
						{ run &&
						( run.status === 'queued' ||
							run.status === 'running' ) ? (
							<Button variant="secondary" onClick={ cancel }>
								{ __( 'Cancel audit', 'seo-studio' ) }
							</Button>
						) : null }
					</p>
					<p className="seostudio-muted">
						{ __(
							'A website audit saves on-page scores. SEO and readability scores are saved when you analyse a page in the editor.',
							'seo-studio'
						) }
					</p>
					{ notice ? <p role="status">{ notice }</p> : null }
					{ run ? (
						<RunProgress
							processed={ run.processed ?? 0 }
							total={ run.total ?? 0 }
							message={ auditMessage( run ) }
						/>
					) : null }
					<div className="seostudio-toolbar">
						<TextControl
							label={ __( 'Search', 'seo-studio' ) }
							value={ search }
							onChange={ ( value ) => {
								setSearch( value );
								setPage( 1 );
							} }
						/>
						<SelectControl
							label={ __( 'Sort', 'seo-studio' ) }
							value={ orderby }
							options={ [
								{
									label: __( 'Last analysed', 'seo-studio' ),
									value: 'last_rendered_at',
								},
								{
									label: __( 'On-page score', 'seo-studio' ),
									value: 'score',
								},
								{
									label: __( 'Title', 'seo-studio' ),
									value: 'title',
								},
							] }
							onChange={ ( value ) => {
								setOrderby( value );
								setPage( 1 );
							} }
						/>
						<SelectControl
							label={ __( 'Direction', 'seo-studio' ) }
							value={ order }
							options={ [
								{
									label: __( 'Descending', 'seo-studio' ),
									value: 'desc',
								},
								{
									label: __( 'Ascending', 'seo-studio' ),
									value: 'asc',
								},
							] }
							onChange={ ( value ) => {
								setOrder( value );
								setPage( 1 );
							} }
						/>
						<SelectControl
							label={ __( 'On-page rating', 'seo-studio' ) }
							value={ rating }
							options={ [
								{ label: __( 'All', 'seo-studio' ), value: '' },
								{
									label: __( 'Poor', 'seo-studio' ),
									value: 'red',
								},
								{
									label: __( 'Ok', 'seo-studio' ),
									value: 'orange',
								},
								{
									label: __( 'Good', 'seo-studio' ),
									value: 'green',
								},
								{
									label: __(
										'Not analysed yet',
										'seo-studio'
									),
									value: 'grey',
								},
							] }
							onChange={ ( value ) => {
								setRating( value );
								setPage( 1 );
							} }
						/>
					</div>
					<details className="seostudio-advanced-filters">
						<summary>
							{ __( 'Advanced filters', 'seo-studio' ) }
						</summary>
						<div className="seostudio-toolbar">
							<TextControl
								label={ __( 'Post type', 'seo-studio' ) }
								value={ postType }
								help={ __(
									'For example, post or page.',
									'seo-studio'
								) }
								onChange={ ( value ) => {
									setPostType( value );
									setPage( 1 );
								} }
							/>
							<TextControl
								label={ __( 'Minimum score', 'seo-studio' ) }
								value={ scoreMin }
								onChange={ ( value ) => {
									setScoreMin( value );
									setPage( 1 );
								} }
							/>
							<TextControl
								label={ __( 'Maximum score', 'seo-studio' ) }
								value={ scoreMax }
								onChange={ ( value ) => {
									setScoreMax( value );
									setPage( 1 );
								} }
							/>
							<CheckboxControl
								label={ __(
									'Has a target phrase',
									'seo-studio'
								) }
								checked={ hasKeyphrase }
								onChange={ ( value ) => {
									setHasKeyphrase( value );
									setPage( 1 );
								} }
							/>
						</div>
					</details>
					{ error ? (
						<Notice status="error" isDismissible={ false }>
							{ error }
						</Notice>
					) : null }
					{ list && list.total === 0 ? (
						<p>
							{ search ||
							rating ||
							postType.trim() ||
							scoreMin ||
							scoreMax ||
							hasKeyphrase
								? __(
										'No stored pages match these filters.',
										'seo-studio'
								  )
								: __(
										'No pages are stored yet. Open a page and choose Analyse page.',
										'seo-studio'
								  ) }
						</p>
					) : (
						<div className="seostudio-table-wrap">
							<table className="seostudio-table">
								<thead>
									<tr>
										<th scope="col">
											{ __( 'Page', 'seo-studio' ) }
										</th>
										<th scope="col">
											{ __( 'On-page', 'seo-studio' ) }
										</th>
										<th scope="col">
											{ __(
												'Editor scores',
												'seo-studio'
											) }
										</th>
										<th scope="col">
											{ __(
												'Main finding',
												'seo-studio'
											) }
										</th>
										<th scope="col">
											{ __( 'Action', 'seo-studio' ) }
										</th>
									</tr>
								</thead>
								<tbody>
									{ items.map( ( item ) => (
										<DashboardRow
											key={ item.page_id }
											item={ item }
										/>
									) ) }
								</tbody>
							</table>
						</div>
					) }
					{ list && list.pages > 1 ? (
						<p className="seostudio-actions">
							<Button
								variant="secondary"
								disabled={ page <= 1 }
								onClick={ () => setPage( page - 1 ) }
							>
								{ __( 'Previous', 'seo-studio' ) }
							</Button>
							<Button
								variant="secondary"
								disabled={ page >= list.pages }
								onClick={ () => setPage( page + 1 ) }
							>
								{ __( 'Next', 'seo-studio' ) }
							</Button>
						</p>
					) : null }
				</>
			) : null }
		</div>
	);
}

function sectionView(): 'overview' | 'pages' | 'settings' | 'architecture' {
	const section = new URLSearchParams( window.location.search ).get(
		'section'
	);
	if (
		section === 'pages' ||
		section === 'settings' ||
		section === 'architecture'
	) {
		return section;
	}
	return 'overview';
}

function AppBar( {
	current,
	mode,
	onNavigate,
}: {
	current: 'overview' | 'pages' | 'settings' | 'architecture';
	mode: string;
	onNavigate: (
		next: 'overview' | 'pages' | 'settings' | 'architecture'
	) => void;
} ) {
	const items: Array< {
		id: 'overview' | 'pages' | 'settings' | 'architecture';
		label: string;
	} > = [
		{ id: 'overview', label: __( 'Overview', 'seo-studio' ) },
		{ id: 'pages', label: __( 'Pages', 'seo-studio' ) },
		{ id: 'architecture', label: __( 'Architecture', 'seo-studio' ) },
		{ id: 'settings', label: __( 'Settings', 'seo-studio' ) },
	];
	return (
		<div className="seostudio-appbar">
			<h1>{ __( 'Zoop', 'seo-studio' ) }</h1>
			<nav aria-label={ __( 'Zoop sections', 'seo-studio' ) }>
				{ items.map( ( item ) => (
					<button
						key={ item.id }
						type="button"
						aria-current={
							current === item.id ? 'page' : undefined
						}
						onClick={ () => {
							if ( isPageWorkspace() ) {
								const target =
									item.id === 'overview'
										? 'admin.php?page=seostudio'
										: `admin.php?page=seostudio&section=${ item.id }`;
								window.location.href = target;
								return;
							}
							onNavigate( item.id );
						} }
					>
						{ item.label }
					</button>
				) ) }
			</nav>
			<p>{ mode }</p>
		</div>
	);
}

function shortMode( settings: StudioSettings | null ): string {
	if ( settings?.publishing ) {
		return __( 'Zoop manages metadata', 'seo-studio' );
	}
	if ( settings?.detected?.name ) {
		return settings.detected.name;
	}
	return __( 'Analysis only', 'seo-studio' );
}

function metadataModeLine( settings: StudioSettings | null ): string {
	if ( settings?.publishing ) {
		return __(
			'Zoop manages the supported search and social tags.',
			'seo-studio'
		);
	}
	if ( settings?.detected?.name ) {
		return sprintf(
			/* translators: %s: name of the active SEO plugin. */
			__(
				'%s controls the live SEO metadata. Zoop is in Analysis-only mode.',
				'seo-studio'
			),
			settings.detected.name
		);
	}
	return __(
		'Analysis-only. Zoop saves its fields and does not print them.',
		'seo-studio'
	);
}

function suggestionText( item: PageListItem ): string {
	if ( item.top_issue ) {
		return assessmentTitle( item.top_issue );
	}
	if ( item.onpage.score === null ) {
		return __( 'This page has not been analysed yet.', 'seo-studio' );
	}
	return __( 'No outstanding on-page finding was stored.', 'seo-studio' );
}

function mainFinding( item: PageListItem ): string {
	if ( item.last_error ) {
		return item.last_error;
	}
	if ( item.top_issue ) {
		return assessmentTitle( item.top_issue );
	}
	return '';
}

function auditMessage( run: AuditRun ): string {
	const status = run.status || 'queued';
	if ( status === 'done' && ( run.failed || 0 ) > 0 ) {
		return __( 'Completed with failures.', 'seo-studio' );
	}
	const labels: Record< string, string > = {
		queued: __( 'Queued.', 'seo-studio' ),
		running: __( 'Running.', 'seo-studio' ),
		done: __( 'Completed.', 'seo-studio' ),
		cancelled: __( 'Cancelled.', 'seo-studio' ),
		failed: __( 'Failed.', 'seo-studio' ),
	};
	return labels[ status ] || status;
}

function DashboardRow( { item }: { item: PageListItem } ) {
	const score =
		item.onpage.score === null || item.onpage.denominator === null
			? __( 'Not analysed yet', 'seo-studio' )
			: `${ item.onpage.score } / ${ item.onpage.denominator }`;
	return (
		<tr>
			<td>
				{ item.edit_url ? (
					<a href={ item.edit_url }>{ item.title }</a>
				) : (
					item.title
				) }
				<div className="seostudio-muted">
					{ item.keyphrase || __( 'No target phrase', 'seo-studio' ) }
				</div>
			</td>
			<td>{ score }</td>
			<td>
				<TrafficLight
					rating={ item.seo_rating }
					label={ __( 'SEO', 'seo-studio' ) }
				/>{ ' ' }
				<TrafficLight
					rating={ item.readability_rating }
					label={ __( 'Readability', 'seo-studio' ) }
				/>
			</td>
			<td>{ mainFinding( item ) }</td>
			<td>
				<a href={ pageWorkspaceUrl( item.object_id ) }>
					{ __( 'Open analysis', 'seo-studio' ) }
				</a>
			</td>
		</tr>
	);
}

const mount = document.getElementById( 'seostudio-dashboard' );
if ( mount ) {
	createRoot( mount ).render( <Dashboard /> );
}
