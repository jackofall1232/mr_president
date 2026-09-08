/**
 * MRP.api — thin REST client for the `mr-president/v1` namespace.
 *
 * The browser sends intent only: a choice id or an empty advance (spec section 0.3).
 * Nothing here ever posts a state value. Errors from WordPress arrive as
 * `{code, message, data:{status}}` and are re-thrown as an Error carrying `code`,
 * `status` and `data` so views can branch on `decision_required` and friends.
 */
( function ( window ) {
	'use strict';

	var MRP = window.MRP = window.MRP || {};

	var GENERIC_ERROR = 'The situation room could not be reached. Try again.';

	function config() {
		return window.MRP_CONFIG || {};
	}

	function base() {
		var url = String( config().restUrl || '' );

		return url && '/' !== url.charAt( url.length - 1 ) ? url + '/' : url;
	}

	/**
	 * Build an Error carrying the WordPress error envelope.
	 *
	 * @param {string} code    Machine code, e.g. `decision_required`.
	 * @param {string} message Human message.
	 * @param {number} status  HTTP status.
	 * @param {Object} [data]  Raw payload.
	 * @return {Error} Error with `code`, `status` and `data` properties.
	 */
	function apiError( code, message, status, data ) {
		var error = new Error( message || GENERIC_ERROR );

		error.code = code || 'mrp_request_failed';
		error.status = status || 0;
		error.data = data || null;
		error.isApiError = true;

		return error;
	}

	/**
	 * Normalise a WordPress error code: `mrp_decision_required` → `decision_required`.
	 *
	 * @param {string} code Raw code.
	 * @return {string} Bare code.
	 */
	function bareCode( code ) {
		var value = String( code || '' );

		return 0 === value.indexOf( 'mrp_' ) ? value.slice( 4 ) : value;
	}

	/**
	 * Perform a REST request.
	 *
	 * @param {string} method HTTP method.
	 * @param {string} path   Path relative to the REST namespace root.
	 * @param {Object} [body] JSON body for POST requests.
	 * @return {Promise<Object>} Resolves with the decoded JSON body.
	 */
	function request( method, path, body ) {
		var options = {
			method: method,
			credentials: 'same-origin',
			headers: {
				Accept: 'application/json',
				'X-WP-Nonce': String( config().nonce || '' )
			}
		};

		if ( body ) {
			options.headers[ 'Content-Type' ] = 'application/json';
			options.body = JSON.stringify( body );
		}

		return window.fetch( base() + path, options ).then( function ( response ) {
			var isJson = ( response.headers && response.headers.get &&
				String( response.headers.get( 'Content-Type' ) || '' ).indexOf( 'json' ) !== -1 );

			return ( isJson ? response.json() : response.text() ).then( function ( payload ) {
				var data = ( payload && 'object' === typeof payload ) ? payload : {};

				if ( ! response.ok ) {
					throw apiError(
						bareCode( data.code ),
						data.message || ( response.status + ' ' + ( response.statusText || GENERIC_ERROR ) ),
						response.status,
						data
					);
				}

				return data;
			} );
		}, function ( networkError ) {
			throw apiError( 'network_error', networkError && networkError.message ? networkError.message : GENERIC_ERROR, 0, null );
		} );
	}

	function pick( key ) {
		return function ( data ) {
			return ( data && undefined !== data[ key ] ) ? data[ key ] : data;
		};
	}

	MRP.api = {
		/**
		 * @return {Promise<Array>} Saved games for the current user.
		 */
		listGames: function () {
			return request( 'GET', 'games' ).then( function ( data ) {
				return ( data && Array.isArray( data.games ) ) ? data.games : [];
			} );
		},

		/**
		 * @param {string} name         President name (2–60 characters).
		 * @param {string} [scenarioId] Scenario id; the server defaults it.
		 * @return {Promise<Object>} The new game view model.
		 */
		newGame: function ( name, scenarioId ) {
			var body = { president_name: name };

			if ( scenarioId ) {
				body.scenario_id = scenarioId;
			}

			return request( 'POST', 'game/new', body ).then( pick( 'game' ) );
		},

		/**
		 * @param {string} id Game uuid.
		 * @return {Promise<Object>} Game view model.
		 */
		getGame: function ( id ) {
			return request( 'GET', 'game/' + encodeURIComponent( id ) ).then( pick( 'game' ) );
		},

		/**
		 * @param {string} id Game uuid.
		 * @return {Promise<Object>} Briefing payload.
		 */
		getBriefing: function ( id ) {
			return request( 'GET', 'game/' + encodeURIComponent( id ) + '/briefing' ).then( pick( 'briefing' ) );
		},

		/**
		 * @param {string} id       Game uuid.
		 * @param {string} choiceId Chosen option id.
		 * @return {Promise<Object>} `{outcome, game}`.
		 */
		decide: function ( id, choiceId ) {
			return request( 'POST', 'game/' + encodeURIComponent( id ) + '/decision', { choice_id: choiceId } );
		},

		/**
		 * @param {string} id Game uuid.
		 * @return {Promise<Object>} `{turn_report, game}`.
		 */
		advance: function ( id ) {
			return request( 'POST', 'game/' + encodeURIComponent( id ) + '/advance', {} );
		},

		/**
		 * @param {string} id Game uuid.
		 * @return {Promise<Object>} `{saved_at}`.
		 */
		save: function ( id ) {
			return request( 'POST', 'game/' + encodeURIComponent( id ) + '/save', {} );
		},

		/**
		 * @param {string} id Game uuid.
		 * @return {Promise<Object>} `{deleted:true}`.
		 */
		deleteGame: function ( id ) {
			return request( 'DELETE', 'game/' + encodeURIComponent( id ) );
		},

		error: apiError,
		request: request
	};
}( window ) );
