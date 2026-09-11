/**
 * MRP.views.title — the title screen.
 *
 * Offers "Continue" when the user has saved games (from `listGames()`), preferring the
 * game id remembered in localStorage, plus a list of every save and a New Game action.
 */
( function ( window ) {
	'use strict';

	var MRP = window.MRP = window.MRP || {};
	var views = MRP.views = MRP.views || {};

	var TAGLINE = 'You have taken the oath. From this morning the economy, the alliances, ' +
		'the Congress and the world’s quieter dangers are yours to weigh, one month at a time.';

	/**
	 * Pick the save that "Continue" should resume.
	 *
	 * @param {Array}   games         Saved games.
	 * @param {?string} currentGameId Remembered id.
	 * @return {?Object} The chosen save record.
	 */
	function pickResume( games, currentGameId ) {
		var i;

		if ( ! games || ! games.length ) {
			return null;
		}
		for ( i = 0; i < games.length; i++ ) {
			if ( currentGameId && games[ i ].game_uuid === currentGameId ) {
				return games[ i ];
			}
		}

		return games[ 0 ];
	}

	function saveButton( save ) {
		var ui = MRP.ui;
		var el = ui.el;
		var label = save.president_name || 'Unnamed administration';
		var meta = ui.fmt.date( save.game_date ) + ' · Month ' + ui.fmt.num( save.turn_number );

		return el( 'button', {
			type: 'button',
			'class': 'mrp-save',
			onClick: function () {
				MRP.actions.continueGame( save.game_uuid );
			}
		}, [
			el( 'span', { 'class': 'mrp-save__grow' }, [
				el( 'span', { 'class': 'mrp-save__name', text: 'President ' + label } ),
				el( 'span', { 'class': 'mrp-save__meta', text: meta, style: { display: 'block' } } )
			] ),
			el( 'span', { 'class': 'mrp-badge mrp-badge--info', text: 'Resume' } )
		] );
	}

	/**
	 * Render the title screen.
	 *
	 * @param {Object} state Store state.
	 * @return {Element} Screen node.
	 */
	function render( state ) {
		var ui = MRP.ui;
		var el = ui.el;
		var games = state.games || [];
		var resume = pickResume( games, state.currentGameId );
		var actions = [];
		var i;
		var saveNodes = [];

		if ( resume ) {
			actions.push( el( 'button', {
				type: 'button',
				'class': 'mrp-btn mrp-btn--primary mrp-btn--lg mrp-btn--block',
				onClick: function () {
					MRP.actions.continueGame( resume.game_uuid );
				}
			}, 'Continue — ' + ( resume.president_name || 'Administration' ) ) );
		}

		actions.push( el( 'button', {
			type: 'button',
			'class': 'mrp-btn mrp-btn--lg mrp-btn--block' + ( resume ? '' : ' mrp-btn--primary' ),
			onClick: function () {
				MRP.actions.startNew();
			}
		}, 'New Administration' ) );

		for ( i = 0; i < games.length; i++ ) {
			saveNodes.push( saveButton( games[ i ] ) );
		}

		return el( 'div', { 'class': 'mrp-screen mrp-animate-in' }, [
			el( 'div', { 'class': 'mrp-screen__inner' }, [
				el( 'div', { 'class': 'mrp-hero' }, [
					el( 'p', { 'class': 'mrp-hero__kicker', text: 'Executive Office' } ),
					el( 'h1', { 'class': 'mrp-hero__title', text: 'Mr. President' } ),
					el( 'div', { 'class': 'mrp-hero__rule' } ),
					el( 'p', { 'class': 'mrp-hero__tagline', text: TAGLINE } ),
					el( 'div', { 'class': 'mrp-hero__actions' }, actions )
				] ),
				saveNodes.length ? el( 'div', { 'class': 'mrp-saves' }, [
					el( 'h2', { 'class': 'mrp-section-title', text: 'Saved administrations' } ),
					saveNodes
				] ) : null,
				state.busy ? el( 'div', { 'class': 'mrp-loading' }, [
					el( 'div', { 'class': 'mrp-spinner' } ),
					el( 'p', { 'class': 'mrp-loading__label', text: 'Reading the files' } )
				] ) : null
			] )
		] );
	}

	views.title = { render: render, pickResume: pickResume };
}( window ) );
