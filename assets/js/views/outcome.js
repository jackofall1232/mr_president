/**
 * MRP.views.outcome — the panel shown after a decision.
 *
 * Renders the authoritative Outcome record (spec section 4.3): the chosen course, the
 * outcome text, visible deltas as chips, fictional headlines, and the standing reminder
 * that the simulation has queued consequences the player cannot see yet.
 */
( function ( window ) {
	'use strict';

	var MRP = window.MRP = window.MRP || {};
	var views = MRP.views = MRP.views || {};

	var PENDING_NOTE = 'Some consequences may not be visible yet.';

	/**
	 * Build one delta chip.
	 *
	 * @param {Object} delta `{path, label, delta, display}`.
	 * @return {Element} Chip node.
	 */
	function chip( delta ) {
		var ui = MRP.ui;
		var el = ui.el;
		var value = ( delta && 'number' === typeof delta.delta ) ? delta.delta : 0;
		var modifier = 0 < value ? 'up' : ( 0 > value ? 'down' : 'flat' );

		return el( 'span', { 'class': 'mrp-chip mrp-chip--' + modifier, title: ( delta && delta.path ) || '' }, [
			el( 'span', { 'class': 'mrp-chip__label', text: ( delta && delta.label ) || ui.fmt.humanize( delta && delta.path ) } ),
			el( 'span', { 'class': 'mrp-chip__delta', text: ui.fmt.delta( delta ) } )
		] );
	}

	/**
	 * Build a chip row.
	 *
	 * @param {Array}  deltas     Delta records.
	 * @param {string} [emptyMsg] Message when there is nothing to show.
	 * @return {Element} Chip container or an empty-state node.
	 */
	function chips( deltas, emptyMsg ) {
		var ui = MRP.ui;
		var el = ui.el;
		var list = Array.isArray( deltas ) ? deltas : [];
		var nodes = [];
		var i;

		for ( i = 0; i < list.length; i++ ) {
			nodes.push( chip( list[ i ] ) );
		}
		if ( ! nodes.length ) {
			return el( 'p', { 'class': 'mrp-empty', text: emptyMsg || 'No measurable movement this month.' } );
		}

		return el( 'div', { 'class': 'mrp-chips' }, nodes );
	}

	/**
	 * Build a headline list.
	 *
	 * @param {Array} list Headline records `{outlet, outlet_id, title, date}`.
	 * @return {?Element} List node, or null when empty.
	 */
	function headlines( list ) {
		var ui = MRP.ui;
		var el = ui.el;
		var items = Array.isArray( list ) ? list : [];
		var nodes = [];
		var i;
		var item;

		for ( i = 0; i < items.length; i++ ) {
			item = items[ i ];
			if ( ! item || ! item.title ) {
				continue;
			}
			nodes.push( el( 'li', { 'class': 'mrp-headline' }, [
				el( 'div', { 'class': 'mrp-headline__outlet', text: item.outlet || ui.fmt.humanize( item.outlet_id ) } ),
				el( 'p', { 'class': 'mrp-headline__title', text: item.title } ),
				item.date ? el( 'div', { 'class': 'mrp-headline__date', text: ui.fmt.date( item.date ) } ) : null
			] ) );
		}
		if ( ! nodes.length ) {
			return null;
		}

		return el( 'ul', { 'class': 'mrp-headlines' }, nodes );
	}

	/**
	 * Render the outcome panel.
	 *
	 * @param {Object} outcome   Outcome record.
	 * @param {Object} [options] `{footer: Node, id: string}`.
	 * @return {?Element} Panel node, or null without an outcome.
	 */
	function render( outcome, options ) {
		var ui = MRP.ui;
		var el = ui.el;
		var opts = options || {};
		var news;
		var hiddenCount;

		if ( ! outcome ) {
			return null;
		}

		news = headlines( outcome.headlines );
		hiddenCount = parseInt( outcome.hidden_change_count, 10 ) || 0;

		return el( 'section', {
			'class': 'mrp-outcome mrp-animate-in',
			id: opts.id || 'mrp-outcome',
			tabindex: '-1',
			'aria-label': 'Outcome of your decision'
		}, [
			el( 'header', { 'class': 'mrp-outcome__head' }, [
				el( 'div', {}, [
					el( 'span', { 'class': 'mrp-kicker mrp-kicker--gold', text: 'Decision recorded' } ),
					el( 'h2', {
						'class': 'mrp-outcome__decision',
						text: outcome.choice_label || ui.fmt.humanize( outcome.choice_id )
					} )
				] ),
				el( 'span', {
					'class': 'mrp-outcome__hidden-count',
					text: hiddenCount + ( 1 === hiddenCount ? ' unseen shift' : ' unseen shifts' )
				} )
			] ),
			outcome.outcome_text ? el( 'p', { 'class': 'mrp-outcome__body', text: outcome.outcome_text } ) : null,
			el( 'div', { 'class': 'mrp-outcome__block' }, [
				el( 'h3', { 'class': 'mrp-section-title', text: 'Immediate effect' } ),
				chips( outcome.visible_deltas, 'Nothing moved that the country can measure yet.' )
			] ),
			news ? el( 'div', { 'class': 'mrp-outcome__block' }, [
				el( 'h3', { 'class': 'mrp-section-title', text: 'The morning papers' } ),
				news
			] ) : null,
			el( 'p', { 'class': 'mrp-outcome__note', text: PENDING_NOTE } ),
			opts.footer ? el( 'div', { 'class': 'mrp-outcome__foot' }, opts.footer ) : null
		] );
	}

	views.outcome = {
		render: render,
		chip: chip,
		chips: chips,
		headlines: headlines,
		PENDING_NOTE: PENDING_NOTE
	};
}( window ) );
