import { Button, TextControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { fetchTopic, saveTopic, type TopicRecord } from '../shared/api';
import { topicIntentLabel, topicRoleLabel } from './topic-copy';

export function TopicPanel( { postId }: { postId: number } ) {
	const [ topic, setTopic ] = useState< TopicRecord | null >( null );
	const [ primary, setPrimary ] = useState( '' );
	const [ role, setRole ] = useState( '' );
	const [ intent, setIntent ] = useState( '' );
	const [ parent, setParent ] = useState( '' );
	const [ entities, setEntities ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ saved, setSaved ] = useState( '' );

	useEffect( () => {
		let cancelled = false;
		fetchTopic( postId )
			.then( ( next ) => {
				if ( cancelled ) {
					return;
				}
				setTopic( next );
				setPrimary( String( next.effective.primary_topic || '' ) );
				setRole( String( next.effective.page_role || '' ) );
				setIntent( String( next.effective.search_intent || '' ) );
				setParent( String( next.effective.parent_topic || '' ) );
				setEntities(
					Array.isArray( next.effective.supporting_entities )
						? next.effective.supporting_entities.join( ', ' )
						: ''
				);
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setError(
						__(
							'The topic suggestions could not be loaded.',
							'seo-studio'
						)
					);
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ postId ] );

	if ( error ) {
		return <p role="alert">{ error }</p>;
	}
	if ( ! topic ) {
		return <p>{ __( 'Loading topic suggestions.', 'seo-studio' ) }</p>;
	}

	const save = async () => {
		setSaved( '' );
		setError( '' );
		try {
			const next = await saveTopic( postId, {
				primary_topic: primary,
				page_role: role,
				search_intent: intent,
				parent_topic: parent,
				supporting_entities: entities
					.split( ',' )
					.map( ( phrase ) => phrase.trim() )
					.filter( Boolean ),
			} );
			setTopic( next );
			setPrimary( String( next.effective.primary_topic || '' ) );
			setRole( String( next.effective.page_role || '' ) );
			setIntent( String( next.effective.search_intent || '' ) );
			setParent( String( next.effective.parent_topic || '' ) );
			setEntities(
				Array.isArray( next.effective.supporting_entities )
					? next.effective.supporting_entities.join( ', ' )
					: ''
			);
			setSaved(
				__(
					'Saved. Clear a field and save again to use the suggestion.',
					'seo-studio'
				)
			);
		} catch ( _caught ) {
			setError(
				__( 'The topic corrections could not be saved.', 'seo-studio' )
			);
		}
	};

	return (
		<section className="seostudio-topic">
			<h3>{ __( 'Topic', 'seo-studio' ) }</h3>
			<p className="seostudio-lede">{ topic.note }</p>
			<TextControl
				label={ __( 'Primary topic', 'seo-studio' ) }
				help={ topic.suggestions.primary_topic.reason }
				value={ primary }
				onChange={ setPrimary }
			/>
			<label className="seostudio-field" htmlFor="seostudio-topic-role">
				{ __( 'Page role', 'seo-studio' ) }
				<select
					id="seostudio-topic-role"
					value={ role }
					onChange={ ( event ) => setRole( event.target.value ) }
				>
					<option value="">
						{ __( 'Use the suggestion', 'seo-studio' ) }
					</option>
					<option value="hub">{ topicRoleLabel( 'hub' ) }</option>
					<option value="supporting">
						{ topicRoleLabel( 'supporting' ) }
					</option>
					<option value="utility">
						{ topicRoleLabel( 'utility' ) }
					</option>
					<option value="unassigned">
						{ topicRoleLabel( 'unassigned' ) }
					</option>
				</select>
				<span className="seostudio-muted">
					{ topic.suggestions.page_role.reason }
				</span>
			</label>
			<label className="seostudio-field" htmlFor="seostudio-topic-intent">
				{ __( 'Search intent', 'seo-studio' ) }
				<select
					id="seostudio-topic-intent"
					value={ intent }
					onChange={ ( event ) => setIntent( event.target.value ) }
				>
					<option value="">
						{ __( 'Use the suggestion', 'seo-studio' ) }
					</option>
					<option value="informational">
						{ topicIntentLabel( 'informational' ) }
					</option>
					<option value="commercial">
						{ topicIntentLabel( 'commercial' ) }
					</option>
					<option value="navigational">
						{ topicIntentLabel( 'navigational' ) }
					</option>
					<option value="unassigned">
						{ topicIntentLabel( 'unassigned' ) }
					</option>
				</select>
				<span className="seostudio-muted">
					{ topic.suggestions.search_intent.reason }
				</span>
			</label>
			<TextControl
				label={ __( 'Parent topic', 'seo-studio' ) }
				help={ topic.suggestions.parent_topic.reason }
				value={ parent }
				onChange={ setParent }
			/>
			<TextControl
				label={ __( 'Supporting phrases', 'seo-studio' ) }
				help={ topic.suggestions.supporting_entities.reason }
				value={ entities }
				onChange={ setEntities }
			/>
			<p className="seostudio-actions">
				<Button variant="primary" onClick={ save }>
					{ __( 'Save topic corrections', 'seo-studio' ) }
				</Button>
			</p>
			{ saved ? <p role="status">{ saved }</p> : null }
		</section>
	);
}
