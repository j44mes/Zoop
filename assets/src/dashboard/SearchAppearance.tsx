import { Button, TextareaControl, TextControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	applyAppearance,
	fetchAppearance,
	restoreAppearance,
	saveAppearance,
	type AppearanceRecord,
	type AppearanceVerification,
} from '../shared/api';
import type { ListedAssessment } from '../shared/ui/AssessmentList';
import { assessmentTitle } from '../shared/ui/assessment-labels';
import { SnippetPreview } from '../shared/ui/SnippetPreview';
import { appearancePreviewCaption } from './ownership-note';

function messageOf( error: unknown, fallback: string ): string {
	if ( error && typeof error === 'object' && 'message' in error ) {
		const message = ( error as { message?: string } ).message;
		if ( message ) {
			return message;
		}
	}
	return fallback;
}

function isSearchCheck( row: ListedAssessment ): boolean {
	return (
		row.id.startsWith( 'title.' ) ||
		row.id.startsWith( 'meta.' ) ||
		row.id === 'headings.title_differs'
	);
}

export function SearchAppearance( {
	postId,
	pageTitle,
	assessments,
}: {
	postId: number;
	pageTitle: string;
	assessments: ListedAssessment[];
} ) {
	const [ report, setReport ] = useState< AppearanceRecord | null >( null );
	const [ title, setTitle ] = useState( '' );
	const [ description, setDescription ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( '' );
	const [ confirming, setConfirming ] = useState< '' | 'apply' | 'restore' >(
		''
	);
	const [ verification, setVerification ] =
		useState< AppearanceVerification | null >( null );

	useEffect( () => {
		let cancelled = false;
		fetchAppearance( postId )
			.then( ( next ) => {
				if ( cancelled ) {
					return;
				}
				setReport( next );
				setTitle(
					next.proposal_saved
						? next.proposal.title
						: next.resolved.title
				);
				setDescription(
					next.proposal_saved
						? next.proposal.description
						: next.resolved.description
				);
			} )
			.catch( ( caught ) => {
				if ( ! cancelled ) {
					setError(
						messageOf(
							caught,
							__(
								'The search appearance could not be loaded.',
								'seo-studio'
							)
						)
					);
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ postId ] );

	const remember = ( next: AppearanceRecord ) => {
		setReport( next );
		setVerification( next.verification );
		setConfirming( '' );
	};

	const save = async () => {
		setBusy( 'save' );
		setError( '' );
		try {
			const next = await saveAppearance( postId, {
				title,
				description,
				proposal_revision: report?.proposal_revision ?? 0,
			} );
			setTitle( next.proposal.title );
			setDescription( next.proposal.description );
			remember( next );
		} catch ( caught ) {
			setError(
				messageOf(
					caught,
					__( 'The proposal could not be saved.', 'seo-studio' )
				)
			);
		} finally {
			setBusy( '' );
		}
	};

	const apply = async () => {
		if (
			! report?.proposal_saved ||
			title !== report.proposal.title ||
			description !== report.proposal.description
		) {
			setError(
				__( 'Save the proposal before applying it.', 'seo-studio' )
			);
			setConfirming( '' );
			return;
		}
		setBusy( 'apply' );
		setError( '' );
		try {
			remember(
				await applyAppearance( postId, {
					confirm: true,
					proposal_revision: report.proposal_revision,
					baseline_title: report.baseline.title,
					baseline_description: report.baseline.description,
				} )
			);
		} catch ( caught ) {
			setError(
				messageOf(
					caught,
					__( 'The proposal was not published.', 'seo-studio' )
				)
			);
		} finally {
			setBusy( '' );
		}
	};

	const restore = async () => {
		setBusy( 'restore' );
		setError( '' );
		try {
			const next = await restoreAppearance( postId, {
				title: report?.baseline.title || '',
				description: report?.baseline.description || '',
			} );
			setTitle( next.resolved.title );
			setDescription( next.resolved.description );
			remember( next );
		} catch ( caught ) {
			setError(
				messageOf(
					caught,
					__( 'The previous values were not restored.', 'seo-studio' )
				)
			);
		} finally {
			setBusy( '' );
		}
	};

	if ( ! report && ! error ) {
		return <p>{ __( 'Loading the search appearance.', 'seo-studio' ) }</p>;
	}

	const checks = assessments.filter( isSearchCheck );
	const previewUrl = report?.url || '';
	let unavailable = '';
	if ( report && ! report.can_apply ) {
		if ( report.publishing && report.baseline.target === 'homepage' ) {
			unavailable = __(
				'The homepage title is a site setting. An administrator can apply a change.',
				'seo-studio'
			);
		} else if ( report.controller ) {
			unavailable = sprintf(
				/* translators: %s: name of the plugin that prints the live tags. */
				__(
					'%s controls the live tags. Zoop can keep a proposal, and it does not change that plugin.',
					'seo-studio'
				),
				report.controller
			);
		} else {
			unavailable = __(
				'Zoop is not printing the tags. A saved proposal stays in Zoop.',
				'seo-studio'
			);
		}
	}

	return (
		<div className="seostudio-appearance">
			<div>
				<p className="seostudio-kicker">{ pageTitle }</p>
				<h3>{ __( 'Search appearance', 'seo-studio' ) }</h3>
				<p className="seostudio-lede">
					{ __(
						'A title you enter in the editor is published by Update. A suggestion stays here until you review and apply it.',
						'seo-studio'
					) }
				</p>
				<section className="seostudio-published">
					<h4>
						{ __( 'Resolved from saved settings', 'seo-studio' ) }
					</h4>
					<p className="seostudio-muted">
						{ __(
							'Calculated from saved settings. This is not a check of the live HTML.',
							'seo-studio'
						) }
					</p>
					<p>{ report?.resolved.title || '–' }</p>
					<p className="seostudio-muted">
						{ previewUrl ||
							__( 'Permalink unavailable', 'seo-studio' ) }
					</p>
					<p>{ report?.resolved.description || '–' }</p>
				</section>
				<TextControl
					label={ __( 'Proposed SEO title', 'seo-studio' ) }
					value={ title }
					onChange={ setTitle }
				/>
				<TextareaControl
					label={ __( 'Proposed meta description', 'seo-studio' ) }
					value={ description }
					onChange={ setDescription }
				/>
				{ report && unavailable ? (
					<p className="seostudio-muted">
						{ unavailable }
						{ report.publishing ? null : (
							<>
								{ ' ' }
								<a href={ report.edit_url }>
									{ __( 'Open the editor', 'seo-studio' ) }
								</a>
							</>
						) }
					</p>
				) : null }
				<p className="seostudio-actions">
					<Button
						variant="secondary"
						disabled={ busy !== '' }
						onClick={ save }
					>
						{ __( 'Save proposal', 'seo-studio' ) }
					</Button>
					{ report?.can_apply ? (
						<Button
							variant="primary"
							disabled={ busy !== '' }
							onClick={ () => setConfirming( 'apply' ) }
						>
							{ __( 'Review and apply', 'seo-studio' ) }
						</Button>
					) : null }
					{ report?.can_restore ? (
						<Button
							variant="tertiary"
							disabled={ busy !== '' }
							onClick={ () => setConfirming( 'restore' ) }
						>
							{ __( 'Restore previous values', 'seo-studio' ) }
						</Button>
					) : null }
				</p>
				{ confirming === 'apply' && report ? (
					<section className="seostudio-confirm">
						<h4>
							{ __(
								'Confirm the published change',
								'seo-studio'
							) }
						</h4>
						<p>
							<strong>
								{ __(
									'Stored title that will be replaced:',
									'seo-studio'
								) }
							</strong>{ ' ' }
							{ report.baseline.title || '–' }
						</p>
						<p>
							<strong>
								{ __( 'Proposed title:', 'seo-studio' ) }
							</strong>{ ' ' }
							{ title || '–' }
						</p>
						<p>
							<strong>
								{ __(
									'Stored description that will be replaced:',
									'seo-studio'
								) }
							</strong>{ ' ' }
							{ report.baseline.description || '–' }
						</p>
						<p>
							<strong>
								{ __( 'Proposed description:', 'seo-studio' ) }
							</strong>{ ' ' }
							{ description || '–' }
						</p>
						<p className="seostudio-actions">
							<Button
								variant="primary"
								disabled={ busy !== '' }
								onClick={ apply }
							>
								{ __( 'Publish these values', 'seo-studio' ) }
							</Button>
							<Button
								variant="secondary"
								disabled={ busy !== '' }
								onClick={ () => setConfirming( '' ) }
							>
								{ __( 'Keep the proposal', 'seo-studio' ) }
							</Button>
						</p>
					</section>
				) : null }
				{ confirming === 'restore' && report ? (
					<section className="seostudio-confirm">
						<h4>
							{ __(
								'Restore the previous values',
								'seo-studio'
							) }
						</h4>
						<p>{ report.previous.title || '–' }</p>
						<p>{ report.previous.description || '–' }</p>
						<p className="seostudio-actions">
							<Button
								variant="primary"
								disabled={ busy !== '' }
								onClick={ restore }
							>
								{ __( 'Restore and check', 'seo-studio' ) }
							</Button>
							<Button
								variant="secondary"
								disabled={ busy !== '' }
								onClick={ () => setConfirming( '' ) }
							>
								{ __(
									'Keep the current values',
									'seo-studio'
								) }
							</Button>
						</p>
					</section>
				) : null }
				{ error ? <p role="alert">{ error }</p> : null }
				{ verification ? (
					<section>
						<h4>
							{ verification.basis === 'live_html' &&
							verification.html_matches
								? __(
										'Change applied successfully',
										'seo-studio'
								  )
								: __(
										'Saved. The published HTML was not confirmed.',
										'seo-studio'
								  ) }
						</h4>
						<p>{ verification.note }</p>
						{ verification.basis === 'live_html' ? (
							<>
								<p>
									{ __(
										'Observed HTML title:',
										'seo-studio'
									) }{ ' ' }
									{ verification.observed_title || '–' }
								</p>
								<p>
									{ __(
										'Observed HTML description:',
										'seo-studio'
									) }{ ' ' }
									{ verification.observed_description || '–' }
								</p>
							</>
						) : null }
						<p className="seostudio-muted">
							{ __( 'Checked at', 'seo-studio' ) }{ ' ' }
							{ verification.checked_at }
						</p>
					</section>
				) : null }
				<details className="seostudio-why">
					<summary>
						{ __( 'Why is Zoop recommending this?', 'seo-studio' ) }
					</summary>
					{ checks.length === 0 ? (
						<p className="seostudio-muted">
							{ __(
								'No stored title or description check is available yet.',
								'seo-studio'
							) }
						</p>
					) : (
						<ul>
							{ checks.map( ( row ) => (
								<li key={ row.id }>
									<strong>
										{ assessmentTitle( row.id ) }
									</strong>
									{ row.explanation || row.recommendation
										? ` — ${
												row.explanation ||
												row.recommendation
										  }`
										: '' }
									{ typeof row.earned === 'number' &&
									typeof row.max === 'number'
										? ` (${ row.earned }/${ row.max })`
										: '' }
								</li>
							) ) }
						</ul>
					) }
				</details>
			</div>
			<div>
				<p className="seostudio-muted">
					{ appearancePreviewCaption() }
				</p>
				<div className="seostudio-preview-row">
					<SnippetPreview
						title={ title }
						url={ previewUrl }
						description={ description }
					/>
					<SnippetPreview
						title={ title }
						url={ previewUrl }
						description={ description }
						viewport="mobile"
					/>
				</div>
			</div>
		</div>
	);
}
