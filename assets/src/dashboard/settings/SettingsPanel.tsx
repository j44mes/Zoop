import {
	Button,
	CheckboxControl,
	Notice,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
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
	const [ confirmDelete, setConfirmDelete ] = useState( false );
	const [ ready, setReady ] = useState( false );

	useEffect( () => {
		let cancelled = false;
		fetchSettings()
			.then( ( next ) => {
				if ( cancelled ) {
					return;
				}
				setSettings( {
					...empty,
					...next,
					local_business: {
						...empty.local_business,
						...next.local_business,
					},
				} );
				setError( '' );
				setReady( true );
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setError(
						__( 'Settings could not be loaded.', 'seo-studio' )
					);
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [] );

	async function save() {
		setNotice( '' );
		setError( '' );
		if ( settings.uninstall_delete_data && ! confirmDelete ) {
			setError(
				__(
					'Confirm that uninstall should permanently delete Zoop data.',
					'seo-studio'
				)
			);
			return;
		}
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
				sprintf(
					/* translators: %d: import run id. */
					__( 'Import queued. %d', 'seo-studio' ),
					result.run_id
				)
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
	const robots = settings.default_robots || {
		index: true,
		follow: true,
	};
	const selectors = settings.content_selectors || {};

	if ( ! ready ) {
		return (
			<div className="seostudio-settings">
				{ error ? (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) : (
					<p className="seostudio-muted" role="status">
						{ __( 'Loading settings.', 'seo-studio' ) }
					</p>
				) }
			</div>
		);
	}

	return (
		<div className="seostudio-settings">
			<h2>{ __( 'General', 'seo-studio' ) }</h2>
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
				label={ __( 'Metadata mode', 'seo-studio' ) }
				value={ settings.mode }
				help={
					settings.publishing
						? __(
								'Zoop prints the supported search and social tags.',
								'seo-studio'
						  )
						: __(
								'Analysis-only. Another plugin, or this mode, keeps Zoop from printing tags.',
								'seo-studio'
						  )
				}
				options={ [
					{
						label: __( 'Analysis only', 'seo-studio' ),
						value: 'analysis',
					},
					{
						label: __( 'Zoop manages metadata', 'seo-studio' ),
						value: 'owner',
					},
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
			<h2>{ __( 'Analysis', 'seo-studio' ) }</h2>
			<TextControl
				label={ __( 'Batch size', 'seo-studio' ) }
				value={ String( settings.batch_size ) }
				onChange={ ( value ) =>
					setSettings( {
						...settings,
						batch_size: Number( value ) || 1,
					} )
				}
			/>
			<TextControl
				label={ __(
					'Pause between batches, milliseconds',
					'seo-studio'
				) }
				value={ String( settings.batch_pause_ms ) }
				onChange={ ( value ) =>
					setSettings( {
						...settings,
						batch_pause_ms: Number( value ) || 0,
					} )
				}
			/>
			<TextControl
				label={ __( 'Function-word language', 'seo-studio' ) }
				value={ settings.function_word_locale || 'en' }
				onChange={ ( value ) =>
					setSettings( {
						...settings,
						function_word_locale: value,
					} )
				}
			/>
			<TextControl
				label={ __( 'Post content selector', 'seo-studio' ) }
				value={ selectors.post || '' }
				help={ __(
					'Optional CSS selector used when rendering a post.',
					'seo-studio'
				) }
				onChange={ ( value ) =>
					setSettings( {
						...settings,
						content_selectors: { ...selectors, post: value },
					} )
				}
			/>
			<h2>{ __( 'Search appearance', 'seo-studio' ) }</h2>
			<CheckboxControl
				label={ __( 'Index pages by default', 'seo-studio' ) }
				checked={ robots.index !== false }
				onChange={ ( value ) =>
					setSettings( {
						...settings,
						default_robots: { ...robots, index: value },
					} )
				}
			/>
			<CheckboxControl
				label={ __( 'Follow links by default', 'seo-studio' ) }
				checked={ robots.follow !== false }
				onChange={ ( value ) =>
					setSettings( {
						...settings,
						default_robots: { ...robots, follow: value },
					} )
				}
			/>
			<h2>{ __( 'Social appearance', 'seo-studio' ) }</h2>
			<TextControl
				label={ __( 'Default social image ID', 'seo-studio' ) }
				help={ __(
					'Media library attachment ID. Leave 0 when there is no default image.',
					'seo-studio'
				) }
				value={ String( settings.og_default_image || 0 ) }
				onChange={ ( value ) =>
					setSettings( {
						...settings,
						og_default_image: Number( value ) || 0,
					} )
				}
			/>
			<h2>{ __( 'Local business', 'seo-studio' ) }</h2>
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
			<h2>{ __( 'Tools and data', 'seo-studio' ) }</h2>
			<CheckboxControl
				label={ __(
					'Delete data when Zoop is uninstalled',
					'seo-studio'
				) }
				checked={ settings.uninstall_delete_data }
				onChange={ ( value ) => {
					setConfirmDelete( false );
					setSettings( {
						...settings,
						uninstall_delete_data: value,
					} );
				} }
			/>
			{ settings.uninstall_delete_data ? (
				<CheckboxControl
					label={ __(
						'I understand this permanently deletes Zoop tables and post meta on uninstall.',
						'seo-studio'
					) }
					checked={ confirmDelete }
					onChange={ setConfirmDelete }
				/>
			) : null }
			<p>
				<Button variant="primary" onClick={ save }>
					{ __( 'Save settings', 'seo-studio' ) }
				</Button>
			</p>
			<h2>{ __( 'Import', 'seo-studio' ) }</h2>
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
							'Posts where the import replaces existing Zoop values:',
							'seo-studio'
						) }{ ' ' }
						{ counts.overwrites ?? 0 }
					</li>
				</ul>
			) : null }
		</div>
	);
}
