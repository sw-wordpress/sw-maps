/* SW Maps block: plain script (no build step); uses the WordPress script globals. */
( function ( wp ) {
	const { registerBlockType } = wp.blocks;
	const { createElement: el, Fragment } = wp.element;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { PanelBody, SelectControl, TextControl, ToggleControl, Placeholder } = wp.components;
	const { __ } = wp.i18n;
	const ServerSideRender = wp.serverSideRender;

	registerBlockType( 'sw-maps/map', {
		edit: function ( { attributes, setAttributes } ) {
			const maps = window.swmapBlockMaps || [];
			const blockProps = useBlockProps();
			const options = [ { value: 0, label: __( 'Select a map', 'sw-maps' ) } ].concat( maps );
			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Map', 'sw-maps' ) },
						el( SelectControl, {
							label: __( 'Map', 'sw-maps' ),
							value: attributes.mapId,
							options: options,
							onChange: ( value ) => setAttributes( { mapId: Number.parseInt( value, 10 ) || 0 } ),
						} ),
						el( TextControl, {
							label: __( 'Height', 'sw-maps' ),
							help: '400px, 60vh',
							value: attributes.height,
							onChange: ( value ) => setAttributes( { height: value } ),
						} ),
						el( ToggleControl, {
							label: __( 'Click to load', 'sw-maps' ),
							checked: !! attributes.clickToLoad,
							onChange: ( value ) => setAttributes( { clickToLoad: value } ),
						} ),
						el( ToggleControl, {
							label: __( 'Location list', 'sw-maps' ),
							checked: attributes.showList !== false,
							onChange: ( value ) => setAttributes( { showList: value } ),
						} )
					)
				),
				el(
					'div',
					blockProps,
					attributes.mapId
						? el( ServerSideRender, { block: 'sw-maps/map', attributes: attributes } )
						: el( Placeholder, { icon: 'location-alt', label: __( 'SW Map', 'sw-maps' ) }, __( 'Select a map in the block settings.', 'sw-maps' ) )
				)
			);
		},
		save: () => null,
	} );
} )( window.wp );
