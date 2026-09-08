/**
 * MRP.views.panels.economy — the Economy view.
 *
 * Reads `game.indicators` (spec section 8.4) and the economy-relevant slice of the
 * current turn report. No derivation happens here that the server has not already made:
 * `economy_status` is the View_Model's label, not a client guess.
 */
( function ( window ) {
	'use strict';

	var MRP = window.MRP = window.MRP || {};
	var views = MRP.views = MRP.views || {};
	var panels = views.panels = views.panels || {};

	var KEYS = [ 'gdp_growth', 'inflation', 'unemployment', 'deficit', 'national_debt' ];

	var STATUS_BADGE = {
		Expanding: 'good',
		Stable: 'info',
		Softening: 'warn',
		Contracting: 'alert'
	};

	function economyDeltas( report ) {
		var list = ( report && report.indicator_deltas ) || [];
		var out = [];
		var i;
		var key;

		for ( i = 0; i < list.length; i++ ) {
			key = String( ( list[ i ] && list[ i ].path ) || '' ).replace( 'public.', '' );
			if ( -1 !== KEYS.indexOf( key ) ) {
				out.push( list[ i ] );
			}
		}

		return out;
	}

	/**
	 * @param {Object} state Store state.
	 * @return {Element} Economy view.
	 */
	function render( state ) {
		var ui = MRP.ui;
		var el = ui.el;
		var dash = views.dashboard;
		var game = state.game || {};
		var indicators = game.indicators || {};
		var report = game.turn_report;
		var status = indicators.economy_status || 'Unknown';
		var tiles = [];
		var i;

		for ( i = 0; i < KEYS.length; i++ ) {
			tiles.push( dash.tile( KEYS[ i ], indicators[ KEYS[ i ] ] ) );
		}

		return dash.viewFrame( {
			kicker: 'Council of Economic Advisers',
			title: 'The Economy',
			aside: game.month_label || ui.fmt.date( game.date )
		}, [
			el( 'div', { 'class': 'mrp-block' }, [
				el( 'h3', { 'class': 'mrp-section-title', text: 'Standing assessment' } ),
				el( 'p', {}, [
					el( 'span', {
						'class': 'mrp-badge mrp-badge--' + ( STATUS_BADGE[ status ] || 'info' ),
						text: status
					} )
				] ),
				el( 'p', {
					'class': 'mrp-advisor__position',
					text: 'Growth of ' + ui.fmt.num( indicators.gdp_growth, 1 ) + '% against inflation of ' +
						ui.fmt.num( indicators.inflation, 1 ) + '% and unemployment at ' +
						ui.fmt.num( indicators.unemployment, 1 ) + '%. The deficit runs at ' +
						ui.fmt.money( indicators.deficit ) + ' a year against a debt of ' +
						ui.fmt.money( indicators.national_debt ) + '.'
				} )
			] ),
			el( 'div', { 'class': 'mrp-block' }, [
				el( 'h3', { 'class': 'mrp-section-title', text: 'Indicators' } ),
				el( 'div', { 'class': 'mrp-grid' }, tiles )
			] ),
			el( 'div', { 'class': 'mrp-block' }, [
				el( 'h3', { 'class': 'mrp-section-title', text: 'Movement this month' } ),
				views.outcome.chips( economyDeltas( report ), 'The economy held steady this month.' )
			] )
		] );
	}

	panels.economy = { render: render, KEYS: KEYS };
}( window ) );
