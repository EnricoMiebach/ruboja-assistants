/**
 * Block editor UI of the "Ruboja Assistant" block.
 *
 * Plain ES2017 with wp.element.createElement, so the plugin needs no build step.
 * Attributes and metadata come from block.json (registered on the server).
 */
( function ( wp ) {
	'use strict';

	const { registerBlockType } = wp.blocks;
	const { createElement: el, Fragment, useEffect, useState } = wp.element;
	const { __, sprintf } = wp.i18n;
	const { InspectorControls, useBlockProps, PanelColorSettings } = wp.blockEditor;
	const { PanelBody, Placeholder, RadioControl, RangeControl, SelectControl, Spinner, TextControl, Notice } = wp.components;
	const apiFetch = wp.apiFetch;

	let assistantsRequest = null;

	/**
	 * Loads the released assistants once per editor session.
	 *
	 * @return {Promise<Array>} Released assistants.
	 */
	function loadAssistants() {
		if ( ! assistantsRequest ) {
			assistantsRequest = apiFetch( { path: '/ruboja-assistants/v1/assistants' } );
		}
		return assistantsRequest;
	}

	function useAssistants() {
		const [ state, setState ] = useState( { loading: true, items: [], error: '' } );

		useEffect( () => {
			let active = true;
			loadAssistants()
				.then( ( items ) => active && setState( { loading: false, items, error: '' } ) )
				.catch( ( error ) => active && setState( { loading: false, items: [], error: error.message || '' } ) );
			return () => {
				active = false;
			};
		}, [] );

		return state;
	}

	function Edit( { attributes, setAttributes } ) {
		const { assistantId, mode, height, title, buttonLabel, color, position } = attributes;
		const { loading, items, error } = useAssistants();
		const blockProps = useBlockProps( { className: 'ruboja-assistant-block' } );
		const selected = items.find( ( item ) => item.id === assistantId );

		const assistantSelect = el( SelectControl, {
			label: __( 'Assistant', 'ruboja-assistants' ),
			value: assistantId,
			options: [ { value: '', label: __( 'Choose an assistant …', 'ruboja-assistants' ) } ].concat(
				items.map( ( item ) => ( { value: item.id, label: item.title } ) )
			),
			onChange: ( value ) => setAttributes( { assistantId: value } ),
			__nextHasNoMarginBottom: true,
		} );

		const inspector = el(
			InspectorControls,
			null,
			el(
				PanelBody,
				{ title: __( 'Assistant', 'ruboja-assistants' ), initialOpen: true },
				assistantSelect,
				el( RadioControl, {
					label: __( 'Display', 'ruboja-assistants' ),
					selected: mode,
					options: [
						{ value: 'inline', label: __( 'Inline in the content', 'ruboja-assistants' ) },
						{ value: 'floating', label: __( 'Floating chat button', 'ruboja-assistants' ) },
					],
					onChange: ( value ) => setAttributes( { mode: value } ),
				} ),
				'inline' === mode &&
					el( RangeControl, {
						label: __( 'Height (px)', 'ruboja-assistants' ),
						value: height,
						min: 320,
						max: 2000,
						step: 10,
						onChange: ( value ) => setAttributes( { height: value || 720 } ),
						__nextHasNoMarginBottom: true,
					} ),
				el( TextControl, {
					label: __( 'Title', 'ruboja-assistants' ),
					help: __( 'Leave empty to use the title from Ruboja.', 'ruboja-assistants' ),
					value: title,
					onChange: ( value ) => setAttributes( { title: value } ),
					__nextHasNoMarginBottom: true,
				} ),
				'floating' === mode &&
					el( TextControl, {
						label: __( 'Button label', 'ruboja-assistants' ),
						value: buttonLabel,
						onChange: ( value ) => setAttributes( { buttonLabel: value } ),
						__nextHasNoMarginBottom: true,
					} ),
				'floating' === mode &&
					el( RadioControl, {
						label: __( 'Position', 'ruboja-assistants' ),
						selected: position,
						options: [
							{ value: 'right', label: __( 'Bottom right', 'ruboja-assistants' ) },
							{ value: 'left', label: __( 'Bottom left', 'ruboja-assistants' ) },
						],
						onChange: ( value ) => setAttributes( { position: value } ),
					} )
			),
			el( PanelColorSettings, {
				title: __( 'Color', 'ruboja-assistants' ),
				initialOpen: false,
				colorSettings: [
					{
						value: color || undefined,
						onChange: ( value ) => setAttributes( { color: value || '' } ),
						label: __( 'Accent color', 'ruboja-assistants' ),
					},
				],
			} )
		);

		let body;

		if ( loading ) {
			body = el( Spinner );
		} else if ( error ) {
			body = el( Notice, { status: 'error', isDismissible: false }, error );
		} else if ( ! items.length ) {
			body = el(
				'p',
				null,
				__( 'No assistant is released yet. Release assistants under Settings → Ruboja Assistants.', 'ruboja-assistants' )
			);
		} else if ( ! selected ) {
			body = assistantSelect;
		} else {
			body = el(
				'p',
				null,
				sprintf(
					/* translators: %s: assistant title. */
					'floating' === mode
						? __( '"%s" appears as a floating chat button on the page.', 'ruboja-assistants' )
						: __( '"%s" is shown here in the content.', 'ruboja-assistants' ),
					title || selected.title
				)
			);
		}

		return el(
			Fragment,
			null,
			inspector,
			el(
				'div',
				blockProps,
				el(
					Placeholder,
					{
						icon: 'format-chat',
						label: __( 'Ruboja Assistant', 'ruboja-assistants' ),
						className: 'ruboja-assistant-block__placeholder',
					},
					body
				)
			)
		);
	}

	registerBlockType( 'ruboja/assistant', {
		edit: Edit,
		save: () => null,
	} );
} )( window.wp );
