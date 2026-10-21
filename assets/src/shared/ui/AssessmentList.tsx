import { __, sprintf } from '@wordpress/i18n';
import { assessmentTitle } from './assessment-labels';

export interface ListedAssessment {
	id: string;
	category?: string;
	status?: string;
	rating?: string;
	earned?: number;
	max?: number;
	text?: string;
	explanation?: string;
	recommendation?: string;
}

interface AssessmentListProps {
	items: ListedAssessment[];
}

const statusWord: Record< string, string > = {
	pass: __( 'Good', 'seo-studio' ),
	partial: __( 'Ok', 'seo-studio' ),
	fail: __( 'Poor', 'seo-studio' ),
	not_checked: __( 'Not checked in this mode', 'seo-studio' ),
	not_applicable: __( 'Not applicable', 'seo-studio' ),
	red: __( 'Poor', 'seo-studio' ),
	orange: __( 'Ok', 'seo-studio' ),
	green: __( 'Good', 'seo-studio' ),
	grey: __( 'Not analysed yet', 'seo-studio' ),
};

export function AssessmentList( { items }: AssessmentListProps ) {
	const groups = new Map< string, ListedAssessment[] >();
	items.forEach( ( item ) => {
		const key = item.category || __( 'Content', 'seo-studio' );
		const list = groups.get( key ) ?? [];
		list.push( item );
		groups.set( key, list );
	} );

	return (
		<div className="seostudio-assessments">
			{ Array.from( groups.entries() ).map( ( [ category, rows ] ) => (
				<details key={ category } className="seostudio-check-group">
					<summary>
						<span>{ categoryLabel( category ) }</span>
						<span>{ groupNote( rows ) }</span>
					</summary>
					{ rows.map( ( row ) => {
						const word =
							statusWord[ row.status || row.rating || 'grey' ] ||
							__( 'Not analysed yet', 'seo-studio' );
						const found = row.explanation || row.text || '';
						const action = row.recommendation || '';
						return (
							<details key={ row.id } data-assessment={ row.id }>
								<summary>
									{ assessmentTitle( row.id ) }{ ' ' }
									<span className="seostudio-chip">
										{ word }
									</span>
								</summary>
								{ found ? (
									<p>
										<strong>
											{ __(
												'What Zoop found:',
												'seo-studio'
											) }
										</strong>{ ' ' }
										{ found }
									</p>
								) : null }
								{ action ? (
									<p>
										<strong>
											{ __(
												'Suggested action:',
												'seo-studio'
											) }
										</strong>{ ' ' }
										{ action }
									</p>
								) : null }
								<p className="seostudio-technical">
									{ typeof row.earned === 'number' &&
									typeof row.max === 'number'
										? sprintf(
												/* translators: 1: assessment id, 2: points earned, 3: points available. */
												__(
													'%1$s — %2$d/%3$d',
													'seo-studio'
												),
												row.id,
												row.earned,
												row.max
										  )
										: row.id }
								</p>
							</details>
						);
					} ) }
				</details>
			) ) }
		</div>
	);
}

function categoryLabel( category: string ): string {
	const labels: Record< string, string > = {
		title: __( 'SEO title', 'seo-studio' ),
		meta: __( 'Description', 'seo-studio' ),
		headings: __( 'Headings', 'seo-studio' ),
		body: __( 'Content', 'seo-studio' ),
		media: __( 'Images', 'seo-studio' ),
		url: __( 'Address', 'seo-studio' ),
		code: __( 'Technical', 'seo-studio' ),
	};
	return labels[ category ] || category;
}

function groupNote( rows: ListedAssessment[] ): string {
	const attention = rows.filter(
		( row ) =>
			row.status !== 'pass' &&
			row.status !== 'not_checked' &&
			row.status !== 'not_applicable'
	).length;
	if ( attention > 0 ) {
		return sprintf(
			/* translators: %d: number of checks that are not a clear pass. */
			__( '%d to review', 'seo-studio' ),
			attention
		);
	}
	if (
		rows.length > 0 &&
		rows.every(
			( row ) =>
				row.status === 'not_checked' || row.status === 'not_applicable'
		)
	) {
		return __( 'Not checked in this mode', 'seo-studio' );
	}
	return __( 'Good', 'seo-studio' );
}
