import { useState, type ReactNode } from 'react';
import { __ } from '@wordpress/i18n';
import type { ScorePresentation } from '../api';
import { assessmentTitle } from './assessment-labels';

type PresentedCheck = NonNullable< ScorePresentation[ 'checks' ] >[ number ];
type MissingItem = ScorePresentation[ 'missing' ][ number ];

const OPEN_GROUPS: Array< [ string, string ] > = [
	[ 'needs_work', __( 'Needs work', 'seo-studio' ) ],
	[ 'not_checked', __( 'Not checked', 'seo-studio' ) ],
	[ 'out_of_date', __( 'Out of date', 'seo-studio' ) ],
];

interface EvidenceReadinessProps {
	presentation?: ScorePresentation;
	editorUrl?: string;
	busy?: boolean;
	provisional?: boolean;
	onCheckPublished: () => void;
	onAddKeyphrase: () => void;
	children?: ReactNode;
}

/**
 * Accessible name for a score that PHP has already explained.
 * This joins stored sentences. It does not decide whether the score is ready.
 *
 * @param presentation Stored readiness payload.
 */
export function readinessText( presentation?: ScorePresentation ): string {
	if ( ! presentation ) {
		return __( 'Not enough evidence yet', 'seo-studio' );
	}
	const parts = [ presentation.headline, presentation.explanation ];
	for ( const item of presentation.missing ?? [] ) {
		parts.push( item.label );
	}
	const text = parts.filter( ( part ) => part.trim() !== '' ).join( ' ' );
	return text || __( 'Not enough evidence yet', 'seo-studio' );
}

export function EvidenceReadiness( {
	presentation,
	editorUrl = '',
	busy = false,
	provisional = false,
	onCheckPublished,
	onAddKeyphrase,
	children,
}: EvidenceReadinessProps ) {
	const [ open, setOpen ] = useState< Record< string, boolean > >( {} );
	const [ showPassed, setShowPassed ] = useState( false );
	const [ showAll, setShowAll ] = useState( false );
	const [ notice, setNotice ] = useState( '' );

	if ( provisional || ! presentation ) {
		return <>{ children }</>;
	}

	const checks = presentation.checks ?? [];
	const applicable = checks.filter(
		( check ) => check.state !== 'not_applicable'
	);
	const skipped = checks.filter(
		( check ) => check.state === 'not_applicable'
	);
	const passed = applicable.filter( ( check ) => check.state === 'passed' );

	function toggle( id: string ) {
		setOpen( ( current ) => ( {
			...current,
			[ id ]: ! current[ id ],
		} ) );
	}

	function runCheck( check: PresentedCheck ) {
		if ( check.action === 'check_published' ) {
			setNotice( __( 'Checking the published page.', 'seo-studio' ) );
			onCheckPublished();
			return;
		}
		if ( check.action === 'add_keyphrase' ) {
			setNotice( __( 'Add a focus keyphrase below.', 'seo-studio' ) );
			onAddKeyphrase();
		}
	}

	function runMissing( item: MissingItem ) {
		if ( item.action === 'add_keyphrase' ) {
			setNotice( __( 'Add a focus keyphrase below.', 'seo-studio' ) );
			onAddKeyphrase();
			return;
		}
		setNotice( __( 'Checking the published page.', 'seo-studio' ) );
		onCheckPublished();
	}

	return (
		<div className="seostudio-readiness">
			{ presentation.search_status === 'noindex' ? (
				<p
					className="seostudio-search-hidden"
					data-testid="hidden-from-search"
					role="status"
				>
					<strong>{ presentation.search_label }</strong>{ ' ' }
					{ presentation.search_explanation }
				</p>
			) : null }
			{ presentation.search_status === 'not_public' ||
			presentation.search_status === 'site_discouraged' ? (
				<p className="seostudio-muted" data-testid="search-status">
					<strong>{ presentation.search_label }</strong>{ ' ' }
					{ presentation.search_explanation }
				</p>
			) : null }
			{ children }
			{ presentation.missing.length > 0 ? (
				<section aria-label={ __( 'Missing evidence', 'seo-studio' ) }>
					<h3>{ __( 'Missing evidence', 'seo-studio' ) }</h3>
					<ul className="seostudio-missing">
						{ presentation.missing.map( ( item ) => (
							<li key={ item.id }>
								<span>{ item.label }</span>
								<button
									type="button"
									className="button"
									disabled={ busy }
									onClick={ () => runMissing( item ) }
								>
									{ item.action_label }
								</button>
							</li>
						) ) }
					</ul>
				</section>
			) : null }
			{ notice ? <p role="status">{ notice }</p> : null }
			{ OPEN_GROUPS.map( ( [ state, heading ] ) => {
				const rows = applicable.filter(
					( check ) => check.state === state
				);
				if ( rows.length === 0 ) {
					return null;
				}
				return (
					<section key={ state } aria-label={ heading }>
						<h3>{ heading }</h3>
						<ul className="seostudio-checks">
							{ rows.map( ( check ) => (
								<CheckRow
									key={ check.id }
									check={ check }
									open={ !! open[ check.id ] }
									editorUrl={ editorUrl }
									busy={ busy }
									onToggle={ () => toggle( check.id ) }
									onRun={ () => runCheck( check ) }
								/>
							) ) }
						</ul>
					</section>
				);
			} ) }
			{ passed.length > 0 ? (
				<div>
					<button
						type="button"
						className="button-link"
						aria-expanded={ showPassed }
						aria-controls="seostudio-passed-checks"
						onClick={ () =>
							setShowPassed( ( current ) => ! current )
						}
					>
						{ __( 'Passed', 'seo-studio' ) }
					</button>
					<div id="seostudio-passed-checks" hidden={ ! showPassed }>
						<section aria-label={ __( 'Passed', 'seo-studio' ) }>
							<ul className="seostudio-checks">
								{ passed.map( ( check ) => (
									<CheckRow
										key={ check.id }
										check={ check }
										open={ !! open[ check.id ] }
										editorUrl={ editorUrl }
										busy={ busy }
										onToggle={ () => toggle( check.id ) }
										onRun={ () => runCheck( check ) }
									/>
								) ) }
							</ul>
						</section>
					</div>
				</div>
			) : null }
			{ skipped.length > 0 ? (
				<div>
					<button
						type="button"
						className="button-link"
						aria-expanded={ showAll }
						aria-controls="seostudio-other-checks"
						onClick={ () => setShowAll( ( current ) => ! current ) }
					>
						{ __( 'Show all checks', 'seo-studio' ) }
					</button>
					<div id="seostudio-other-checks" hidden={ ! showAll }>
						<section
							aria-label={ __( "Doesn't apply", 'seo-studio' ) }
						>
							<h3>{ __( "Doesn't apply", 'seo-studio' ) }</h3>
							<ul className="seostudio-checks">
								{ skipped.map( ( check ) => (
									<CheckRow
										key={ check.id }
										check={ check }
										open={ !! open[ check.id ] }
										editorUrl={ editorUrl }
										busy={ busy }
										onToggle={ () => toggle( check.id ) }
										onRun={ () => runCheck( check ) }
									/>
								) ) }
							</ul>
						</section>
					</div>
				</div>
			) : null }
		</div>
	);
}

function CheckRow( {
	check,
	open,
	editorUrl,
	busy,
	onToggle,
	onRun,
}: {
	check: PresentedCheck;
	open: boolean;
	editorUrl: string;
	busy: boolean;
	onToggle: () => void;
	onRun: () => void;
} ) {
	const panelId = `seostudio-check-${ check.id }`;
	const title = assessmentTitle( check.id );

	return (
		<li data-check={ check.id } data-state={ check.state }>
			<h4>
				<button
					type="button"
					className="seostudio-check-toggle"
					aria-expanded={ open }
					aria-controls={ panelId }
					onClick={ onToggle }
				>
					<span>{ title }</span>
					<span className="seostudio-chip">{ check.label }</span>
				</button>
			</h4>
			<div
				id={ panelId }
				hidden={ ! open }
				role="region"
				aria-label={ title }
			>
				{ check.reason ? <p>{ check.reason }</p> : null }
				{ check.measured ? (
					<p>
						{ __( 'Measured', 'seo-studio' ) }: { check.measured }
					</p>
				) : null }
				{ check.recommendation ? (
					<p>{ check.recommendation }</p>
				) : null }
				{ check.action === 'view_fix' && editorUrl ? (
					<p>
						<a href={ editorUrl }>
							{ __( 'Edit page', 'seo-studio' ) }
						</a>
					</p>
				) : null }
				{ check.action === 'check_published' ||
				check.action === 'add_keyphrase' ? (
					<button
						type="button"
						className="button"
						disabled={ busy && check.action === 'check_published' }
						onClick={ onRun }
					>
						{ check.action_label }
					</button>
				) : null }
			</div>
		</li>
	);
}
