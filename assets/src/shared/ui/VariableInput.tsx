import { __ } from '@wordpress/i18n';

const tokens = [
	'%%title%%',
	'%%sitename%%',
	'%%sep%%',
	'%%excerpt%%',
	'%%category%%',
	'%%tagline%%',
];

interface VariableInputProps {
	id: string;
	label: string;
	value: string;
	onChange: ( value: string ) => void;
	multiline?: boolean;
	hint?: string;
}

export function VariableInput( {
	id,
	label,
	value,
	onChange,
	multiline = false,
	hint,
}: VariableInputProps ) {
	const insert = ( token: string ) => onChange( `${ value }${ token }` );
	const field = multiline ? (
		<textarea
			id={ id }
			value={ value }
			rows={ 3 }
			onChange={ ( event ) => onChange( event.target.value ) }
		/>
	) : (
		<input
			id={ id }
			type="text"
			value={ value }
			onChange={ ( event ) => onChange( event.target.value ) }
		/>
	);

	return (
		<label className="seostudio-field" htmlFor={ id }>
			{ label }
			{ field }
			<span className="seostudio-chips">
				{ tokens.map( ( token ) => (
					<button
						key={ token }
						type="button"
						onClick={ () => insert( token ) }
					>
						{ token }
					</button>
				) ) }
			</span>
			{ hint ? (
				<span>{ hint }</span>
			) : (
				<span className="screen-reader-text">
					{ __( 'Insert a variable', 'seo-studio' ) }
				</span>
			) }
		</label>
	);
}
