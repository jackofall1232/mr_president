/**
 * MRP.views.sidePanel — the right-hand tab rail of the command centre.
 *
 * Four tabs over the same game/briefing payload: Brief (daily summary, indicator deltas,
 * turn-report headlines), Cabinet (the six advisors and their standing positions), Intel
 * (crisis posture plus the media log) and Congress (support and political notes).
 *
 * Split out of dashboard.js to keep both files readable; `Assets` enqueues `views/*.js`.
 */
( function ( window ) {
	'use strict';

	var MRP = window.MRP = window.MRP || {};
	var views = MRP.views = MRP.views || {};

	var CONGRESS_READS = [
		{ min: 66, badge: 'good', text: 'The leadership can move legislation without buying every vote.' },
		{ min: 50, badge: 'warn', text: 'A workable majority, but each bill costs political capital.' },
		{ min: 34, badge: 'warn', text: 'The floor is contested. Expect amendments you did not ask for.' },
		{ min: 0, badge: 'alert', text: 'The chamber is hostile. Nothing large passes without a deal first.' }
	];

	function congressRead( support ) {
		var i;

		for ( i = 0; i < CONGRESS_READS.length; i++ ) {
			if ( support >= CONGRESS_READS[ i ].min ) {
				return CONGRESS_READS[ i ];
			}
		}

		return CONGRESS_READS[ CONGRESS_READS.length - 1 ];
	}

	function notesList( notes ) {
		var ui = MRP.ui;
		var el = ui.el;
		var items = Array.isArray( notes ) ? notes : [];
		var nodes = [];
		var i;

		for ( i = 0; i < items.length; i++ ) {
			if ( items[ i ] ) {
				nodes.push( el( 'li', { text: String( items[ i ] ) } ) );
			}
		}
		if ( ! nodes.length ) {
			return null;
		}

		return el( 'ul', { 'class': 'mrp-notes' }, nodes );
	}

	/* --- Tabs ------------------------------------------------------------- */

	function briefTab( state ) {
		var ui = MRP.ui;
		var el = ui.el;
		var game = state.game || {};
		var briefing = state.briefing || {};
		var report = briefing.turn_report || game.turn_report || null;
		var summary = briefing.summary || '';
		var news = views.outcome.headlines( ( report && report.headlines ) || game.media );

		return el( 'div', { 'class': 'mrp-stack' }, [
			el( 'div', {}, [
				el( 'h3', { 'class': 'mrp-section-title', text: 'Presidential daily brief' } ),
				summary
					? el( 'p', { 'class': 'mrp-brief__summary', text: summary } )
					: el( 'p', { 'class': 'mrp-empty', text: 'The morning brief is still being typed.' } )
			] ),
			el( 'div', {}, [
				el( 'h3', { 'class': 'mrp-section-title', text: 'Movement since last month' } ),
				views.outcome.chips( report && report.indicator_deltas, 'A steady month by the numbers.' )
			] ),
			( report && report.system_notes && report.system_notes.length ) ? el( 'div', {}, [
				el( 'h3', { 'class': 'mrp-section-title', text: 'Staff notes' } ),
				notesList( report.system_notes )
			] ) : null,
			news ? el( 'div', {}, [
				el( 'h3', { 'class': 'mrp-section-title', text: 'Headlines' } ),
				news
			] ) : null
		] );
	}

	function cabinetTab( state ) {
		var ui = MRP.ui;
		var el = ui.el;
		var game = state.game || {};
		var briefing = state.briefing || {};
		var roster = ( briefing.cabinet && briefing.cabinet.length )
			? briefing.cabinet
			: ( ( game.active_event && game.active_event.cabinet ) || [] );
		var nodes = [];
		var i;
		var member;

		for ( i = 0; i < roster.length; i++ ) {
			member = roster[ i ];
			if ( ! member ) {
				continue;
			}
			nodes.push( el( 'div', { 'class': 'mrp-advisor' }, [
				el( 'div', {
					'class': 'mrp-advisor__avatar',
					'aria-hidden': 'true',
					text: ui.fmt.initials( member.name || member.advisor_id )
				} ),
				el( 'div', { style: { 'min-width': '0' } }, [
					el( 'div', { 'class': 'mrp-advisor__name', text: member.name || ui.fmt.humanize( member.advisor_id ) } ),
					el( 'div', { 'class': 'mrp-advisor__office', text: member.office || '' } ),
					member.position ? el( 'p', { 'class': 'mrp-advisor__position', text: member.position } ) : null
				] )
			] ) );
		}

		if ( ! nodes.length ) {
			return el( 'p', { 'class': 'mrp-empty', text: 'The cabinet has no standing position this month.' } );
		}

		return el( 'div', { 'class': 'mrp-cabinet' }, nodes );
	}

	function intelTab( state ) {
		var ui = MRP.ui;
		var el = ui.el;
		var dash = views.dashboard;
		var game = state.game || {};
		var indicators = game.indicators || {};
		var media = Array.isArray( game.media ) ? game.media.slice().reverse() : [];
		var news = views.outcome.headlines( media );

		return el( 'div', { 'class': 'mrp-stack' }, [
			el( 'div', {}, [
				el( 'h3', { 'class': 'mrp-section-title', text: 'Threat picture' } ),
				el( 'div', { 'class': 'mrp-tiles' }, [
					dash.tile( 'crisis_level', indicators.crisis_level, {
						foot: 'Posture · ' + ( indicators.security_status || 'unknown' )
					} ),
					dash.tile( 'allied_confidence', indicators.allied_confidence, {
						foot: 'Alliances · ' + ( indicators.allied_confidence_label || 'unknown' )
					} ),
					dash.tile( 'global_influence', indicators.global_influence )
				] )
			] ),
			el( 'div', {}, [
				el( 'h3', { 'class': 'mrp-section-title', text: 'Media log' } ),
				news || el( 'p', { 'class': 'mrp-empty', text: 'Nothing has reached print yet.' } )
			] )
		] );
	}

	function congressTab( state ) {
		var ui = MRP.ui;
		var el = ui.el;
		var dash = views.dashboard;
		var game = state.game || {};
		var indicators = game.indicators || {};
		var support = 'number' === typeof indicators.congress_support ? indicators.congress_support : 0;
		var read = congressRead( support );
		var notes = [];

		notes.push( read.text );
		if ( 'number' === typeof indicators.approval && indicators.approval < support ) {
			notes.push( 'Members are currently running ahead of your approval; they will not stay there for free.' );
		} else if ( 'number' === typeof indicators.approval ) {
			notes.push( 'Your approval is the strongest argument you have on the Hill this month.' );
		}
		if ( 'number' === typeof indicators.deficit && indicators.deficit > 200 ) {
			notes.push( 'The deficit number is now large enough to be its own argument in committee.' );
		}

		return el( 'div', { 'class': 'mrp-stack' }, [
			el( 'div', {}, [
				el( 'h3', { 'class': 'mrp-section-title', text: 'Legislative standing' } ),
				el( 'div', { 'class': 'mrp-tiles' }, [
					dash.tile( 'congress_support', support ),
					dash.tile( 'approval', indicators.approval )
				] )
			] ),
			el( 'div', {}, [
				el( 'h3', { 'class': 'mrp-section-title', text: 'Political notes' } ),
				el( 'p', { style: { 'margin-bottom': '10px' } }, [
					el( 'span', { 'class': 'mrp-badge mrp-badge--' + read.badge, text: ui.fmt.pct( support ) + ' support' } )
				] ),
				notesList( notes )
			] )
		] );
	}

	var RENDERERS = {
		brief: briefTab,
		cabinet: cabinetTab,
		intel: intelTab,
		congress: congressTab
	};

	/**
	 * Render the right-hand rail.
	 *
	 * @param {Object} state Store state.
	 * @return {Element} Panel node.
	 */
	function render( state ) {
		var ui = MRP.ui;
		var el = ui.el;
		var tabs = views.dashboard.TABS;
		var active = RENDERERS[ state.panel ] ? state.panel : 'brief';
		var buttons = [];
		var i;
		var tab;

		for ( i = 0; i < tabs.length; i++ ) {
			tab = tabs[ i ];
			buttons.push( el( 'button', {
				type: 'button',
				role: 'tab',
				'class': 'mrp-tab',
				id: 'mrp-tab-' + tab.id,
				'aria-selected': active === tab.id ? 'true' : 'false',
				'aria-controls': 'mrp-tabpanel',
				tabindex: active === tab.id ? '0' : '-1',
				onClick: ( function ( id ) {
					return function () {
						MRP.actions.setPanel( id );
					};
				}( tab.id ) ),
				onKeydown: ( function ( index ) {
					return function ( event ) {
						var next = null;

						if ( 'ArrowRight' === event.key ) {
							next = tabs[ ( index + 1 ) % tabs.length ];
						} else if ( 'ArrowLeft' === event.key ) {
							next = tabs[ ( index - 1 + tabs.length ) % tabs.length ];
						}
						if ( next ) {
							event.preventDefault();
							MRP.actions.setPanel( next.id );
						}
					};
				}( i ) )
			}, tab.label ) );
		}

		return el( 'aside', { 'class': 'mrp-panel' }, [
			el( 'div', { 'class': 'mrp-tabs', role: 'tablist', 'aria-label': 'Briefing panels' }, buttons ),
			el( 'div', {
				'class': 'mrp-tabpanel mrp-scroll',
				id: 'mrp-tabpanel',
				role: 'tabpanel',
				tabindex: '0',
				'aria-labelledby': 'mrp-tab-' + active
			}, RENDERERS[ active ]( state ) )
		] );
	}

	views.sidePanel = { render: render, congressRead: congressRead };
}( window ) );
