import {
	Button,
	CheckboxControl,
	Notice,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { createRoot, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	fetchPages,
	startAudit,
	type PageList,
	type PageListItem,
} from '../shared/api';
import { RunProgress } from '../shared/ui/RunProgress';
import { ScoreRing } from '../shared/ui/ScoreRing';
import { TrafficLight } from '../shared/ui/TrafficLight';
import '../shared/ui/style.css';

export function Dashboard() {
	const [ list, setList ] = useState< PageList | null >( null );
	const [ search, setSearch ] = useState( '' );
	const [ postType, setPostType ] = useState( '' );
	const [ rating, setRating ] = useState( '' );
	const [ scoreMin, setScoreMin ] = useState( '' );
	const [ scoreMax, setScoreMax ] = useState( '' );
	const [ hasKeyphrase, setHasKeyphrase ] = useState( false );
	const [ page, setPage ] = useState( 1 );
	const [ notice, setNotice ] = useState( '' );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		const query: Record< string, string > = {
			page: String( page ),
			per_page: '20',
		};
		if ( search ) {
			query.search = search;
		}
		if ( postType ) {
			query.post_type = postType;
		}
		if ( rating ) {
			query.rating = rating;
		}
		if ( scoreMin ) {
			query.score_min = scoreMin;
		}
		if ( scoreMax ) {
			query.score_max = scoreMax;
		}
		if ( hasKeyphrase ) {
			query.has_keyphrase = '1';
		}
		fetchPages( query )
			.then( ( next ) => {
				setList( next );
				setError( '' );
			} )
			.catch( () =>
				setError(
					__( 'The page list could not be loaded.', 'seo-studio' )
				)
			);
	}, [ search, postType, rating, scoreMin, scoreMax, hasKeyphrase, page ] );

	async function reanalyse() {
		setNotice( '' );
		try {
			await startAudit();
		} catch ( caught ) {
			const message =
				caught && typeof caught === 'object' && 'message' in caught
					? String( ( caught as { message: string } ).message )
					: __(
							'The site audit queue is not available in this phase.',
							'seo-studio'
					  );
			setNotice( message );
		}
	}

	const summary = list?.summary;
	const items = list?.items ?? [];

	return (
		<div className="seostudio-dashboard">
			<h1>{ __( 'SEO Studio', 'seo-studio' ) }</h1>
			<p>
				<Button variant="primary" onClick={ reanalyse }>
					{ __( 'Re-analyse all', 'seo-studio' ) }
				</Button>
			</p>
			{ summary ? (
				<div className="seostudio-summary">
					<article>
						<h2>{ __( 'Pages analysed', 'seo-studio' ) }</h2>
						<p>{ summary.analysed }</p>
					</article>
					<article>
						<h2>{ __( 'Average on-page', 'seo-studio' ) }</h2>
						<p>
							{ summary.average === null
								? __( 'Not scored', 'seo-studio' )
								: summary.average }
						</p>
					</article>
					<article>
						<h2>{ __( 'Poor', 'seo-studio' ) }</h2>
						<p>{ summary.red }</p>
					</article>
					<article>
						<h2>{ __( 'Ok', 'seo-studio' ) }</h2>
						<p>{ summary.orange }</p>
					</article>
					<article>
						<h2>{ __( 'Good', 'seo-studio' ) }</h2>
						<p>{ summary.green }</p>
					</article>
				</div>
			) : null }
			<p>
				{ __(
					'Last audit: not available in this phase.',
					'seo-studio'
				) }
			</p>
			{ notice ? (
				<RunProgress processed={ 0 } total={ 0 } message={ notice } />
			) : null }
			<form
				className="seostudio-filters"
				onSubmit={ ( event ) => {
					event.preventDefault();
					setPage( 1 );
				} }
			>
				<TextControl
					label={ __( 'Search', 'seo-studio' ) }
					value={ search }
					onChange={ ( value ) => {
						setSearch( value );
						setPage( 1 );
					} }
				/>
				<TextControl
					label={ __( 'Post type', 'seo-studio' ) }
					value={ postType }
					onChange={ ( value ) => {
						setPostType( value );
						setPage( 1 );
					} }
				/>
				<SelectControl
					label={ __( 'Rating', 'seo-studio' ) }
					value={ rating }
					options={ [
						{ label: __( 'All', 'seo-studio' ), value: '' },
						{ label: __( 'Poor', 'seo-studio' ), value: 'red' },
						{ label: __( 'Ok', 'seo-studio' ), value: 'orange' },
						{ label: __( 'Good', 'seo-studio' ), value: 'green' },
						{
							label: __( 'Not scored', 'seo-studio' ),
							value: 'grey',
						},
					] }
					onChange={ ( value ) => {
						setRating( value );
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
					label={ __( 'Has keyphrase', 'seo-studio' ) }
					checked={ hasKeyphrase }
					onChange={ ( value ) => {
						setHasKeyphrase( value );
						setPage( 1 );
					} }
				/>
			</form>
			{ error ? (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) : null }
			{ list && list.total === 0 ? (
				<p>
					{ __(
						'No pages have been analysed yet. Open a post and choose Analyse now.',
						'seo-studio'
					) }
				</p>
			) : (
				<table className="seostudio-table">
					<thead>
						<tr>
							<th>{ __( 'Title', 'seo-studio' ) }</th>
							<th>{ __( 'Keyphrase', 'seo-studio' ) }</th>
							<th>{ __( 'On-page', 'seo-studio' ) }</th>
							<th>{ __( 'SEO', 'seo-studio' ) }</th>
							<th>{ __( 'Readability', 'seo-studio' ) }</th>
							<th>{ __( 'Top issue', 'seo-studio' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ items.map( ( item ) => (
							<DashboardRow key={ item.page_id } item={ item } />
						) ) }
					</tbody>
				</table>
			) }
			{ list && list.pages > 1 ? (
				<p>
					<Button
						variant="secondary"
						disabled={ page <= 1 }
						onClick={ () => setPage( page - 1 ) }
					>
						{ __( 'Previous', 'seo-studio' ) }
					</Button>{ ' ' }
					<Button
						variant="secondary"
						disabled={ page >= list.pages }
						onClick={ () => setPage( page + 1 ) }
					>
						{ __( 'Next', 'seo-studio' ) }
					</Button>
				</p>
			) : null }
		</div>
	);
}

function DashboardRow( { item }: { item: PageListItem } ) {
	return (
		<tr>
			<td>
				{ item.edit_url ? (
					<a href={ item.edit_url }>{ item.title }</a>
				) : (
					item.title
				) }
			</td>
			<td>{ item.keyphrase || __( 'None', 'seo-studio' ) }</td>
			<td>
				<ScoreRing
					value={ item.onpage.score }
					denominator={ item.onpage.denominator }
				/>
			</td>
			<td>
				<TrafficLight
					rating={ item.seo_rating }
					label={ __( 'SEO', 'seo-studio' ) }
				/>
			</td>
			<td>
				<TrafficLight
					rating={ item.readability_rating }
					label={ __( 'Readability', 'seo-studio' ) }
				/>
			</td>
			<td>{ item.top_issue || item.last_error || '' }</td>
		</tr>
	);
}

const mount = document.getElementById( 'seostudio-dashboard' );
if ( mount ) {
	createRoot( mount ).render( <Dashboard /> );
}
