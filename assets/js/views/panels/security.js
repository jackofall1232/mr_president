/**
 * MRP.views.panels.security — the Security view: crisis posture, the alliance picture,
 * and the security record drawn from memories of type `security`, `crisis` or
 * `foreign_policy`.
 */
( function ( window ) {
	'use strict';

	var MRP = window.MRP = window.MRP || {};
	var views = MRP.views = MRP.views || {};
	var panels = views.panels = views.panels || {};

	var TYPES = [ 'security', 'crisis', 'foreign_policy' ];
	var MAX_RECORD = 8;

	var POSTURE_BADGE = {
		Low: 'good',
		Guarded: 'info',
		Elevated: 'warn',
		High: 'alert',
		Severe: 'alert'
	};

	function securityMemories( memories ) {
		var list = Array.isArray( memories ) ? memories : [];
		var out = [];
		var i;

		for ( i = list.length - 1; i >= 0 && out.length < MAX_RECORD; i-- ) {
			if ( list[ i ] && -1 !== TYPES.indexOf( list[ i ].type ) ) {
				out.push( list[ i ] );
			}
		}

		return out;
	}

	function tensionRows( countries ) {
		var ui = MRP.ui;
		var el = ui.el;
		var out = [];
		var key;
		var country;

		for ( key in countries ) {
			if ( ! Object.prototype.hasOwnProperty.call( countries, key ) ) {
				continue;
			}
			country = countries[ key ] || {};
			if ( 'number' !== typeof country.military_tension || 0 === country.military_tension ) {
				continue;
			}
			out.push( el( 'tr', {}, [
				el( 'td', { text: country.name || ui.fmt.humanize( key ) } ),
				el( 'td', {}, [
					el( 'div', { 'class': 'mrp-bar' }, [
						el( 'span', { style: { left: '0', width: Math.min( 100, country.military_tension ) + '%' } } )
					] )
				] ),
				el( 'td', { 'class': 'mrp-table__num', text: ui.fmt.num( country.military_tension ) } )
			] ) );
		}

		return out;
	}

	function recordList( items ) {
		var ui = MRP.ui;
		var el = ui.el;
		var nodes = [];
		var i;
		var item;

		for ( i = 0; i < items.length; i++ ) {
			item = items[ i ];
			nodes.push( el( 'li', { 'class': 'mrp-headline' }, [
				el( 'div', { 'class': 'mrp-headline__outlet', text: ui.fmt.date( item.date ) + ' · ' + ui.fmt.humanize( item.type ) } ),
				el( 'p', { 'class': 'mrp-headline__title', text: item.action || '' } ),
				item.result ? el( 'div', { 'class': 'mrp-timeline__result', text: item.result } ) : null
			] ) );
		}

		return el( 'ul', { 'class': 'mrp-headlines' }, nodes );
	}

	/**
	 * @param {Object} state Store state.
	 * @return {Element} Security view.
	 */
	function render( state ) {
		var ui = MRP.ui;
		var el = ui.el;
		var dash = views.dashboard;
		var game = state.game || {};
		var indicators = game.indicators || {};
		var posture = indicators.security_status || 'Unknown';
		var records = securityMemories( game.memories );
		var tensions = tensionRows( game.countries || {} );

		return dash.viewFrame( {
			kicker: 'National Security Council',
			title: 'Security',
			aside: 'Posture · ' + posture
		}, [
			el( 'div', { 'class': 'mrp-block' }, [
				el( 'h3', { 'class': 'mrp-section-title', text: 'Threat posture' } ),
				el( 'p', {}, [
					el( 'span', {
						'class': 'mrp-badge mrp-badge--' + ( POSTURE_BADGE[ posture ] || 'info' ),
						text: posture
					} )
				] ),
				el( 'div', { 'class': 'mrp-grid', style: { 'margin-top': '12px' } }, [
					dash.tile( 'crisis_level', indicators.crisis_level ),
					dash.tile( 'allied_confidence', indicators.allied_confidence ),
					dash.tile( 'domestic_stability', indicators.domestic_stability )
				] )
			] ),
			el( 'div', { 'class': 'mrp-block' }, [
				el( 'h3', { 'class': 'mrp-section-title', text: 'Military tension' } ),
				tensions.length ? el( 'div', { 'class': 'mrp-table-wrap' }, [
					el( 'table', { 'class': 'mrp-table' }, [
						el( 'thead', {}, [
							el( 'tr', {}, [
								el( 'th', { scope: 'col', text: 'Actor' } ),
								el( 'th', { scope: 'col', text: 'Level' } ),
								el( 'th', { scope: 'col', 'class': 'mrp-table__num', text: 'Index' } )
							] )
						] ),
						el( 'tbody', {}, tensions )
					] )
				] ) : el( 'p', { 'class': 'mrp-empty', text: 'No active military tension is being tracked.' } )
			] ),
			el( 'div', { 'class': 'mrp-block' }, [
				el( 'h3', { 'class': 'mrp-section-title', text: 'Security record' } ),
				records.length ? recordList( records )
					: el( 'p', { 'class': 'mrp-empty', text: 'No security decisions have been taken yet.' } )
			] )
		] );
	}

	panels.security = { render: render };
}( window ) );
