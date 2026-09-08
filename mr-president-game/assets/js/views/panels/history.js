/**
 * MRP.views.panels.history — the History view: a chronological timeline of the structured
 * memories the engine records for every decision and fired consequence.
 */
( function ( window ) {
	'use strict';

	var MRP = window.MRP = window.MRP || {};
	var views = MRP.views = MRP.views || {};
	var panels = views.panels = views.panels || {};

	function tagList( tags ) {
		var el = MRP.ui.el;
		var items = Array.isArray( tags ) ? tags : [];
		var nodes = [];
		var i;

		for ( i = 0; i < items.length; i++ ) {
			nodes.push( el( 'span', { 'class': 'mrp-tag', text: String( items[ i ] ) } ) );
		}
		if ( ! nodes.length ) {
			return null;
		}

		return el( 'div', { 'class': 'mrp-tags' }, nodes );
	}

	function entry( memory ) {
		var ui = MRP.ui;
		var el = ui.el;
		var head = ui.fmt.date( memory.date ) + ' · ' + ui.fmt.humanize( memory.type );

		return el( 'li', { 'class': 'mrp-timeline__item', dataset: { memory: memory.id || '' } }, [
			el( 'div', { 'class': 'mrp-timeline__date', text: head } ),
			el( 'p', { 'class': 'mrp-timeline__action', text: memory.action || '' } ),
			memory.result ? el( 'p', { 'class': 'mrp-timeline__result', text: memory.result } ) : null,
			memory.target ? el( 'p', { 'class': 'mrp-timeline__result', text: 'Subject: ' + ui.fmt.humanize( memory.target ) } ) : null,
			tagList( memory.tags )
		] );
	}

	/**
	 * @param {Object} state Store state.
	 * @return {Element} History view.
	 */
	function render( state ) {
		var ui = MRP.ui;
		var el = ui.el;
		var dash = views.dashboard;
		var game = state.game || {};
		var memories = Array.isArray( game.memories ) ? game.memories.slice() : [];
		var nodes = [];
		var i;

		memories.reverse();
		for ( i = 0; i < memories.length; i++ ) {
			if ( memories[ i ] ) {
				nodes.push( entry( memories[ i ] ) );
			}
		}

		return dash.viewFrame( {
			kicker: 'White House record',
			title: 'History',
			aside: nodes.length + ( 1 === nodes.length ? ' entry' : ' entries' )
		}, [
			nodes.length
				? el( 'ol', { 'class': 'mrp-timeline' }, nodes )
				: el( 'p', { 'class': 'mrp-empty', text: 'The record opens with your first decision.' } )
		] );
	}

	panels.history = { render: render };
}( window ) );
