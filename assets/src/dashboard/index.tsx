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
	analysePost,
	cancelAudit,
	fetchAudit,
	fetchPages,
	dismissLegacyScoreNotice,
	dismissRulesetNotice,
	fetchCapabilities,
	fetchSettings,
	type CapabilityManifest,
	startAudit,
	type AuditRun,
	type PageList,
	type PageListItem,
	type StudioSettings,
} from '../shared/api';
import { assessmentTitle } from '../shared/ui/assessment-labels';
import {
	dashboardFirstRun,
	pagesListEmpty,
	suggestionMessage,
	type SuggestionStatus,
} from './list-empty';
import { scoreStateLabel, suggestionForState } from './score-state';
import { RunProgress } from '../shared/ui/RunProgress';
import { EvidenceLights } from '../shared/ui/EvidenceLights';
import { UpgradeNoticeBanner } from './upgrade-notice';
import '../shared/ui/style.css';
import {
	isPageWorkspace,
	PageWorkspace,
	pageWorkspaceUrl,
} from './PageWorkspace';
import { ArchitecturePanel } from './ArchitecturePanel';
import { OverlapPanel } from './OverlapPanel';
import { PrioritiesPanel } from './PrioritiesPanel';
import { SearchConsolePanel } from './SearchConsolePanel';
import { SettingsPanel } from './settings/SettingsPanel';

export function Dashboard() {
	const [ list, setList ] = useState< PageList | null >( null );
	const [ suggestions, setSuggestions ] = useState< PageListItem[] >( [] );
	const [ suggestionStatus, setSuggestionStatus ] =
		useState< SuggestionStatus >( 'loading' );
	const [ suggestionNotice, setSuggestionNotice ] = useState( '' );
	const [ uncheckedPages, setUncheckedPages ] = useState( 0 );
	const [ settings, setSettings ] = useState< StudioSettings | null >( null );
	const [ capabilities, setCapabilities ] =
		useState< CapabilityManifest | null >( null );
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
		| 'overview'
		| 'pages'
		| 'settings'
		| 'architecture'
		| 'overlap'
		| 'search-console'
		| 'priorities'
	>( sectionView );
	const [ run, setRun ] = useState< AuditRun | null >( null );
	const [ listTick, setListTick ] = useState( 0 );
	const [ scoreState, setScoreState ] = useState( initialScoreState );
	const [ visibility, setVisibility ] = useState( initialVisibility );

	useEffect( () => {
		fetchCapabilities()
			.then( ( next ) => {
				setCapabilities( next );
				if ( ! next.manage && isAdminOnly( view ) ) {
					setView( 'overview' );
				} else if (
					view === 'priorities' &&
					! next.edit_posts &&
					! next.manage
				) {
					setView( 'overview' );
				}
				if ( next.manage ) {
					fetchSettings()
						.then( setSettings )
						.catch( () => undefined );
				}
			} )
			.catch( () => {
				setCapabilities( null );
				setError(
					__(
						'Zoop could not confirm what you can open.',
						'seo-studio'
					)
				);
			} );
	}, [ view ] );

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
		if ( view === 'pages' && scoreState ) {
			query.score_state = scoreState;
		}
		if ( view === 'pages' && visibility ) {
			query.visibility = visibility;
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
		listTick,
		view,
		scoreState,
		visibility,
	] );

	useEffect( () => {
		let cancelled = false;
		setSuggestionStatus( 'loading' );
		fetchPages( {
			per_page: '3',
			orderby: 'score',
			order: 'asc',
			page: '1',
			score_min: '0',
			suggestions: '1',
		} )
			.then( ( next ) => {
				if ( cancelled ) {
					return;
				}
				const state = next.summary?.suggestion_state;
				const serverNotice = next.summary?.suggestion_notice ?? '';
				setSuggestions( next.items.slice( 0, 3 ) );
				setSuggestionNotice( serverNotice );
				setUncheckedPages( next.summary?.unchecked ?? 0 );
				if ( state === 'incomplete' && '' === serverNotice ) {
					setSuggestionStatus( 'error' );
					return;
				}
				if (
					state === 'unscored' ||
					state === 'incomplete' ||
					state === 'clear' ||
					state === 'items'
				) {
					setSuggestionStatus( state );
					return;
				}
				setSuggestionStatus( 'error' );
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setSuggestions( [] );
					setSuggestionNotice( '' );
					setUncheckedPages( 0 );
					setSuggestionStatus( 'error' );
				}
			} );
		return () => {
			cancelled = true;
		};
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

	function openUncheckedPages() {
		setScoreState( 'unchecked' );
		setVisibility( '' );
		setSearch( '' );
		setRating( '' );
		setPostType( '' );
		setScoreMin( '' );
		setScoreMax( '' );
		setHasKeyphrase( false );
		setPage( 1 );
		setView( 'pages' );
	}

	function openStalePages() {
		setScoreState( 'needs_reanalysis' );
		setVisibility( '' );
		setSearch( '' );
		setRating( '' );
		setPostType( '' );
		setScoreMin( '' );
		setScoreMax( '' );
		setHasKeyphrase( false );
		setPage( 1 );
		setView( 'pages' );
	}

	async function checkPublished( item: PageListItem ) {
		setNotice( __( 'Checking the published page.', 'seo-studio' ) );
		try {
			await analysePost( item.object_id, {
				mode: 'live',
				...( item.keyphrase ? { keyphrase: item.keyphrase } : {} ),
			} );
			setNotice( __( 'The published page was checked.', 'seo-studio' ) );
			setListTick( ( current ) => current + 1 );
		} catch ( caught ) {
			const message =
				caught && typeof caught === 'object' && 'message' in caught
					? String( ( caught as { message: string } ).message )
					: __(
							'The published page could not be checked.',
							'seo-studio'
					  );
			setNotice( message );
		}
	}

	async function dismissUpgrade() {
		if ( ! capabilities?.upgrade_notice ) {
			return;
		}
		try {
			await dismissRulesetNotice();
			setCapabilities( { ...capabilities, upgrade_notice: null } );
		} catch ( _caught ) {
			// Leave the notice visible when dismissal does not save.
		}
	}

	async function dismissLegacy() {
		if ( ! capabilities?.legacy_notice ) {
			return;
		}
		try {
			await dismissLegacyScoreNotice();
			setCapabilities( { ...capabilities, legacy_notice: null } );
		} catch ( _caught ) {
			// Leave the notice visible when dismissal does not save.
		}
	}

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
					mode={ shortMode( settings, capabilities ) }
					versionLabel={ capabilities?.version_label ?? '' }
					allowSite={ !! capabilities?.manage }
					allowPriorities={
						!! capabilities?.edit_posts || !! capabilities?.manage
					}
					onNavigate={ () => undefined }
				/>
				{ capabilities?.upgrade_notice ? (
					<UpgradeNoticeBanner
						notice={ capabilities.upgrade_notice }
						onDismiss={ dismissUpgrade }
					/>
				) : null }
				{ capabilities?.legacy_notice ? (
					<UpgradeNoticeBanner
						notice={ capabilities.legacy_notice }
						onDismiss={ dismissLegacy }
						testId="legacy-score-notice"
						label={ __( 'Updated page checks', 'seo-studio' ) }
					/>
				) : null }
				<PageWorkspace />
			</div>
		);
	}

	const summary = list?.summary;
	const items = list?.items ?? [];
	const listFiltered = Boolean(
		search ||
			rating ||
			postType.trim() ||
			scoreMin ||
			scoreMax ||
			hasKeyphrase ||
			scoreState ||
			visibility
	);
	const firstRun = dashboardFirstRun( summary?.analysed, listFiltered );
	const shownSuggestion =
		suggestionStatus === 'items' && suggestions.length === 0
			? 'error'
			: suggestionStatus;
	const suggestionCopy = suggestionMessage(
		shownSuggestion,
		suggestionNotice
	);
	let visibleView = view;
	if ( capabilities && ! capabilities.manage && isAdminOnly( view ) ) {
		visibleView = 'overview';
	} else if (
		capabilities &&
		view === 'priorities' &&
		! capabilities.edit_posts &&
		! capabilities.manage
	) {
		visibleView = 'overview';
	}
	const modeLine = settings
		? metadataModeLine( settings )
		: shortMode( null, capabilities );

	return (
		<div className="seostudio-dashboard">
			<AppBar
				current={ visibleView }
				mode={ modeLine }
				versionLabel={ capabilities?.version_label ?? '' }
				allowSite={ !! capabilities?.manage }
				allowPriorities={
					!! capabilities?.edit_posts || !! capabilities?.manage
				}
				onNavigate={ setView }
			/>
			{ capabilities?.upgrade_notice ? (
				<UpgradeNoticeBanner
					notice={ capabilities.upgrade_notice }
					onDismiss={ dismissUpgrade }
				/>
			) : null }
			{ capabilities?.legacy_notice ? (
				<UpgradeNoticeBanner
					notice={ capabilities.legacy_notice }
					onDismiss={ dismissLegacy }
					testId="legacy-score-notice"
					label={ __( 'Updated page checks', 'seo-studio' ) }
				/>
			) : null }
			{ capabilities && ! capabilities.manage ? (
				<p className="seostudio-muted">
					{ __(
						'Site-wide reports are available to administrators.',
						'seo-studio'
					) }
				</p>
			) : null }
			{ capabilities?.manage && visibleView === 'settings' ? (
				<SettingsPanel />
			) : null }
			{ capabilities?.manage && visibleView === 'architecture' ? (
				<ArchitecturePanel />
			) : null }
			{ capabilities?.manage && visibleView === 'overlap' ? (
				<OverlapPanel />
			) : null }
			{ capabilities?.manage && visibleView === 'search-console' ? (
				<SearchConsolePanel />
			) : null }
			{ ( capabilities?.manage || capabilities?.edit_posts ) &&
			visibleView === 'priorities' ? (
				<PrioritiesPanel />
			) : null }
			{ visibleView !== 'settings' &&
			visibleView !== 'architecture' &&
			visibleView !== 'overlap' &&
			visibleView !== 'search-console' &&
			visibleView !== 'priorities' ? (
				<>
					{ visibleView === 'overview' &&
					summary?.search_discouraged ? (
						<p className="seostudio-search-hidden" role="status">
							{ __(
								'Search engines are asked not to index this site.',
								'seo-studio'
							) }
						</p>
					) : null }
					{ visibleView === 'overview' && summary ? (
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
									<button
										type="button"
										onClick={ openStalePages }
									>
										{ __( 'Out of date', 'seo-studio' ) }
									</button>
								</h2>
								<p className="seostudio-stat">
									{ summary.needs_reanalysis ?? 0 }
								</p>
								{ summary.reanalysis_notice ? (
									<p className="seostudio-muted">
										{ summary.reanalysis_notice }
									</p>
								) : null }
							</article>
							<article>
								<h2>{ __( 'Not scored', 'seo-studio' ) }</h2>
								<p>{ summary.unscored ?? 0 }</p>
							</article>
							<article>
								<h2>
									{ __( 'Not analysed yet', 'seo-studio' ) }
								</h2>
								<p>{ summary.not_analysed ?? 0 }</p>
							</article>
							<article>
								<h2>{ __( 'Errors', 'seo-studio' ) }</h2>
								<p>{ summary.failed ?? 0 }</p>
							</article>
						</div>
					) : null }
					{ firstRun ? (
						<section
							className="seostudio-first-run"
							aria-label={ __(
								'No pages checked yet',
								'seo-studio'
							) }
							data-testid="first-run"
						>
							<h2>
								{ __( 'No pages checked yet', 'seo-studio' ) }
							</h2>
							<button
								type="button"
								className="button button-primary"
								onClick={ () => {
									window.location.href = 'edit.php';
								} }
							>
								{ __( 'Open a page', 'seo-studio' ) }
							</button>
						</section>
					) : null }
					{ ! firstRun && list ? (
						<>
							{ visibleView === 'overview' ? (
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
										{ __(
											'Suggested next steps',
											'seo-studio'
										) }
									</h2>
									<p className="seostudio-muted">
										{ __(
											'These are the lowest saved on-page results. They are not a ranking forecast.',
											'seo-studio'
										) }
									</p>
									<section
										className="seostudio-suggestions"
										aria-label={ __(
											'Suggested next steps',
											'seo-studio'
										) }
										aria-live="polite"
									>
										{ suggestionCopy ? (
											<p
												role={
													shownSuggestion === 'error'
														? 'alert'
														: 'status'
												}
											>
												{ suggestionCopy }
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
													<p>
														{ suggestionText(
															item
														) }
													</p>
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
										{ shownSuggestion !== 'incomplete' &&
										shownSuggestion !== 'error' &&
										uncheckedPages > 0 &&
										suggestionNotice ? (
											<p role="status">
												{ suggestionNotice }
											</p>
										) : null }
										{ uncheckedPages > 0 &&
										shownSuggestion !== 'error' &&
										shownSuggestion !== 'loading' ? (
											<p>
												<button
													type="button"
													className="button"
													onClick={
														openUncheckedPages
													}
												>
													{ __(
														'Show unchecked pages',
														'seo-studio'
													) }
												</button>
											</p>
										) : null }
									</section>
								</>
							) : null }
							<section
								aria-label={ __( 'Pages', 'seo-studio' ) }
								data-testid="pages-list"
							>
								<h2>
									{ visibleView === 'pages'
										? __( 'Pages', 'seo-studio' )
										: __( 'All pages', 'seo-studio' ) }
								</h2>
								{ capabilities?.manage ? (
									<p className="seostudio-actions">
										<Button
											variant="primary"
											onClick={ reanalyse }
										>
											{ __(
												'Refresh content analysis',
												'seo-studio'
											) }
										</Button>
										{ run &&
										( run.status === 'queued' ||
											run.status === 'running' ) ? (
											<Button
												variant="secondary"
												onClick={ cancel }
											>
												{ __(
													'Cancel audit',
													'seo-studio'
												) }
											</Button>
										) : null }
									</p>
								) : null }
								<p className="seostudio-muted">
									{ __(
										'Refresh content analysis updates saved content only. It does not fetch published pages or restore a definitive score. Use Check published page on a page to verify its HTML.',
										'seo-studio'
									) }
								</p>
								{ notice ? (
									<p role="status">{ notice }</p>
								) : null }
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
												label: __(
													'Last analysed',
													'seo-studio'
												),
												value: 'last_rendered_at',
											},
											{
												label: __(
													'On-page score',
													'seo-studio'
												),
												value: 'score',
											},
											{
												label: __(
													'Title',
													'seo-studio'
												),
												value: 'title',
											},
										] }
										onChange={ ( value ) => {
											setOrderby( value );
											setPage( 1 );
										} }
									/>
									<SelectControl
										label={ __(
											'Direction',
											'seo-studio'
										) }
										value={ order }
										options={ [
											{
												label: __(
													'Descending',
													'seo-studio'
												),
												value: 'desc',
											},
											{
												label: __(
													'Ascending',
													'seo-studio'
												),
												value: 'asc',
											},
										] }
										onChange={ ( value ) => {
											setOrder( value );
											setPage( 1 );
										} }
									/>
									<SelectControl
										label={ __(
											'On-page rating',
											'seo-studio'
										) }
										value={ rating }
										options={ [
											{
												label: __(
													'All',
													'seo-studio'
												),
												value: '',
											},
											{
												label: __(
													'Poor',
													'seo-studio'
												),
												value: 'red',
											},
											{
												label: __( 'Ok', 'seo-studio' ),
												value: 'orange',
											},
											{
												label: __(
													'Good',
													'seo-studio'
												),
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
										{ __(
											'Advanced filters',
											'seo-studio'
										) }
									</summary>
									<div className="seostudio-toolbar">
										<TextControl
											label={ __(
												'Post type',
												'seo-studio'
											) }
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
											label={ __(
												'Minimum score',
												'seo-studio'
											) }
											value={ scoreMin }
											onChange={ ( value ) => {
												setScoreMin( value );
												setPage( 1 );
											} }
										/>
										<TextControl
											label={ __(
												'Maximum score',
												'seo-studio'
											) }
											value={ scoreMax }
											onChange={ ( value ) => {
												setScoreMax( value );
												setPage( 1 );
											} }
										/>
										<CheckboxControl
											label={ __(
												'Has a focus keyphrase',
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
									<Notice
										status="error"
										isDismissible={ false }
									>
										{ error }
									</Notice>
								) : null }
								{ list.total === 0 ? (
									<p>{ pagesListEmpty( listFiltered ) }</p>
								) : (
									<div className="seostudio-table-wrap">
										<table className="seostudio-table">
											<thead>
												<tr>
													<th scope="col">
														{ __(
															'Page',
															'seo-studio'
														) }
													</th>
													<th scope="col">
														{ __(
															'On-page',
															'seo-studio'
														) }
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
														{ __(
															'Action',
															'seo-studio'
														) }
													</th>
												</tr>
											</thead>
											<tbody>
												{ items.map( ( item ) => (
													<DashboardRow
														key={ item.page_id }
														item={ item }
														onCheck={
															checkPublished
														}
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
											onClick={ () =>
												setPage( page - 1 )
											}
										>
											{ __( 'Previous', 'seo-studio' ) }
										</Button>
										<Button
											variant="secondary"
											disabled={ page >= list.pages }
											onClick={ () =>
												setPage( page + 1 )
											}
										>
											{ __( 'Next', 'seo-studio' ) }
										</Button>
									</p>
								) : null }
							</section>
						</>
					) : null }
					{ ! firstRun && ! list && error ? (
						<Notice status="error" isDismissible={ false }>
							{ error }
						</Notice>
					) : null }
					{ ! firstRun && ! list && ! error ? (
						<p role="status">
							{ __( 'Loading pages.', 'seo-studio' ) }
						</p>
					) : null }
				</>
			) : null }
		</div>
	);
}

function isAdminOnly( section: string ): boolean {
	return (
		section === 'settings' ||
		section === 'architecture' ||
		section === 'overlap' ||
		section === 'search-console'
	);
}

function initialScoreState(): string {
	const value = new URLSearchParams( window.location.search ).get(
		'score_state'
	);
	if (
		value === 'needs_reanalysis' ||
		value === 'unchecked' ||
		value === 'needs_published_check' ||
		value === 'ruleset_retired' ||
		value === 'legacy_scores'
	) {
		return value;
	}
	return '';
}

function initialVisibility(): string {
	const value = new URLSearchParams( window.location.search ).get(
		'visibility'
	);
	if ( value === 'noindex' ) {
		return value;
	}
	return '';
}

function sectionView():
	| 'overview'
	| 'pages'
	| 'settings'
	| 'architecture'
	| 'overlap'
	| 'search-console'
	| 'priorities' {
	const section = new URLSearchParams( window.location.search ).get(
		'section'
	);
	if (
		section === 'pages' ||
		section === 'settings' ||
		section === 'architecture' ||
		section === 'overlap' ||
		section === 'search-console' ||
		section === 'priorities'
	) {
		return section;
	}
	return 'overview';
}

function AppBar( {
	current,
	mode,
	versionLabel,
	allowSite,
	allowPriorities,
	onNavigate,
}: {
	current:
		| 'overview'
		| 'pages'
		| 'settings'
		| 'architecture'
		| 'overlap'
		| 'search-console'
		| 'priorities';
	mode: string;
	versionLabel: string;
	allowSite: boolean;
	allowPriorities: boolean;
	onNavigate: (
		next:
			| 'overview'
			| 'pages'
			| 'settings'
			| 'architecture'
			| 'overlap'
			| 'search-console'
			| 'priorities'
	) => void;
} ) {
	const sections: Array< {
		id:
			| 'overview'
			| 'pages'
			| 'settings'
			| 'architecture'
			| 'overlap'
			| 'search-console'
			| 'priorities';
		label: string;
	} > = [
		{ id: 'overview', label: __( 'Overview', 'seo-studio' ) },
		{ id: 'pages', label: __( 'Pages', 'seo-studio' ) },
		{ id: 'architecture', label: __( 'Architecture', 'seo-studio' ) },
		{ id: 'overlap', label: __( 'Overlap', 'seo-studio' ) },
		{
			id: 'search-console',
			label: __( 'Search Console', 'seo-studio' ),
		},
		{ id: 'priorities', label: __( 'Priorities', 'seo-studio' ) },
		{ id: 'settings', label: __( 'Settings', 'seo-studio' ) },
	];
	const items = sections.filter( ( item ) => {
		if ( item.id === 'overview' || item.id === 'pages' ) {
			return true;
		}
		if ( item.id === 'priorities' ) {
			return allowPriorities;
		}
		return allowSite;
	} );
	return (
		<div className="seostudio-appbar">
			<h1>{ __( 'Zoop', 'seo-studio' ) }</h1>
			{ versionLabel ? (
				<p className="seostudio-version">{ versionLabel }</p>
			) : null }
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

function shortMode(
	settings: StudioSettings | null,
	capabilities: CapabilityManifest | null
): string {
	if ( settings?.publishing || capabilities?.publishing ) {
		return __( 'Zoop manages metadata', 'seo-studio' );
	}
	if ( settings?.detected?.name ) {
		return settings.detected.name;
	}
	if ( capabilities?.detected_name ) {
		return capabilities.detected_name;
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
	return suggestionForState(
		item.score_state,
		item.top_issue,
		item.top_issue ? assessmentTitle( item.top_issue ) : ''
	);
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

function DashboardRow( {
	item,
	onCheck,
}: {
	item: PageListItem;
	onCheck: ( item: PageListItem ) => void;
} ) {
	const score =
		item.presentation?.headline ||
		scoreStateLabel(
			item.score_state,
			item.onpage.score,
			item.onpage.denominator
		);
	return (
		<tr>
			<td>
				{ item.edit_url ? (
					<a href={ item.edit_url }>{ item.title }</a>
				) : (
					item.title
				) }
				<div className="seostudio-muted">
					{ item.keyphrase ||
						__( 'No focus keyphrase', 'seo-studio' ) }
				</div>
			</td>
			<td>{ score }</td>
			<td>
				<EvidenceLights
					verified={ !! item.presentation?.definitive }
					seoRating={ item.seo_rating }
					readabilityRating={ item.readability_rating }
				/>
			</td>
			<td>{ mainFinding( item ) }</td>
			<td>
				<Button variant="secondary" onClick={ () => onCheck( item ) }>
					{ __( 'Check published page', 'seo-studio' ) }
				</Button>{ ' ' }
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
