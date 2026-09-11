/** Campaign calendar, election ledger and the final public record. */
( function ( window ) {
	'use strict';
	var MRP = window.MRP = window.MRP || {};
	var views = MRP.views = MRP.views || {};
	function chamber( title, held, total ) {
		var el = MRP.ui.el;
		return el( 'section', { 'class': 'mrp-chamber' }, [
			el( 'p', { 'class': 'mrp-hero__kicker', text: title } ),
			el( 'strong', { 'class': 'mrp-chamber__count', text: held + ' / ' + total } ),
			el( 'p', { text: 'Administration coalition · ' + ( held > total / 2 ? 'Majority' : held === total / 2 ? 'Tied chamber' : 'Minority' ) } ),
			el( 'meter', { min: '0', max: String( total ), value: String( held ), 'aria-label': title + ' coalition seats' } ),
			el( 'p', { text: 'Opposition: ' + ( total - held ) + ' seats' } )
		] );
	}
	function chambers( campaign ) {
		return MRP.ui.el( 'div', { 'class': 'mrp-chambers' }, [
			chamber( 'House of Representatives', campaign.house, 435 ),
			chamber( 'United States Senate', ( campaign.senate_classes || [] ).reduce( function ( a, b ) { return a + b; }, 0 ), 100 )
		] );
	}
	function ledger( campaign ) {
		var el = MRP.ui.el;
		return el( 'div', { 'class': 'mrp-election-ledger' }, Object.keys( campaign.results || {} ).reverse().map( function ( key ) {
			var r = campaign.results[ key ];
			return el( 'article', { 'class': 'mrp-block' }, [
				el( 'h3', { text: r.type + ' · ' + MRP.ui.fmt.date( r.date ) } ),
				el( 'p', { text: 'Approval at the ballot: ' + MRP.ui.fmt.num( r.approval, 2 ) + '% · ' + r.senate_contested + ' Senate seats contested' } ),
				el( 'p', { text: 'House: ' + r.house + ' (' + ( r.house_change > 0 ? '+' : '' ) + r.house_change + ') · Senate: ' + r.senate + ' (' + ( r.senate_change > 0 ? '+' : '' ) + r.senate_change + ')' } ),
				r.presidential_result ? el( 'strong', { text: r.presidential_result } ) : null
			] );
		} ) );
	}
	function banner( game ) {
		var c = game.campaign || {};
		var next = [23, 47, 49, 71, 95, 97].filter( function ( turn ) { return turn > game.turn; } )[0] || 97;
		var label = next === 49 ? ( c.reelected === false ? 'Transfer of power' : 'Second inauguration' ) : next === 97 ? 'Legacy report' : next === 47 ? 'Reelection' : 'Congressional election';
		return MRP.ui.el( 'div', { 'class': 'mrp-campaign-banner', role: 'status' }, [
			MRP.ui.el( 'strong', { text: label + ' in ' + Math.max( 0, next - game.turn ) + ' months' } ),
			MRP.ui.el( 'span', { text: c.reelected === false ? 'Election lost. Serve out your term until January.' : game.term === 2 ? 'Second term — govern for your legacy.' : 'Game rule: approval >50% wins reelection; ≤50% loses.' } )
		] );
	}
	function ending( state ) {
		var el = MRP.ui.el;
		var game = state.game;
		var c = game.campaign;
		var legacy = c.legacy || {};
		var profile = game.president_profile || {};
		return el( 'div', { 'class': 'mrp-screen mrp-legacy' }, [el( 'main', { 'class': 'mrp-legacy__paper' }, [
			el( 'p', { 'class': 'mrp-hero__kicker', text: 'The Presidential Archives · ' + MRP.ui.fmt.date( legacy.date ) } ),
			el( 'h1', { text: 'The ' + game.president_name + ' Presidency' } ),
			el( 'h2', { text: c.status === 'completed' ? 'Eight years. A lasting record.' : 'A term ends. A record remains.' } ),
			el( 'p', { text: legacy.summary } ),
			el( 'p', { text: legacy.months_served + ' months served · ' + legacy.decisions + ' recorded decisions · ' + MRP.ui.fmt.num( legacy.approval, 1 ) + '% final approval' } ),
			profile.priorities ? el( 'p', { text: 'Your mandate: ' + profile.priorities.join( ', ' ) } ) : null,
			el( 'h2', { text: 'The country you leave behind' } ),
			el( 'div', { 'class': 'mrp-legacy__metrics' }, Object.keys( legacy.indicators || {} ).map( function ( key ) {
				return el( 'p', { text: key.replace( /_/g, ' ' ) + ': ' + MRP.ui.fmt.num( legacy.indicators[ key ], 1 ) } );
			} ) ),
			chambers( c ), ledger( c ),
			el( 'h2', { text: 'Recent decisions' } ),
			( game.memories || [] ).slice( -12 ).map( function ( memory ) { return el( 'p', { text: MRP.ui.fmt.date( memory.date ) + ' — ' + memory.action + ': ' + memory.result } ); } ),
			el( 'button', { type: 'button', 'class': 'mrp-btn mrp-btn--primary', onClick: function () { MRP.actions.startNew(); }, text: 'Begin another presidency' } ),
			el( 'button', { type: 'button', 'class': 'mrp-btn', onClick: function () { MRP.actions.showTitle(); }, text: 'Saved administrations' } )
		] )] );
	}
	views.campaign = { chambers: chambers, ledger: ledger, banner: banner, ending: ending };
}( window ) );
