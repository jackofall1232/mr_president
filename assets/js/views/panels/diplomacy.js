/**
 * MRP.views.panels.diplomacy — the Diplomacy view: one row per country or bloc from
 * `game.countries`, with relationship on a signed bar and the remaining 0–100 measures
 * as plain numbers.
 */
( function ( window ) {
	'use strict';

	var MRP = window.MRP = window.MRP || {};
	var views = MRP.views = MRP.views || {};
	var panels = views.panels = views.panels || {};

	var COLUMNS = [
		{ key: 'trust', label: 'Trust' },
		{ key: 'trade_dependency', label: 'Trade' },
		{ key: 'military_tension', label: 'Tension' },
		{ key: 'cooperation', label: 'Coop.' }
	];

	var KIND_BADGE = {
		ally_bloc: 'good',
		partner: 'info',
		rival_power: 'warn',
		rival_state: 'alert'
	};

	/**
	 * Signed −100..100 relationship bar.
	 *
	 * @param {number} value Relationship.
	 * @return {Element} Bar node.
	 */
	function relationshipBar( value ) {
		var el = MRP.ui.el;
		var magnitude = Math.min( 100, Math.abs( 'number' === typeof value ? value : 0 ) ) / 2;

		return el( 'div', { 'class': 'mrp-bar mrp-bar--signed', role: 'img', 'aria-label': 'Relationship ' + MRP.ui.fmt.num( value ) }, [
			el( 'span', {
				'class': 0 <= value ? 'mrp-bar__pos' : 'mrp-bar__neg',
				style: { width: magnitude + '%' }
			} )
		] );
	}

	function rows( countries ) {
		var ui = MRP.ui;
		var el = ui.el;
		var out = [];
		var key;
		var country;
		var cells;
		var i;

		for ( key in countries ) {
			if ( ! Object.prototype.hasOwnProperty.call( countries, key ) ) {
				continue;
			}
			country = countries[ key ] || {};
			cells = [
				el( 'td', {}, [
					el( 'div', { text: country.name || ui.fmt.humanize( key ) } ),
					el( 'span', {
						'class': 'mrp-badge mrp-badge--' + ( KIND_BADGE[ country.kind ] || 'info' ),
						text: ui.fmt.humanize( country.kind || 'state' )
					} )
				] ),
				el( 'td', {}, [
					relationshipBar( country.relationship ),
					el( 'span', { 'class': 'mrp-save__meta', text: ui.fmt.delta( country.relationship, 0 ) } )
				] )
			];
			for ( i = 0; i < COLUMNS.length; i++ ) {
				cells.push( el( 'td', {
					'class': 'mrp-table__num',
					text: 'number' === typeof country[ COLUMNS[ i ].key ] ? ui.fmt.num( country[ COLUMNS[ i ].key ] ) : '—'
				} ) );
			}
			out.push( el( 'tr', { dataset: { country: key } }, cells ) );
		}

		return out;
	}

	/**
	 * @param {Object} state Store state.
	 * @return {Element} Diplomacy view.
	 */
	function render( state ) {
		var ui = MRP.ui;
		var el = ui.el;
		var dash = views.dashboard;
		var game = state.game || {};
		var indicators = game.indicators || {};
		var countries = game.countries || {};
		var headers = [
			el( 'th', { scope: 'col', text: 'Country or bloc' } ),
			el( 'th', { scope: 'col', text: 'Relationship' } )
		];
		var i;
		var body = rows( countries );

		for ( i = 0; i < COLUMNS.length; i++ ) {
			headers.push( el( 'th', { scope: 'col', 'class': 'mrp-table__num', text: COLUMNS[ i ].label } ) );
		}

		return dash.viewFrame( {
			kicker: 'Department of State',
			title: 'Diplomacy',
			aside: ( indicators.allied_confidence_label || '' ) + ' alliances'
		}, [
			el( 'div', { 'class': 'mrp-block' }, [
				el( 'h3', { 'class': 'mrp-section-title', text: 'Standing abroad' } ),
				el( 'div', { 'class': 'mrp-grid' }, [
					dash.tile( 'global_influence', indicators.global_influence ),
					dash.tile( 'allied_confidence', indicators.allied_confidence )
				] )
			] ),
			el( 'div', { 'class': 'mrp-block' }, [
				el( 'h3', { 'class': 'mrp-section-title', text: 'Bilateral picture' } ),
				body.length ? el( 'div', { 'class': 'mrp-table-wrap' }, [
					el( 'table', { 'class': 'mrp-table' }, [
						el( 'thead', {}, [ el( 'tr', {}, headers ) ] ),
						el( 'tbody', {}, body )
					] )
				] ) : el( 'p', { 'class': 'mrp-empty', text: 'No diplomatic relationships are on file.' } )
			] )
		] );
	}

	panels.diplomacy = { render: render, COLUMNS: COLUMNS };
}( window ) );
