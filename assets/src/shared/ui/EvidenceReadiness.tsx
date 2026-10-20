import { useState, type ReactNode } from 'react';
import { __ } from '@wordpress/i18n';
import type { ScorePresentation } from '../api';
import { assessmentTitle } from './assessment-labels';

type PresentedCheck = NonNullable< ScorePresentation[ 'checks' ] >[ number ];
type MissingItem = ScorePresentation[ 'missing' ][ number ];

const GROUPS: Array< [ string, string ] > = [
	[ 'passed', __( 'Passed', 'seo-studio' ) ],
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
	const [ open, setOpen ] = useState( '' );
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

	function focusDetail( id: string ) {
		window.setTimeout( () => {
			document.getElementById( id )?.focus();
		}, 0 );
	}

	function runCheck( check: PresentedCheck ) {
		if ( check.action === 'check_published' ) {
			setNotice( __( 'Checking the published page.', 'seo-studio' ) );
			onCheckPublished();
			return;
		}
		if ( check.action === 'add_keyphrase' ) {
			setNotice(
				__( 'The target phrase field is ready.', 'seo-studio' )
			);
			onAddKeyphrase();
			return;
		}
		setOpen( check.id );
		if ( check.action === 'view_fix' ) {
			setNotice(
				check.recommendation ||
					__( 'Showing the safe fix.', 'seo-studio' )
			);
			focusDetail( `seostudio-fix-${ check.id }` );
			return;
		}
		if ( check.action === 'view_evidence' ) {
			setNotice(
				check.measured ||
					__( 'Showing the observed value.', 'seo-studio' )
			);
			focusDetail( `seostudio-evidence-${ check.id }` );
			return;
		}
		setNotice(
			check.reason || __( 'Showing the explanation.', 'seo-studio' )
		);
		focusDetail( `seostudio-reason-${ check.id }` );
	}

	function runMissing( item: MissingItem ) {
		if ( item.action === 'add_keyphrase' ) {
			setNotice(
				__( 'The target phrase field is ready.', 'seo-studio' )
			);
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
			{ GROUPS.map( ( [ state, heading ] ) => {
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
									open={ open === check.id }
									editorUrl={ editorUrl }
									busy={ busy }
									onRun={ () => runCheck( check ) }
								/>
							) ) }
						</ul>
					</section>
				);
			} ) }
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
										open={ open === check.id }
										editorUrl={ editorUrl }
										busy={ busy }
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
	onRun,
}: {
	check: PresentedCheck;
	open: boolean;
	editorUrl: string;
	busy: boolean;
	onRun: () => void;
} ) {
	const reveals =
		check.action === 'view_evidence' ||
		check.action === 'view_fix' ||
		check.action === 'view_explanation';
	let detailId = `seostudio-reason-${ check.id }`;
	if ( check.action === 'view_fix' ) {
		detailId = `seostudio-fix-${ check.id }`;
	} else if ( check.action === 'view_evidence' ) {
		detailId = `seostudio-evidence-${ check.id }`;
	}

	return (
		<li data-check={ check.id } data-state={ check.state }>
			<h4>{ assessmentTitle( check.id ) }</h4>
			<p>
				<span className="seostudio-chip">{ check.label }</span>
			</p>
			<p id={ `seostudio-reason-${ check.id }` } tabIndex={ -1 }>
				{ check.reason }
			</p>
			{ check.measured ? (
				<p id={ `seostudio-evidence-${ check.id }` } tabIndex={ -1 }>
					{ __( 'Measured', 'seo-studio' ) }: { check.measured }
				</p>
			) : (
				<p id={ `seostudio-evidence-${ check.id }` } tabIndex={ -1 }>
					{ __( 'No measured value was stored.', 'seo-studio' ) }
				</p>
			) }
			<button
				type="button"
				className="button"
				disabled={ busy && check.action === 'check_published' }
				aria-expanded={ reveals ? open : undefined }
				aria-controls={ reveals ? detailId : undefined }
				onClick={ onRun }
			>
				{ check.action_label }
			</button>
			{ check.action === 'view_fix' && open ? (
				<div id={ `seostudio-fix-${ check.id }` } tabIndex={ -1 }>
					<p>{ check.recommendation }</p>
					{ editorUrl ? (
						<p>
							<a href={ editorUrl }>
								{ __( 'Edit page', 'seo-studio' ) }
							</a>
						</p>
					) : null }
				</div>
			) : null }
		</li>
	);
}
