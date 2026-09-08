/**
 * MRP app bootstrap — wiring, actions and the render loop.
 *
 * Loaded last (spec section 8.5). Reads `MRP_CONFIG`, resumes the remembered game,
 * subscribes to the store and re-renders the whole screen on every state change; views
 * build their own DOM through `MRP.ui.el`, so a full re-render is cheap and predictable.
 *
 * `MRP.actions` is the single place a view calls to change anything.
 */
( function ( window, document ) {
	'use strict';

	var MRP = window.MRP = window.MRP || {};

	var ROOT_ID = 'mrp-app';

	/** Machine error codes mapped to the message the player should read. */
	var ERROR_MESSAGES = {
		decision_required: 'The briefing on your desk needs a decision before the month can turn.',
		event_already_resolved: 'That briefing has already been decided.',
		unknown_choice: 'That course of action is not on the table.',
		no_active_event: 'There is no briefing awaiting a decision.',
		unknown_scenario: 'That scenario is not installed.',
		rest_cookie_invalid_nonce: 'Your session expired. Reload the page to continue.',
		network_error: 'The situation room could not be reached. Check your connection.'
	};

	var root = null;
	var config = {};
	var lastOutcomeKey = null;
	var pendingOutcomeFocus = false;

	function store() {
		return MRP.store;
	}

	function state() {
		return MRP.store.get();
	}

	/**
	 * Show an error to the player and, for a missing or foreign game, fall back to the
	 * title screen so they are never stranded on a dead board.
	 *
	 * @param {Error} error Error thrown by MRP.api.
	 */
	function handleError( error ) {
		var code = ( error && error.code ) || 'request_failed';
		var message = ERROR_MESSAGES[ code ] || ( error && error.message ) || 'Something went wrong.';
		var kind = 404 === ( error && error.status ) ? 'error' : 'warn';

		MRP.ui.toast( message, kind );
		store().set( { toast: { message: message, kind: kind }, busy: false } );

		if ( 404 === ( error && error.status ) ) {
			store().set( { screen: 'title', game: null, briefing: null, currentGameId: null } );
			MRP.actions.refreshGames();
		}
	}

	/**
	 * Fetch the briefing for a game. Failure is non-fatal: the dashboard still renders
	 * from the game object alone.
	 *
	 * @param {string} id Game uuid.
	 * @return {Promise} Resolves when the briefing has been stored (or skipped).
	 */
	function loadBriefing( id ) {
		if ( ! id ) {
			return window.Promise.resolve( null );
		}

		return MRP.api.getBriefing( id ).then( function ( briefing ) {
			if ( state().currentGameId === id ) {
				store().set( { briefing: briefing || null } );
			}

			return briefing;
		}, function () {
			store().set( { briefing: null } );

			return null;
		} );
	}

	function adoptGame( game ) {
		if ( ! game ) {
			return;
		}
		store().set( {
			game: game,
			currentGameId: game.id || state().currentGameId,
			screen: 'game',
			busy: false,
			error: null
		} );
		loadBriefing( game.id );
	}

	MRP.actions = {
		/** Return to the title screen and refresh the save list. */
		showTitle: function () {
			store().set( { screen: 'title', devOpen: false } );
			MRP.actions.refreshGames();
		},

		/** Open the new-administration form. */
		startNew: function () {
			store().set( { screen: 'new', error: null } );
		},

		/** Reload the saved-game list used by the title screen. */
		refreshGames: function () {
			return MRP.api.listGames().then( function ( games ) {
				store().set( { games: games } );

				return games;
			}, function () {
				store().set( { games: [] } );

				return [];
			} );
		},

		/**
		 * Create a new game.
		 *
		 * @param {string} name President name, already validated client-side.
		 */
		createGame: function ( name ) {
			if ( state().busy ) {
				return;
			}
			store().set( { busy: true } );
			MRP.api.newGame( name ).then( function ( game ) {
				adoptGame( game );
				store().set( { view: 'situation', panel: 'brief' } );
				MRP.ui.toast( 'You have taken the oath. The first briefing is on your desk.', 'success' );
			}, handleError );
		},

		/**
		 * Load an existing game.
		 *
		 * @param {string} id Game uuid.
		 */
		continueGame: function ( id ) {
			if ( ! id || state().busy ) {
				return;
			}
			store().set( { busy: true, currentGameId: id } );
			MRP.api.getGame( id ).then( adoptGame, handleError );
		},

		/**
		 * Send a decision. The browser sends the choice id and nothing else.
		 *
		 * @param {string} choiceId Chosen option.
		 */
		decide: function ( choiceId ) {
			var id = state().currentGameId;

			if ( ! id || ! choiceId || state().busy ) {
				return;
			}
			store().set( { busy: true } );
			MRP.api.decide( id, choiceId ).then( function ( result ) {
				pendingOutcomeFocus = true;
				adoptGame( result && result.game );
			}, handleError );
		},

		/** Advance one month. Blocked server-side when a decision is outstanding. */
		advance: function () {
			var id = state().currentGameId;

			if ( ! id || state().busy ) {
				return;
			}
			store().set( { busy: true } );
			MRP.api.advance( id ).then( function ( result ) {
				adoptGame( result && result.game );
				store().set( { view: 'situation' } );
			}, handleError );
		},

		/** Explicit save (every mutation autosaves; this is the reassurance button). */
		saveGame: function () {
			var id = state().currentGameId;

			if ( ! id || state().busy ) {
				return;
			}
			store().set( { busy: true } );
			MRP.api.save( id ).then( function () {
				store().set( { busy: false } );
				MRP.ui.toast( 'Game saved.', 'success' );
			}, handleError );
		},

		/**
		 * Switch the centre column.
		 *
		 * @param {string} view View id.
		 */
		setView: function ( view ) {
			store().set( { view: view } );
		},

		/**
		 * Switch the right-hand tab.
		 *
		 * @param {string} panel Panel id.
		 */
		setPanel: function ( panel ) {
			store().set( { panel: panel } );
		},

		/**
		 * Toggle the developer drawer.
		 *
		 * @param {boolean} [force] Explicit target state.
		 */
		toggleDev: function ( force ) {
			var next = undefined === force ? ! state().devOpen : !! force;

			store().set( { devOpen: !! config.devMode && next } );
		}
	};

	/* --- Render loop ------------------------------------------------------ */

	function screenNode( current ) {
		var views = MRP.views;

		if ( 'new' === current.screen ) {
			return views.newGame.render( current );
		}
		if ( 'game' === current.screen && current.game ) {
			return views.dashboard.render( current );
		}

		return views.title.render( current );
	}

	function outcomeKey( game ) {
		var outcome = game && game.last_outcome;

		if ( ! outcome ) {
			return null;
		}

		return String( outcome.event_id ) + ':' + String( outcome.choice_id ) + ':' + String( game.turn );
	}

	/**
	 * Detach the toast layer so a full re-render does not destroy messages that were
	 * raised in the same tick as the state change which triggered the render.
	 *
	 * @return {?Element} The detached layer, if any.
	 */
	function detachToasts() {
		var i;

		for ( i = 0; i < root.childNodes.length; i++ ) {
			if ( root.childNodes[ i ].nodeType === 1 &&
				-1 !== String( root.childNodes[ i ].className ).indexOf( 'mrp-toasts' ) ) {
				return root.removeChild( root.childNodes[ i ] );
			}
		}

		return null;
	}

	function render( current ) {
		var ui = MRP.ui;
		var key;
		var toasts;

		if ( ! root ) {
			return;
		}
		root.setAttribute( 'data-busy', current.busy ? 'true' : 'false' );
		root.setAttribute( 'data-screen', current.screen );

		toasts = detachToasts();
		ui.replace( root, screenNode( current ) );
		if ( toasts ) {
			root.appendChild( toasts );
		}

		if ( config.devMode && current.devOpen && current.game ) {
			root.appendChild( MRP.views.devPanel.render( current ) );
		}

		key = outcomeKey( current.game );
		if ( key && key !== lastOutcomeKey && pendingOutcomeFocus ) {
			pendingOutcomeFocus = false;
			window.setTimeout( function () {
				var outcome = document.getElementById( 'mrp-outcome' );
				ui.focus( outcome );
				if ( outcome && outcome.scrollIntoView ) {
					outcome.scrollIntoView( { block: 'start', behavior: 'smooth' } );
				}
			}, 0 );
		}
		lastOutcomeKey = key;
	}

	/* --- Boot ------------------------------------------------------------- */

	function boot() {
		var current;

		root = document.getElementById( ROOT_ID );
		config = window.MRP_CONFIG || {};

		if ( ! root ) {
			return;
		}
		if ( 'locked' === root.getAttribute( 'data-mrp-state' ) || false === config.isLoggedIn ) {
			/* The template already rendered the logged-out gate; leave it in place. */
			return;
		}

		store().subscribe( render );
		current = state();
		render( current );

		MRP.actions.refreshGames().then( function () {
			var resumeId = state().currentGameId;

			if ( resumeId ) {
				MRP.actions.continueGame( resumeId );
			}
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && state().devOpen ) {
				MRP.actions.toggleDev( false );
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}

	MRP.app = { boot: boot, render: render, ERROR_MESSAGES: ERROR_MESSAGES };
}( window, document ) );
