/**
 * MRP.store — the single client-side state container.
 *
 * State shape (spec section 9):
 *   screen        'title' | 'new' | 'game'
 *   game          the game view model from the REST layer, or null
 *   briefing      the briefing payload, or null
 *   view          'situation' | 'economy' | 'congress' | 'diplomacy' | 'security' | 'history'
 *   panel         'brief' | 'cabinet' | 'intel' | 'congress'
 *   busy          truthy while a request is in flight
 *   toast         the last toast record `{message, kind}` (rendered by app.js)
 *   error         the last fatal error message, or null
 *   devOpen       developer drawer visibility
 *   currentGameId persisted to localStorage so a reload resumes
 *   games         saved-game list for the title screen (additive: the title screen
 *                 needs the list from `listGames()` in render, not in a fetch)
 */
( function ( window ) {
	'use strict';

	var MRP = window.MRP = window.MRP || {};

	var STORAGE_KEY = 'mrp.currentGameId';

	var state = {
		screen: 'title',
		game: null,
		briefing: null,
		view: 'situation',
		panel: 'brief',
		busy: false,
		toast: null,
		error: null,
		devOpen: false,
		currentGameId: readStoredId(),
		games: []
	};

	var subscribers = [];

	function readStoredId() {
		try {
			return window.localStorage.getItem( STORAGE_KEY ) || null;
		} catch ( e ) {
			return null;
		}
	}

	function writeStoredId( id ) {
		try {
			if ( id ) {
				window.localStorage.setItem( STORAGE_KEY, String( id ) );
			} else {
				window.localStorage.removeItem( STORAGE_KEY );
			}
		} catch ( e ) {
			/* Private browsing or disabled storage: resuming simply will not persist. */
		}
	}

	function notify() {
		var listeners = subscribers.slice();
		var i;

		for ( i = 0; i < listeners.length; i++ ) {
			try {
				listeners[ i ]( state );
			} catch ( e ) {
				if ( window.console && window.console.error ) {
					window.console.error( '[Mr. President] subscriber failed', e );
				}
			}
		}
	}

	MRP.store = {
		/**
		 * @return {Object} The live state object. Treat it as read-only; mutate via `set()`.
		 */
		get: function () {
			return state;
		},

		/**
		 * Merge a patch into the state and notify subscribers.
		 *
		 * @param {Object} patch Partial state.
		 * @return {Object} The new state.
		 */
		set: function ( patch ) {
			var key;
			var changed = false;

			if ( ! patch ) {
				return state;
			}
			for ( key in patch ) {
				if ( ! Object.prototype.hasOwnProperty.call( patch, key ) ) {
					continue;
				}
				if ( state[ key ] !== patch[ key ] ) {
					changed = true;
				}
				state[ key ] = patch[ key ];
			}
			if ( Object.prototype.hasOwnProperty.call( patch, 'currentGameId' ) ) {
				writeStoredId( patch.currentGameId );
			}
			if ( changed ) {
				notify();
			}

			return state;
		},

		/**
		 * Subscribe to state changes.
		 *
		 * @param {Function} fn Listener invoked with the state.
		 * @return {Function} Unsubscribe.
		 */
		subscribe: function ( fn ) {
			subscribers.push( fn );

			return function () {
				var index = subscribers.indexOf( fn );

				if ( -1 !== index ) {
					subscribers.splice( index, 1 );
				}
			};
		},

		STORAGE_KEY: STORAGE_KEY
	};
}( window ) );
