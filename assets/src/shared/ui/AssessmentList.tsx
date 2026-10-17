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
				<section key={ category }>
					<h3>{ category }</h3>
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
				</section>
			) ) }
		</div>
	);
}
