import { __, sprintf } from '@wordpress/i18n';

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
	not_checked: __( 'Not scored', 'seo-studio' ),
	red: __( 'Poor', 'seo-studio' ),
	orange: __( 'Ok', 'seo-studio' ),
	green: __( 'Good', 'seo-studio' ),
	grey: __( 'Not scored', 'seo-studio' ),
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
				<section key={ category }>
					<h3>{ category }</h3>
					{ rows.map( ( row ) => {
						const word =
							statusWord[ row.status || row.rating || 'grey' ] ||
							__( 'Not scored', 'seo-studio' );
						const detail =
							row.recommendation ||
							row.explanation ||
							row.text ||
							'';
						return (
							<details key={ row.id } data-assessment={ row.id }>
								<summary>
									{ row.id }{ ' ' }
									<span className="seostudio-chip">
										{ word }
									</span>
									{ typeof row.earned === 'number' &&
									typeof row.max === 'number'
										? ' ' +
										  sprintf(
												/* translators: 1: points earned, 2: points available */
												__(
													'%1$d / %2$d',
													'seo-studio'
												),
												row.earned,
												row.max
										  )
										: null }
								</summary>
								{ detail ? <p>{ detail }</p> : null }
								{ row.explanation && row.recommendation ? (
									<p>{ row.explanation }</p>
								) : null }
							</details>
						);
					} ) }
				</section>
			) ) }
		</div>
	);
}
