/**
 * MRP.views.eventCard — the active event rendered as a classified briefing document.
 *
 * Consumes `game.active_event` from the view model (spec section 8.4): flash label,
 * severity, briefing text, the cabinet assessment for this event, and the choice list.
 * Choice effects are never present client-side, so nothing here can leak them.
 */
( function ( window ) {
	'use strict';

	var MRP = window.MRP = window.MRP || {};
	var views = MRP.views = MRP.views || {};

	var SEVERITY_WORDS = {
		1: 'Routine',
		2: 'Notable',
		3: 'Serious',
		4: 'Severe',
		5: 'Critical'
	};

	function severityOf( event ) {
		var value = parseInt( event && event.severity, 10 );

		return ( value >= 1 && value <= 5 ) ? value : 3;
	}

	/**
	 * Human location line for the card metadata.
	 *
	 * @param {Object} event Active event.
	 * @param {Object} game  Game view model (for country names).
	 * @return {?string} Location label or null.
	 */
	function locationLabel( event, game ) {
		var location = event && event.location;
		var countries = ( game && game.countries ) || {};
		var country;

		if ( ! location ) {
			return null;
		}
		if ( location.country ) {
			country = countries[ location.country ];

			return ( country && country.name ) ? country.name : MRP.ui.fmt.humanize( location.country );
		}
		if ( location.region ) {
			return MRP.ui.fmt.humanize( location.region );
		}

		return null;
	}

	function paragraphs( bodyText ) {
		var ui = MRP.ui;
		var chunks = String( bodyText || '' ).split( /\n{2,}/ );
		var nodes = [];
		var i;
		var trimmed;

		for ( i = 0; i < chunks.length; i++ ) {
			trimmed = chunks[ i ].trim();
			if ( trimmed ) {
				nodes.push( ui.el( 'p', { text: trimmed } ) );
			}
		}

		return nodes;
	}

	function cabinetList( cabinet ) {
		var ui = MRP.ui;
		var el = ui.el;
		var nodes = [];
		var i;
		var member;

		for ( i = 0; i < cabinet.length; i++ ) {
			member = cabinet[ i ];
			if ( ! member || ! member.position ) {
				continue;
			}
			nodes.push( el( 'div', { 'class': 'mrp-assessment__item' }, [
				el( 'div', { 'class': 'mrp-assessment__who', text: member.name || ui.fmt.humanize( member.advisor_id ) } ),
				el( 'div', { 'class': 'mrp-assessment__office', text: member.office || '' } ),
				el( 'p', { 'class': 'mrp-assessment__text', text: member.position } )
			] ) );
		}

		return nodes;
	}

	function advisorName( cabinet, advisorId ) {
		var i;

		for ( i = 0; i < cabinet.length; i++ ) {
			if ( cabinet[ i ] && cabinet[ i ].advisor_id === advisorId ) {
				return cabinet[ i ].name || MRP.ui.fmt.humanize( advisorId );
			}
		}

		return MRP.ui.fmt.humanize( advisorId );
	}

	function choicePositions( choice, cabinet ) {
		var ui = MRP.ui;
		var el = ui.el;
		var positions = choice.advisor_positions || {};
		var nodes = [];
		var key;

		for ( key in positions ) {
			if ( ! Object.prototype.hasOwnProperty.call( positions, key ) || ! positions[ key ] ) {
				continue;
			}
			nodes.push( el( 'p', { 'class': 'mrp-choice__advisor' }, [
				el( 'b', { text: advisorName( cabinet, key ) + ': ' } ),
				ui.text( positions[ key ] )
			] ) );
		}

		if ( ! nodes.length ) {
			return null;
		}

		return el( 'div', { 'class': 'mrp-choice__advisors' }, nodes );
	}

	function choiceButton( choice, index, cabinet, options ) {
		var ui = MRP.ui;
		var el = ui.el;
		var taken = options.resolvedChoiceId === choice.id;

		return el( 'button', {
			type: 'button',
			'class': 'mrp-choice' + ( taken ? ' mrp-choice--taken' : '' ),
			disabled: options.disabled ? 'disabled' : null,
			'aria-pressed': options.resolvedChoiceId ? ( taken ? 'true' : 'false' ) : null,
			dataset: { 'choice-id': choice.id },
			onClick: function () {
				if ( ! options.disabled && options.onChoose ) {
					options.onChoose( choice.id );
				}
			}
		}, [
			el( 'span', { 'class': 'mrp-choice__label' }, [
				el( 'span', { 'class': 'mrp-choice__key', text: 'Opt ' + ( index + 1 ) } ),
				ui.text( choice.label || ui.fmt.humanize( choice.id ) )
			] ),
			choice.description ? el( 'p', { 'class': 'mrp-choice__desc', text: choice.description } ) : null,
			choicePositions( choice, cabinet )
		] );
	}

	/**
	 * Render the event card.
	 *
	 * @param {Object} game      Game view model.
	 * @param {Object} [options] `{onChoose, busy}`.
	 * @return {?Element} Card node, or null when there is no active event.
	 */
	function render( game, options ) {
		var ui = MRP.ui;
		var el = ui.el;
		var event = game && game.active_event;
		var opts = options || {};
		var severity;
		var cabinet;
		var choices;
		var resolvedId;
		var location;
		var meta = [];
		var choiceNodes = [];
		var i;

		if ( ! event ) {
			return null;
		}

		severity = severityOf( event );
		cabinet = Array.isArray( event.cabinet ) ? event.cabinet : [];
		choices = Array.isArray( event.choices ) ? event.choices : [];
		resolvedId = event.resolved_choice_id || null;
		location = locationLabel( event, game );

		meta.push( el( 'span', { text: 'Category · ' + ui.fmt.humanize( event.category || 'briefing' ) } ) );
		meta.push( el( 'span', { text: 'Severity ' + severity + ' · ' + ( SEVERITY_WORDS[ severity ] || '' ) } ) );
		if ( location ) {
			meta.push( el( 'span', { text: 'Origin · ' + location } ) );
		}
		meta.push( el( 'span', { text: game.month_label || ui.fmt.date( game.date ) } ) );

		for ( i = 0; i < choices.length; i++ ) {
			choiceNodes.push( choiceButton( choices[ i ], i, cabinet, {
				disabled: !! ( resolvedId || opts.busy ),
				resolvedChoiceId: resolvedId,
				onChoose: opts.onChoose || function ( id ) {
					MRP.actions.decide( id );
				}
			} ) );
		}

		return el( 'article', {
			'class': 'mrp-doc mrp-doc--sev-' + severity + ' mrp-animate-in',
			'aria-labelledby': 'mrp-doc-title',
			dataset: { 'event-id': event.id || '' }
		}, [
			el( 'header', { 'class': 'mrp-doc__head' }, [
				el( 'div', {}, [
					el( 'span', { 'class': 'mrp-doc__flash', text: event.flash_label || 'Presidential briefing' } ),
					el( 'h2', { 'class': 'mrp-doc__title', id: 'mrp-doc-title', text: event.title || 'Untitled briefing' } )
				] ),
				el( 'div', { 'class': 'mrp-doc__stamp', text: resolvedId ? 'Decided' : 'Action\nrequired' } )
			] ),
			el( 'div', { 'class': 'mrp-doc__meta' }, meta ),
			el( 'div', { 'class': 'mrp-doc__body' }, [
				event.summary ? el( 'p', { 'class': 'mrp-doc__summary', text: event.summary } ) : null,
				paragraphs( event.briefing_text )
			] ),
			cabinet.length ? el( 'section', { 'class': 'mrp-doc__section' }, [
				el( 'h3', { 'class': 'mrp-doc__section-title', text: 'Cabinet assessment' } ),
				el( 'div', { 'class': 'mrp-assessment' }, cabinetList( cabinet ) )
			] ) : null,
			choiceNodes.length ? el( 'section', {
				'class': 'mrp-choices',
				role: 'group',
				'aria-label': resolvedId ? 'Decision taken' : 'Available courses of action'
			}, choiceNodes ) : null
		] );
	}

	views.eventCard = { render: render, SEVERITY_WORDS: SEVERITY_WORDS };
}( window ) );
