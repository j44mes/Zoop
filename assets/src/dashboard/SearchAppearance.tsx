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
						: next.published.title
				);
				setDescription(
					next.proposal_saved
						? next.proposal.description
						: next.published.description
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
			remember( await saveAppearance( postId, { title, description } ) );
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
		setBusy( 'apply' );
		setError( '' );
		try {
			remember(
				await applyAppearance( postId, {
					confirm: true,
					title,
					description,
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
			const next = await restoreAppearance( postId );
			setTitle( next.published.title );
			setDescription( next.published.description );
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

	return (
		<div className="seostudio-appearance">
			<div>
				<p className="seostudio-kicker">{ pageTitle }</p>
				<h3>{ __( 'Search appearance', 'seo-studio' ) }</h3>
				<p className="seostudio-lede">
					{ __(
						'Compare the published title and description with a proposal. Saving a proposal leaves the website unchanged.',
						'seo-studio'
					) }
				</p>
				<section className="seostudio-published">
					<h4>{ __( 'Currently published', 'seo-studio' ) }</h4>
					<p>{ report?.published.title || '–' }</p>
					<p className="seostudio-muted">
						{ previewUrl ||
							__( 'Permalink unavailable', 'seo-studio' ) }
					</p>
					<p>{ report?.published.description || '–' }</p>
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
				{ report && ! report.can_apply ? (
					<p className="seostudio-muted">
						{ report.controller
							? sprintf(
									/* translators: %s: name of the plugin that prints the live tags. */
									__(
										'%s controls the live tags. Zoop can keep a proposal, and it does not change that plugin.',
										'seo-studio'
									),
									report.controller
							  )
							: __(
									'Zoop is not printing the tags. A saved proposal stays in Zoop.',
									'seo-studio'
							  ) }{ ' ' }
						<a href={ report.edit_url }>
							{ __( 'Open the editor', 'seo-studio' ) }
						</a>
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
							{ __( 'Apply approved changes', 'seo-studio' ) }
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
								{ __( 'Previous title:', 'seo-studio' ) }
							</strong>{ ' ' }
							{ report.published.title || '–' }
						</p>
						<p>
							<strong>
								{ __( 'Proposed title:', 'seo-studio' ) }
							</strong>{ ' ' }
							{ title || '–' }
						</p>
						<p>
							<strong>
								{ __( 'Previous description:', 'seo-studio' ) }
							</strong>{ ' ' }
							{ report.published.description || '–' }
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
							{ verification.html_matches
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
						{ verification.observed_title ? (
							<p>
								{ __( 'Published HTML title:', 'seo-studio' ) }{ ' ' }
								{ verification.observed_title }
							</p>
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
