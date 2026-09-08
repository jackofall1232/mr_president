/**
 * MRP.views.panels.congress — the Congress view: legislative standing and the record of
 * congressional decisions taken so far (from `game.memories` of type `congress`).
 */
( function ( window ) {
	'use strict';

	var MRP = window.MRP = window.MRP || {};
	var views = MRP.views = MRP.views || {};
	var panels = views.panels = views.panels || {};

	var MAX_RECORD = 8;

	function congressMemories( memories ) {
		var list = Array.isArray( memories ) ? memories : [];
		var out = [];
		var i;

		for ( i = list.length - 1; i >= 0 && out.length < MAX_RECORD; i-- ) {
			if ( list[ i ] && 'congress' === list[ i ].type ) {
				out.push( list[ i ] );
			}
		}

		return out;
	}

	function recordRows( items ) {
		var ui = MRP.ui;
		var el = ui.el;
		var rows = [];
		var i;
		var item;

		for ( i = 0; i < items.length; i++ ) {
			item = items[ i ];
			rows.push( el( 'tr', {}, [
				el( 'td', { 'class': 'mrp-num', text: ui.fmt.date( item.date ) } ),
				el( 'td', { text: item.action || '' } ),
				el( 'td', { text: item.result || '' } )
			] ) );
		}

		return rows;
	}

	/**
	 * @param {Object} state Store state.
	 * @return {Element} Congress view.
	 */
	function render( state ) {
		var ui = MRP.ui;
		var el = ui.el;
		var dash = views.dashboard;
		var game = state.game || {};
		var indicators = game.indicators || {};
		var support = 'number' === typeof indicators.congress_support ? indicators.congress_support : 0;
		var read = views.sidePanel.congressRead( support );
		var records = congressMemories( game.memories );

		return dash.viewFrame( {
			kicker: 'Office of Legislative Affairs',
			title: 'Congress',
			aside: 'Term ' + ui.fmt.num( game.term ) + ' · Month ' + ui.fmt.num( game.turn )
		}, [
			el( 'div', { 'class': 'mrp-block' }, [
				el( 'h3', { 'class': 'mrp-section-title', text: 'Floor arithmetic' } ),
				el( 'div', { 'class': 'mrp-grid' }, [
					dash.tile( 'congress_support', support ),
					dash.tile( 'approval', indicators.approval ),
					dash.tile( 'domestic_stability', indicators.domestic_stability )
				] ),
				el( 'p', { style: { 'margin-top': '12px' } }, [
					el( 'span', { 'class': 'mrp-badge mrp-badge--' + read.badge, text: 'Whip count' } )
				] ),
				el( 'p', { 'class': 'mrp-advisor__position', text: read.text } )
			] ),
			el( 'div', { 'class': 'mrp-block' }, [
				el( 'h3', { 'class': 'mrp-section-title', text: 'Legislative record' } ),
				records.length ? el( 'div', { 'class': 'mrp-table-wrap' }, [
					el( 'table', { 'class': 'mrp-table' }, [
						el( 'thead', {}, [
							el( 'tr', {}, [
								el( 'th', { scope: 'col', text: 'Month' } ),
								el( 'th', { scope: 'col', text: 'Action' } ),
								el( 'th', { scope: 'col', text: 'Result' } )
							] )
						] ),
						el( 'tbody', {}, recordRows( records ) )
					] )
				] ) : el( 'p', { 'class': 'mrp-empty', text: 'No legislative business has reached your desk yet.' } )
			] )
		] );
	}

	panels.congress = { render: render };
}( window ) );
