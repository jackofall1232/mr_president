/**
 * MRP.views.devPanel — the developer drawer.
 *
 * Renders only what the server chose to send in `game.debug`, which the View_Model
 * populates exclusively when developer mode is on AND the user has `manage_options`
 * (spec sections 0.4 and 8.4). The client never reconstructs hidden state on its own; if
 * `debug` is absent the drawer says so.
 */
( function ( window ) {
	'use strict';

	var MRP = window.MRP = window.MRP || {};
	var views = MRP.views = MRP.views || {};

	var SECTIONS = [
		{ key: 'hidden', label: 'Hidden state' },
		{ key: 'flags', label: 'Flags' },
		{ key: 'counters', label: 'Counters' },
		{ key: 'delayed_queue', label: 'Delayed queue' },
		{ key: 'cooldowns', label: 'Cooldowns' },
		{ key: 'seen_events', label: 'Seen events' },
		{ key: 'eligibility', label: 'Event eligibility' },
		{ key: 'raw_state', label: 'Raw state' }
	];

	function pretty( value ) {
		try {
			return JSON.stringify( value, null, 2 );
		} catch ( e ) {
			return String( value );
		}
	}

	function section( label, value ) {
		var el = MRP.ui.el;

		return el( 'section', { 'class': 'mrp-dev-section' }, [
			el( 'h4', { 'class': 'mrp-dev-section__title', text: label } ),
			el( 'pre', { 'class': 'mrp-pre mrp-scroll', text: pretty( value ) } )
		] );
	}

	/**
	 * Render the developer drawer.
	 *
	 * @param {Object} state Store state.
	 * @return {Element} Drawer node.
	 */
	function render( state ) {
		var ui = MRP.ui;
		var el = ui.el;
		var game = state.game || {};
		var debug = game.debug || null;
		var body = [];
		var i;
		var node;
		var detach;

		if ( ! debug ) {
			body.push( el( 'p', {
				'class': 'mrp-empty',
				text: 'The server sent no debug payload. Developer mode requires the option to be on and the user to have manage_options.'
			} ) );
		} else {
			body.push( section( 'Determinism', { seed: debug.seed, rng: debug.rng } ) );
			for ( i = 0; i < SECTIONS.length; i++ ) {
				if ( undefined !== debug[ SECTIONS[ i ].key ] ) {
					body.push( section( SECTIONS[ i ].label, debug[ SECTIONS[ i ].key ] ) );
				}
			}
		}

		node = el( 'aside', {
			'class': 'mrp-drawer',
			role: 'dialog',
			'aria-modal': 'false',
			'aria-label': 'Developer panel',
			onKeydown: function ( event ) {
				if ( 'Escape' === event.key ) {
					event.stopPropagation();
					MRP.actions.toggleDev( false );
				}
			}
		}, [
			el( 'header', { 'class': 'mrp-drawer__head' }, [
				el( 'span', { 'class': 'mrp-drawer__title', text: 'Developer · schema v' + ( ( game.meta && game.meta.schema_version ) || '?' ) } ),
				el( 'button', {
					type: 'button',
					'class': 'mrp-btn mrp-btn--ghost',
					onClick: function () {
						MRP.actions.toggleDev( false );
					}
				}, 'Close' )
			] ),
			el( 'div', { 'class': 'mrp-drawer__body mrp-scroll' }, body )
		] );

		detach = ui.trapFocus( node );
		node.addEventListener( 'mrp:detach', detach );

		window.setTimeout( function () {
			if ( node.isConnected ) {
				ui.focusFirst( node );
			}
		}, 0 );

		return node;
	}

	views.devPanel = { render: render, SECTIONS: SECTIONS };
}( window ) );
