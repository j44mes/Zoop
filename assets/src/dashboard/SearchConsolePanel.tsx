import { Button, TextareaControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	fetchSearchConsole,
	importSearchConsole,
	type SearchSummary,
} from '../shared/api';

export function SearchConsolePanel() {
	const [ report, setReport ] = useState< SearchSummary | null >( null );
	const [ csv, setCsv ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	useEffect( () => {
		let cancelled = false;
		fetchSearchConsole()
			.then( ( next ) => {
				if ( ! cancelled ) {
					setReport( next );
				}
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setError(
						__(
							'The Search Console import could not be loaded.',
							'seo-studio'
						)
					);
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [] );

	const upload = async () => {
		setBusy( true );
		setError( '' );
		try {
			setReport( await importSearchConsole( csv ) );
		} catch ( caught ) {
			const message =
				caught && typeof caught === 'object' && 'message' in caught
					? String( ( caught as { message?: string } ).message )
					: __( 'The file could not be imported.', 'seo-studio' );
			setError( message );
		} finally {
			setBusy( false );
		}
	};

	return (
		<div className="seostudio-search-import">
			<h2>{ __( 'Search Console', 'seo-studio' ) }</h2>
			<p className="seostudio-lede">
				{ report?.note ||
					__(
						'Paste a performance export. Zoop is not connected to Search Console.',
						'seo-studio'
					) }
			</p>
			<TextareaControl
				label={ __( 'Performance export', 'seo-studio' ) }
				help={ __(
					'Use the query, page, clicks, impressions, and position columns. Importing replaces the previous file.',
					'seo-studio'
				) }
				value={ csv }
				onChange={ setCsv }
			/>
			<p className="seostudio-actions">
				<Button
					variant="primary"
					disabled={ busy || csv.trim() === '' }
					onClick={ upload }
				>
					{ __( 'Import file', 'seo-studio' ) }
				</Button>
			</p>
			{ error ? <p role="alert">{ error }</p> : null }
			{ report ? (
				<>
					<dl className="seostudio-evidence">
						<div>
							<dt>{ __( 'Rows imported', 'seo-studio' ) }</dt>
							<dd>
								{ report.rows }
								{ report.skipped > 0
									? ` (${ report.skipped } ${ __(
											'skipped',
											'seo-studio'
									  ) })`
									: '' }
							</dd>
						</div>
						<div>
							<dt>
								{ __(
									'Rows with a usable page address',
									'seo-studio'
								) }
							</dt>
							<dd>{ report.rows_with_page }</dd>
						</div>
						<div>
							<dt>
								{ __(
									'Rows without a usable page address',
									'seo-studio'
								) }
							</dt>
							<dd>{ report.rows_without_page }</dd>
						</div>
					</dl>
					{ report.top.length === 0 ? (
						<p className="seostudio-muted">
							{ __( 'No imported queries yet.', 'seo-studio' ) }
						</p>
					) : (
						<div className="seostudio-table-wrap">
							<table className="seostudio-table">
								<thead>
									<tr>
										<th>{ __( 'Query', 'seo-studio' ) }</th>
										<th>{ __( 'Page', 'seo-studio' ) }</th>
										<th>
											{ __( 'Clicks', 'seo-studio' ) }
										</th>
										<th>
											{ __(
												'Impressions',
												'seo-studio'
											) }
										</th>
										<th>
											{ __( 'Position', 'seo-studio' ) }
										</th>
									</tr>
								</thead>
								<tbody>
									{ report.top.map( ( row ) => (
										<tr
											key={ `${ row.query }-${ row.page }` }
										>
											<td>{ row.query }</td>
											<td>{ row.page }</td>
											<td>{ row.clicks }</td>
											<td>{ row.impressions }</td>
											<td>
												{ row.position === null
													? ''
													: row.position }
											</td>
										</tr>
									) ) }
								</tbody>
							</table>
						</div>
					) }
				</>
			) : null }
		</div>
	);
}
