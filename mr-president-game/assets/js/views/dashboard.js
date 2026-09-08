/**
 * MRP.views.dashboard — the command centre.
 *
 * Owns the shell (top bar, three-column grid, bottom navigation) and the right-hand tab
 * rail. The centre column is delegated: the Situation Room renders the map plus the event
 * card and outcome, every other bottom-nav view renders a panel from `MRP.views.panels`.
 *
 * Also exports the shared presentation primitives the panels reuse: `tile()`, `meta()`,
 * `viewFrame()` and the indicator metadata table.
 */
( function ( window ) {
	'use strict';

	var MRP = window.MRP = window.MRP || {};
	var views = MRP.views = MRP.views || {};

	/**
	 * Indicator metadata: label, unit, formatting and the range used for meters/tone.
	 * `better` is 'high' when a larger number is good for the country, 'low' when it is not.
	 */
	var INDICATORS = {
		approval: { label: 'Approval', unit: '%', format: 'pct0', min: 0, max: 100, better: 'high', group: 'core' },
		congress_support: { label: 'Congress', unit: '%', format: 'pct0', min: 0, max: 100, better: 'high', group: 'core' },
		domestic_stability: { label: 'Stability', unit: '%', format: 'pct0', min: 0, max: 100, better: 'high', group: 'core' },
		crisis_level: { label: 'Crisis level', unit: '%', format: 'pct0', min: 0, max: 100, better: 'low', group: 'core' },
		gdp_growth: { label: 'GDP growth', unit: '%', format: 'num1', min: -10, max: 10, better: 'high', group: 'economy' },
		inflation: { label: 'Inflation', unit: '%', format: 'num1', min: -2, max: 20, better: 'low', group: 'economy' },
		unemployment: { label: 'Unemployment', unit: '%', format: 'num1', min: 2, max: 25, better: 'low', group: 'economy' },
		deficit: { label: 'Deficit', unit: '$bn/yr', format: 'money', min: -500, max: 2000, better: 'low', group: 'economy' },
		national_debt: { label: 'National debt', unit: '$bn', format: 'money', min: 0, max: 20000, better: 'low', group: 'economy' },
		global_influence: { label: 'Global influence', unit: '%', format: 'pct0', min: 0, max: 100, better: 'high', group: 'world' },
		allied_confidence: { label: 'Allied confidence', unit: '%', format: 'pct0', min: 0, max: 100, better: 'high', group: 'world' }
	};

	var RAIL_KEYS = [ 'approval', 'congress_support', 'domestic_stability', 'crisis_level', 'global_influence', 'allied_confidence' ];

	/** Bottom navigation. `short` is used on narrow screens where the long label will not fit. */
	var NAV = [
		{ id: 'situation', label: 'Situation Room', short: 'Situation', glyph: '◉' },
		{ id: 'economy', label: 'Economy', short: 'Economy', glyph: '▤' },
		{ id: 'congress', label: 'Congress', short: 'Congress', glyph: '⌂' },
		{ id: 'diplomacy', label: 'Diplomacy', short: 'Diplomacy', glyph: '◈' },
		{ id: 'security', label: 'Security', short: 'Security', glyph: '⌖' },
		{ id: 'history', label: 'History', short: 'History', glyph: '☰' }
	];

	var TABS = [
		{ id: 'brief', label: 'Brief' },
		{ id: 'cabinet', label: 'Cabinet' },
		{ id: 'intel', label: 'Intel' },
		{ id: 'congress', label: 'Congress' }
	];

	var TONE_GOOD = 0.6;
	var TONE_ALERT = 0.35;

	/* --- Shared primitives ------------------------------------------------ */

	function formatIndicator( value, meta ) {
		var ui = MRP.ui;

		if ( 'number' !== typeof value ) {
			return '—';
		}
		if ( 'money' === meta.format ) {
			return ui.fmt.num( value, 0 );
		}
		if ( 'num1' === meta.format ) {
			return ui.fmt.num( value, 1 );
		}

		return ui.fmt.num( value, 0 );
	}

	/**
	 * Normalise a value into 0..1 across its declared range.
	 *
	 * @param {number} value Raw value.
	 * @param {Object} meta  Indicator metadata.
	 * @return {number} Fraction between 0 and 1.
	 */
	function fraction( value, meta ) {
		var span = meta.max - meta.min;

		if ( 'number' !== typeof value || 0 >= span ) {
			return 0;
		}

		return Math.max( 0, Math.min( 1, ( value - meta.min ) / span ) );
	}

	/**
	 * Tone modifier ('good' / 'warn' / 'alert' / '') for an indicator value.
	 *
	 * @param {number} value Raw value.
	 * @param {Object} meta  Indicator metadata.
	 * @return {string} Modifier suffix.
	 */
	function tone( value, meta ) {
		var score = fraction( value, meta );

		if ( 'low' === meta.better ) {
			score = 1 - score;
		}
		if ( score >= TONE_GOOD ) {
			return 'good';
		}
		if ( score < TONE_ALERT ) {
			return 'alert';
		}

		return 'warn';
	}

	/**
	 * Render an indicator tile.
	 *
	 * @param {string} key      Indicator key.
	 * @param {number} value    Value.
	 * @param {Object} [extra]  `{delta, foot}`.
	 * @return {?Element} Tile node, or null for unknown keys.
	 */
	function tile( key, value, extra ) {
		var ui = MRP.ui;
		var el = ui.el;
		var meta = INDICATORS[ key ];
		var opts = extra || {};
		var modifier;

		if ( ! meta ) {
			return null;
		}
		modifier = tone( value, meta );

		return el( 'div', { 'class': 'mrp-tile mrp-tile--' + modifier, dataset: { indicator: key } }, [
			el( 'span', { 'class': 'mrp-tile__label', text: meta.label } ),
			el( 'div', { 'class': 'mrp-tile__row' }, [
				el( 'span', { 'class': 'mrp-tile__value', text: formatIndicator( value, meta ) } ),
				el( 'span', { 'class': 'mrp-tile__unit', text: meta.unit } )
			] ),
			el( 'div', { 'class': 'mrp-tile__meter' }, [
				el( 'span', { style: { width: ( fraction( value, meta ) * 100 ).toFixed( 1 ) + '%' } } )
			] ),
			opts.foot ? el( 'div', { 'class': 'mrp-tile__foot', text: opts.foot } ) : null
		] );
	}

	/**
	 * Render a labelled key/value pair list.
	 *
	 * @param {Array} pairs `[{label, value}]`.
	 * @return {Element} Definition list.
	 */
	function meta( pairs ) {
		var ui = MRP.ui;
		var el = ui.el;
		var nodes = [];
		var i;

		for ( i = 0; i < pairs.length; i++ ) {
			nodes.push( el( 'dt', { text: pairs[ i ].label } ) );
			nodes.push( el( 'dd', { text: pairs[ i ].value } ) );
		}

		return el( 'dl', { 'class': 'mrp-kv' }, nodes );
	}

	/**
	 * Standard frame for a bottom-nav panel view.
	 *
	 * @param {Object} head     `{title, kicker, aside}`.
	 * @param {*}      children Body content.
	 * @return {Element} Frame node.
	 */
	function viewFrame( head, children ) {
		var ui = MRP.ui;
		var el = ui.el;

		return el( 'div', { 'class': 'mrp-view mrp-animate-in' }, [
			el( 'header', { 'class': 'mrp-view__head' }, [
				el( 'div', {}, [
					head.kicker ? el( 'span', { 'class': 'mrp-kicker mrp-kicker--gold', text: head.kicker } ) : null,
					el( 'h2', { 'class': 'mrp-view__title', text: head.title } )
				] ),
				head.aside ? el( 'div', { 'class': 'mrp-eyebrow', text: head.aside } ) : null
			] ),
			el( 'div', { 'class': 'mrp-view__body mrp-scroll' }, children )
		] );
	}

	views.dashboard = {
		INDICATORS: INDICATORS,
		NAV: NAV,
		TABS: TABS,
		tile: tile,
		meta: meta,
		tone: tone,
		fraction: fraction,
		viewFrame: viewFrame,
		assetUrl: assetUrl,
		render: render,
		RAIL_KEYS: RAIL_KEYS
	};

	/* --- Top bar ---------------------------------------------------------- */

	function topBar( state ) {
		var ui = MRP.ui;
		var el = ui.el;
		var game = state.game || {};
		var config = window.MRP_CONFIG || {};

		return el( 'header', { 'class': 'mrp-topbar' }, [
			el( 'div', { 'class': 'mrp-topbar__brand' }, [
				el( 'span', { 'class': 'mrp-topbar__seal', text: 'MP', 'aria-hidden': 'true' } ),
				el( 'span', { 'class': 'mrp-topbar__title', text: 'Mr. President' } ),
				el( 'span', {
					'class': 'mrp-topbar__president',
					text: game.president_name ? '· President ' + game.president_name : ''
				} )
			] ),
			el( 'div', { 'class': 'mrp-topbar__clock' }, [
				el( 'span', { 'class': 'mrp-topbar__month', text: game.month_label || ui.fmt.date( game.date ) } ),
				el( 'span', {
					'class': 'mrp-topbar__turn',
					text: 'Month ' + ui.fmt.num( game.turn ) + ' · Term ' + ui.fmt.num( game.term )
				} )
			] ),
			el( 'div', { 'class': 'mrp-topbar__spacer' } ),
			el( 'div', { 'class': 'mrp-topbar__actions' }, [
				el( 'span', {
					'class': 'mrp-status-dot' + ( state.busy ? ' mrp-status-dot--busy' : '' ),
					role: 'status',
					'aria-label': state.busy ? 'Working' : 'Ready'
				} ),
				el( 'button', {
					type: 'button',
					'class': 'mrp-btn mrp-btn--ghost',
					onClick: function () {
						MRP.actions.saveGame();
					},
					disabled: state.busy ? 'disabled' : null
				}, 'Save' ),
				config.devMode ? el( 'button', {
					type: 'button',
					'class': 'mrp-btn mrp-btn--ghost',
					'aria-expanded': state.devOpen ? 'true' : 'false',
					onClick: function () {
						MRP.actions.toggleDev();
					}
				}, 'Dev' ) : null,
				el( 'button', {
					type: 'button',
					'class': 'mrp-btn mrp-btn--ghost',
					onClick: function () {
						MRP.actions.showTitle();
					}
				}, 'Menu' )
			] )
		] );
	}

	/* --- Left rail -------------------------------------------------------- */

	function deltaFor( report, path ) {
		var list = ( report && report.indicator_deltas ) || [];
		var i;

		for ( i = 0; i < list.length; i++ ) {
			if ( list[ i ] && list[ i ].path === path ) {
				return list[ i ];
			}
		}

		return null;
	}

	function rail( state ) {
		var ui = MRP.ui;
		var el = ui.el;
		var game = state.game || {};
		var indicators = game.indicators || {};
		var report = game.turn_report;
		var tiles = [];
		var i;
		var key;
		var delta;

		for ( i = 0; i < RAIL_KEYS.length; i++ ) {
			key = RAIL_KEYS[ i ];
			delta = deltaFor( report, 'public.' + key );
			tiles.push( tile( key, indicators[ key ], {
				foot: delta ? 'Last month ' + ui.fmt.delta( delta ) : null
			} ) );
		}

		return el( 'aside', { 'class': 'mrp-rail mrp-scroll', 'aria-label': 'National indicators' }, [
			el( 'div', { 'class': 'mrp-tiles' }, tiles ),
			el( 'div', { 'class': 'mrp-rail__aside' }, [
				el( 'h3', { 'class': 'mrp-section-title', text: 'Standing read' } ),
				meta( [
					{ label: 'Economy', value: String( indicators.economy_status || '—' ) },
					{ label: 'Security', value: String( indicators.security_status || '—' ) },
					{ label: 'Alliances', value: String( indicators.allied_confidence_label || '—' ) }
				] )
			] )
		] );
	}

	/* --- Centre ----------------------------------------------------------- */

	/**
	 * Absolute URL for a file under assets/, tolerating a config value with or without a
	 * trailing slash.
	 *
	 * @param {string} relative Path relative to the assets directory.
	 * @return {string} URL.
	 */
	function assetUrl( relative ) {
		var base = String( ( window.MRP_CONFIG || {} ).assetsUrl || '' );

		if ( base && '/' !== base.charAt( base.length - 1 ) ) {
			base += '/';
		}

		return base + relative;
	}

	function mapPanel( state ) {
		var ui = MRP.ui;
		var el = ui.el;
		var game = state.game || {};
		var event = game.active_event;
		var caption = event && event.title ? event.title : 'No active flash traffic';

		return el( 'div', { 'class': 'mrp-map' }, [
			el( 'img', {
				'class': 'mrp-map__img',
				src: assetUrl( 'images/world-map.svg' ),
				alt: 'Stylised world map of the current situation',
				draggable: 'false'
			} ),
			el( 'div', { 'class': 'mrp-map__caption' }, [
				event ? el( 'span', { 'class': 'mrp-map__pin', text: '◆', 'aria-hidden': 'true' } ) : null,
				el( 'span', { text: caption } )
			] )
		] );
	}

	function advanceButton( state ) {
		var ui = MRP.ui;
		var game = state.game || {};
		var event = game.active_event;
		var blocked = !! ( event && ! event.resolved_choice_id );
		var label = 'Advance to ' + ( ui.fmt.nextMonthLabel( game.date ) || 'next month' );

		return ui.el( 'button', {
			type: 'button',
			'class': 'mrp-btn mrp-btn--gold mrp-btn--lg',
			id: 'mrp-advance',
			disabled: ( blocked || state.busy ) ? 'disabled' : null,
			title: blocked ? 'Resolve the briefing on your desk first.' : label,
			onClick: function () {
				MRP.actions.advance();
			}
		}, label );
	}

	function situation( state ) {
		var ui = MRP.ui;
		var el = ui.el;
		var game = state.game || {};
		var card = views.eventCard.render( game, { busy: state.busy } );
		var outcomeNode = game.last_outcome
			? views.outcome.render( game.last_outcome, { footer: advanceButton( state ) } )
			: null;
		var quiet = ! card ? el( 'div', { 'class': 'mrp-block' }, [
			el( 'span', { 'class': 'mrp-kicker mrp-kicker--gold', text: 'Quiet month' } ),
			el( 'p', {
				'class': 'mrp-hero__tagline',
				text: 'No briefing demands a decision this month. The staff recommends using the time.'
			} ),
			el( 'div', { style: { 'margin-top': '16px' } }, [ advanceButton( state ) ] )
		] ) : null;

		return el( 'div', { 'class': 'mrp-feed mrp-scroll' }, [
			card,
			outcomeNode,
			quiet,
			( card && ! outcomeNode ) ? el( 'div', { 'class': 'mrp-outcome__foot' }, [ advanceButton( state ) ] ) : null
		] );
	}

	function centre( state ) {
		var ui = MRP.ui;
		var el = ui.el;
		var panels = views.panels || {};
		var panel = panels[ state.view ];

		return el( 'section', {
			'class': 'mrp-center',
			id: 'mrp-center',
			'aria-label': 'Main view',
			tabindex: '-1'
		}, [
			'situation' === state.view ? mapPanel( state ) : null,
			( 'situation' === state.view || ! panel ) ? situation( state ) : panel.render( state )
		] );
	}

	/* --- Right rail ------------------------------------------------------- */

	function navBar( state ) {
		var ui = MRP.ui;
		var el = ui.el;
		var game = state.game || {};
		var pending = !! ( game.active_event && ! game.active_event.resolved_choice_id );
		var buttons = [];
		var i;
		var item;

		for ( i = 0; i < NAV.length; i++ ) {
			item = NAV[ i ];
			buttons.push( el( 'button', {
				type: 'button',
				'class': 'mrp-nav__btn',
				'aria-label': item.label,
				'aria-current': state.view === item.id ? 'page' : null,
				dataset: { view: item.id },
				onClick: ( function ( id ) {
					return function () {
						MRP.actions.setView( id );
					};
				}( item.id ) )
			}, [
				el( 'span', { 'class': 'mrp-nav__glyph', text: item.glyph, 'aria-hidden': 'true' } ),
				el( 'span', { 'class': 'mrp-nav__label mrp-nav__label--long', text: item.label } ),
				el( 'span', { 'class': 'mrp-nav__label mrp-nav__label--short', text: item.short, 'aria-hidden': 'true' } ),
				( 'situation' === item.id && pending )
					? el( 'span', { 'class': 'mrp-nav__flag', 'aria-label': 'Decision required' } )
					: null
			] ) );
		}

		return el( 'nav', { 'class': 'mrp-nav', 'aria-label': 'Government views' }, buttons );
	}

	/**
	 * Render the whole game screen.
	 *
	 * @param {Object} state Store state.
	 * @return {DocumentFragment} Top bar, grid and bottom navigation.
	 */
	function render( state ) {
		var fragment = window.document.createDocumentFragment();

		fragment.appendChild( topBar( state ) );
		fragment.appendChild( MRP.ui.el( 'div', { 'class': 'mrp-main' }, [
			rail( state ),
			centre( state ),
			views.sidePanel.render( state )
		] ) );
		fragment.appendChild( navBar( state ) );

		return fragment;
	}
}( window ) );
