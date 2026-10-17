import {
	Button,
	CheckboxControl,
	Notice,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	fetchSettings,
	previewMigration,
	saveSettings,
	startMigration,
	type StudioSettings,
} from '../../shared/api';

const empty: StudioSettings = {
	mode: 'analysis',
	site_title_separator: '–',
	home_title: '',
	home_description: '',
	batch_size: 25,
	batch_pause_ms: 0,
	uninstall_delete_data: false,
	local_business: {
		enabled: false,
		name: '',
		street: '',
		city: '',
		telephone: '',
	},
};

export function SettingsPanel() {
	const [ settings, setSettings ] = useState< StudioSettings >( empty );
	const [ notice, setNotice ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ source, setSource ] = useState( 'yoast' );
	const [ counts, setCounts ] = useState< Record< string, number > | null >(
		null
	);

	useEffect( () => {
		fetchSettings()
			.then( ( next ) => {
				setSettings( {
					...empty,
					...next,
					local_business: {
						...empty.local_business,
						...next.local_business,
					},
				} );
				setError( '' );
			} )
			.catch( () =>
				setError( __( 'Settings could not be loaded.', 'seo-studio' ) )
			);
	}, [] );

	async function save() {
		setNotice( '' );
		setError( '' );
		try {
			const saved = await saveSettings( settings );
			setSettings( {
				...empty,
				...saved,
				local_business: {
					...empty.local_business,
					...saved.local_business,
				},
			} );
			setNotice( __( 'Settings saved.', 'seo-studio' ) );
		} catch ( caught ) {
			const message =
				caught && typeof caught === 'object' && 'message' in caught
					? String( ( caught as { message: string } ).message )
					: __( 'Settings could not be saved.', 'seo-studio' );
			setError( message );
		}
	}

	async function preview() {
		setError( '' );
		try {
			const result = await previewMigration( source );
			setCounts( result.counts );
		} catch ( caught ) {
			const message =
				caught && typeof caught === 'object' && 'message' in caught
					? String( ( caught as { message: string } ).message )
					: __( 'The preview failed.', 'seo-studio' );
			setError( message );
		}
	}

	async function migrate() {
		setError( '' );
		try {
			const result = await startMigration( source );
			setNotice(
				`${ __( 'Import queued.', 'seo-studio' ) } ${ result.run_id }`
			);
		} catch ( caught ) {
			const message =
				caught && typeof caught === 'object' && 'message' in caught
					? String( ( caught as { message: string } ).message )
					: __( 'The import could not be started.', 'seo-studio' );
			setError( message );
		}
	}

	const local = settings.local_business;

	return (
		<div className="seostudio-settings">
			<h2>{ __( 'Settings', 'seo-studio' ) }</h2>
			{ notice ? (
				<Notice status="success" isDismissible={ false }>
					{ notice }
				</Notice>
			) : null }
			{ error ? (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) : null }
			<SelectControl
				label={ __( 'Mode', 'seo-studio' ) }
				value={ settings.mode }
				options={ [
					{
						label: __( 'Analysis only', 'seo-studio' ),
						value: 'analysis',
					},
					{ label: __( 'Owner', 'seo-studio' ), value: 'owner' },
				] }
				onChange={ ( value ) =>
					setSettings( {
						...settings,
						mode: value === 'owner' ? 'owner' : 'analysis',
					} )
				}
			/>
			<TextControl
				label={ __( 'Home title', 'seo-studio' ) }
				value={ settings.home_title }
				onChange={ ( value ) =>
					setSettings( { ...settings, home_title: value } )
				}
			/>
			<TextControl
				label={ __( 'Home description', 'seo-studio' ) }
				value={ settings.home_description }
				onChange={ ( value ) =>
					setSettings( { ...settings, home_description: value } )
				}
			/>
			<TextControl
				label={ __( 'Title separator', 'seo-studio' ) }
				value={ settings.site_title_separator }
				onChange={ ( value ) =>
					setSettings( {
						...settings,
						site_title_separator: value,
					} )
				}
			/>
			<h3>{ __( 'Local business', 'seo-studio' ) }</h3>
			<CheckboxControl
				label={ __( 'Add a LocalBusiness schema node', 'seo-studio' ) }
				checked={ local.enabled }
				onChange={ ( value ) =>
					setSettings( {
						...settings,
						local_business: { ...local, enabled: value },
					} )
				}
			/>
			<TextControl
				label={ __( 'Business name', 'seo-studio' ) }
				value={ local.name }
				onChange={ ( value ) =>
					setSettings( {
						...settings,
						local_business: { ...local, name: value },
					} )
				}
			/>
			<TextControl
				label={ __( 'Street', 'seo-studio' ) }
				value={ local.street }
				onChange={ ( value ) =>
					setSettings( {
						...settings,
						local_business: { ...local, street: value },
					} )
				}
			/>
			<TextControl
				label={ __( 'City', 'seo-studio' ) }
				value={ local.city }
				onChange={ ( value ) =>
					setSettings( {
						...settings,
						local_business: { ...local, city: value },
					} )
				}
			/>
			<TextControl
				label={ __( 'Telephone', 'seo-studio' ) }
				value={ local.telephone }
				onChange={ ( value ) =>
					setSettings( {
						...settings,
						local_business: { ...local, telephone: value },
					} )
				}
			/>
			<CheckboxControl
				label={ __(
					'Delete data when Zoop is uninstalled',
					'seo-studio'
				) }
				checked={ settings.uninstall_delete_data }
				onChange={ ( value ) =>
					setSettings( {
						...settings,
						uninstall_delete_data: value,
					} )
				}
			/>
			<p>
				<Button variant="primary" onClick={ save }>
					{ __( 'Save settings', 'seo-studio' ) }
				</Button>
			</p>
			<h3>{ __( 'Migration', 'seo-studio' ) }</h3>
			<SelectControl
				label={ __( 'Source plugin', 'seo-studio' ) }
				value={ source }
				options={ [
					{ label: 'Yoast SEO', value: 'yoast' },
					{ label: 'Rank Math', value: 'rankmath' },
					{ label: 'SEOPress', value: 'seopress' },
				] }
				onChange={ setSource }
			/>
			<p>
				<Button variant="secondary" onClick={ preview }>
					{ __( 'Preview import', 'seo-studio' ) }
				</Button>{ ' ' }
				<Button variant="secondary" onClick={ migrate }>
					{ __( 'Start import', 'seo-studio' ) }
				</Button>
			</p>
			{ counts ? (
				<ul className="seostudio-migration-counts">
					<li>
						{ __( 'Posts:', 'seo-studio' ) } { counts.posts ?? 0 }
					</li>
					<li>
						{ __( 'Titles:', 'seo-studio' ) } { counts.titles ?? 0 }
					</li>
					<li>
						{ __( 'Descriptions:', 'seo-studio' ) }{ ' ' }
						{ counts.descriptions ?? 0 }
					</li>
					<li>
						{ __( 'Keyphrases:', 'seo-studio' ) }{ ' ' }
						{ counts.keyphrases ?? 0 }
					</li>
					<li>
						{ __( 'Robots:', 'seo-studio' ) } { counts.robots ?? 0 }
					</li>
					<li>
						{ __( 'Canonicals:', 'seo-studio' ) }{ ' ' }
						{ counts.canonicals ?? 0 }
					</li>
					<li data-testid="migration-overwrites">
						{ __(
							'Posts where the import replaces existing SEO Studio values:',
							'seo-studio'
						) }{ ' ' }
						{ counts.overwrites ?? 0 }
					</li>
				</ul>
			) : null }
		</div>
	);
}
