/**
 * MRP.ui — DOM construction, formatting and small interaction helpers.
 *
 * Every node in the game is built here through `el()` with text nodes only; server
 * strings are never assigned to innerHTML (spec section 9).
 */
( function ( window, document ) {
	'use strict';

	var MRP = window.MRP = window.MRP || {};

	var TOAST_TTL_MS = 5200;
	var TOAST_FADE_MS = 220;
	var TOAST_MAX = 4;

	var MONTHS = [
		'January', 'February', 'March', 'April', 'May', 'June',
		'July', 'August', 'September', 'October', 'November', 'December'
	];

	/**
	 * Create an element.
	 *
	 * @param {string} tag             Tag name.
	 * @param {Object} [attrs]         Attributes. Special keys: `class`/`className`,
	 *                                 `text` (textContent), `dataset` (object),
	 *                                 `style` (object), `on<Event>` (function listener).
	 *                                 Everything else becomes setAttribute. Null/undefined
	 *                                 values are skipped; `true` sets an empty attribute.
	 * @param {Array|Node|string} [children] Child nodes; strings become text nodes.
	 * @return {Element} The element.
	 */
	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		var key;
		var value;

		if ( attrs ) {
			for ( key in attrs ) {
				if ( ! Object.prototype.hasOwnProperty.call( attrs, key ) ) {
					continue;
				}
				value = attrs[ key ];
				if ( null === value || undefined === value || false === value ) {
					continue;
				}
				if ( 'class' === key || 'className' === key ) {
					node.className = String( value );
				} else if ( 'text' === key ) {
					node.textContent = String( value );
				} else if ( 'dataset' === key ) {
					applyDataset( node, value );
				} else if ( 'style' === key ) {
					applyStyle( node, value );
				} else if ( 0 === key.indexOf( 'on' ) && 'function' === typeof value ) {
					node.addEventListener( key.slice( 2 ).toLowerCase(), value );
				} else if ( true === value ) {
					node.setAttribute( key, '' );
				} else {
					node.setAttribute( key, String( value ) );
				}
			}
		}

		append( node, children );

		return node;
	}

	function applyDataset( node, data ) {
		var key;
		for ( key in data ) {
			if ( Object.prototype.hasOwnProperty.call( data, key ) && null !== data[ key ] && undefined !== data[ key ] ) {
				node.setAttribute( 'data-' + key, String( data[ key ] ) );
			}
		}
	}

	function applyStyle( node, style ) {
		var key;
		if ( 'string' === typeof style ) {
			node.setAttribute( 'style', style );
			return;
		}
		for ( key in style ) {
			if ( Object.prototype.hasOwnProperty.call( style, key ) && null !== style[ key ] && undefined !== style[ key ] ) {
				node.style.setProperty( key, String( style[ key ] ) );
			}
		}
	}

	/**
	 * Append children of any supported shape to a parent node.
	 *
	 * @param {Node} parent Parent.
	 * @param {*}    child  Node, string, number, array, or nullish (skipped).
	 * @return {Node} The parent.
	 */
	function append( parent, child ) {
		var i;

		if ( null === child || undefined === child || false === child || true === child ) {
			return parent;
		}
		if ( Array.isArray( child ) ) {
			for ( i = 0; i < child.length; i++ ) {
				append( parent, child[ i ] );
			}
			return parent;
		}
		if ( child && child.nodeType ) {
			parent.appendChild( child );
			return parent;
		}
		parent.appendChild( document.createTextNode( String( child ) ) );

		return parent;
	}

	/**
	 * Replace all children of a node.
	 *
	 * @param {Node} parent   Parent.
	 * @param {*}    children New children.
	 * @return {Node} The parent.
	 */
	function replace( parent, children ) {
		while ( parent.firstChild ) {
			parent.removeChild( parent.firstChild );
		}

		return append( parent, children );
	}

	function text( value ) {
		return document.createTextNode( null === value || undefined === value ? '' : String( value ) );
	}

	/* --- Formatting ------------------------------------------------------- */

	function isNum( value ) {
		return 'number' === typeof value && isFinite( value );
	}

	function round( value, decimals ) {
		var factor = Math.pow( 10, decimals || 0 );

		return Math.round( value * factor ) / factor;
	}

	var fmt = {
		/**
		 * Percentage-style indicator, no decimals by default.
		 *
		 * @param {number} value    Value.
		 * @param {number} [places] Decimal places.
		 * @return {string} Formatted value with a percent sign.
		 */
		pct: function ( value, places ) {
			if ( ! isNum( value ) ) {
				return '—';
			}

			return round( value, places || 0 ).toFixed( places || 0 ) + '%';
		},

		/**
		 * Plain number with an optional fixed number of decimals.
		 *
		 * @param {number} value    Value.
		 * @param {number} [places] Decimal places (default 0).
		 * @return {string} Formatted number.
		 */
		num: function ( value, places ) {
			var places2 = undefined === places ? 0 : places;

			if ( ! isNum( value ) ) {
				return '—';
			}

			return round( value, places2 ).toFixed( places2 );
		},

		/**
		 * Signed delta string; prefers the server-provided `display` when present.
		 *
		 * @param {number|Object} value  Number, or a delta record with `display`/`delta`.
		 * @param {number}        [places] Decimal places when formatting a raw number.
		 * @return {string} e.g. "+2", "-1.4", "0".
		 */
		delta: function ( value, places ) {
			var n = value;

			if ( value && 'object' === typeof value ) {
				if ( 'string' === typeof value.display && '' !== value.display ) {
					return value.display;
				}
				n = value.delta;
			}
			if ( ! isNum( n ) ) {
				return '—';
			}
			n = round( n, undefined === places ? 1 : places );
			if ( 0 === n ) {
				return '0';
			}

			return ( n > 0 ? '+' : '' ) + String( n );
		},

		/**
		 * Money in billions of dollars.
		 *
		 * @param {number} value Value in $bn.
		 * @return {string} e.g. "$120bn".
		 */
		money: function ( value ) {
			if ( ! isNum( value ) ) {
				return '—';
			}

			return ( value < 0 ? '-$' : '$' ) + fmt.num( Math.abs( value ), 0 ) + 'bn';
		},

		/**
		 * "April 2001" from an ISO date, with a graceful fallback.
		 *
		 * @param {string} iso ISO date, `YYYY-MM-DD` or `YYYY-MM`.
		 * @return {string} Month label.
		 */
		date: function ( iso ) {
			var parts = fmt.parseDate( iso );

			if ( ! parts ) {
				return String( iso || '' );
			}

			return MONTHS[ parts.month - 1 ] + ' ' + parts.year;
		},

		/**
		 * Parse an ISO-ish date into numeric parts.
		 *
		 * @param {string} iso ISO date.
		 * @return {?Object} `{year, month, day}` or null.
		 */
		parseDate: function ( iso ) {
			var match = /^(\d{4})-(\d{2})(?:-(\d{2}))?/.exec( String( iso || '' ) );

			if ( ! match ) {
				return null;
			}

			return {
				year: parseInt( match[ 1 ], 10 ),
				month: parseInt( match[ 2 ], 10 ),
				day: match[ 3 ] ? parseInt( match[ 3 ], 10 ) : 1
			};
		},

		/**
		 * Month label one calendar month after the given date.
		 *
		 * @param {string} iso ISO date.
		 * @return {string} Next month label, or an empty string when unparseable.
		 */
		nextMonthLabel: function ( iso ) {
			var parts = fmt.parseDate( iso );
			var month;
			var year;

			if ( ! parts ) {
				return '';
			}
			month = parts.month + 1;
			year = parts.year;
			if ( 12 < month ) {
				month = 1;
				year += 1;
			}

			return MONTHS[ month - 1 ] + ' ' + year;
		},

		/**
		 * Initials for an advisor avatar.
		 *
		 * @param {string} name Full name.
		 * @return {string} Up to two uppercase initials.
		 */
		initials: function ( name ) {
			var words = String( name || '' ).trim().split( /\s+/ );
			var first = words[ 0 ] ? words[ 0 ].charAt( 0 ) : '';
			var last = 1 < words.length ? words[ words.length - 1 ].charAt( 0 ) : '';

			return ( first + last ).toUpperCase();
		},

		/**
		 * Humanise an id such as `european_allies` or `rival-state-a`.
		 *
		 * @param {string} id Identifier.
		 * @return {string} Title-cased words.
		 */
		humanize: function ( id ) {
			return String( id || '' )
				.replace( /[_-]+/g, ' ' )
				.replace( /\b\w/g, function ( c ) {
					return c.toUpperCase();
				} );
		}
	};

	/* --- Toasts ----------------------------------------------------------- */

	var toastHost = null;

	function toastLayer() {
		var root;

		if ( toastHost && toastHost.isConnected ) {
			return toastHost;
		}
		root = document.getElementById( 'mrp-app' ) || document.body;
		toastHost = el( 'div', { 'class': 'mrp-toasts', 'aria-live': 'polite', 'aria-atomic': 'false' } );
		root.appendChild( toastHost );

		return toastHost;
	}

	var TOAST_KICKERS = {
		error: 'Situation Room',
		warn: 'Advisory',
		success: 'Confirmed',
		info: 'Notice'
	};

	/**
	 * Show a transient message in the bottom-right toast stack.
	 *
	 * @param {string} message Message text (plain text; never markup).
	 * @param {string} [kind]  One of `info`, `success`, `warn`, `error`.
	 * @return {Element} The toast node.
	 */
	function toast( message, kind ) {
		var layer = toastLayer();
		var type = TOAST_KICKERS[ kind ] ? kind : 'info';
		var node = el( 'div', { 'class': 'mrp-toast mrp-toast--' + type, role: 'status' }, [
			el( 'div', {}, [
				el( 'span', { 'class': 'mrp-toast__kicker', text: TOAST_KICKERS[ type ] } ),
				el( 'span', { text: String( message ) } )
			] )
		] );

		layer.appendChild( node );
		while ( layer.childNodes.length > TOAST_MAX ) {
			layer.removeChild( layer.firstChild );
		}

		window.setTimeout( function () {
			node.className += ' mrp-toast--leaving';
			window.setTimeout( function () {
				if ( node.parentNode ) {
					node.parentNode.removeChild( node );
				}
			}, TOAST_FADE_MS );
		}, TOAST_TTL_MS );

		return node;
	}

	/* --- Focus helpers ---------------------------------------------------- */

	var FOCUSABLE = 'button:not(:disabled), [href], input:not(:disabled), select:not(:disabled),' +
		' textarea:not(:disabled), [tabindex]:not([tabindex="-1"])';

	/**
	 * Move focus to a node, adding a temporary tabindex for non-focusable elements.
	 *
	 * @param {Element} node Target.
	 */
	function focus( node ) {
		if ( ! node ) {
			return;
		}
		if ( ! node.hasAttribute( 'tabindex' ) && ! /^(A|BUTTON|INPUT|SELECT|TEXTAREA)$/.test( node.tagName ) ) {
			node.setAttribute( 'tabindex', '-1' );
		}
		try {
			node.focus( { preventScroll: false } );
		} catch ( e ) {
			node.focus();
		}
	}

	/**
	 * Focus the first focusable descendant, falling back to the container itself.
	 *
	 * @param {Element} container Container.
	 */
	function focusFirst( container ) {
		var target = container ? container.querySelector( FOCUSABLE ) : null;

		focus( target || container );
	}

	/**
	 * Keep Tab focus inside a container (used by modals and the dev drawer).
	 *
	 * @param {Element} container Container.
	 * @return {Function} Detach function.
	 */
	function trapFocus( container ) {
		function onKeydown( event ) {
			var nodes;
			var first;
			var last;

			if ( 'Tab' !== event.key ) {
				return;
			}
			nodes = container.querySelectorAll( FOCUSABLE );
			if ( ! nodes.length ) {
				return;
			}
			first = nodes[ 0 ];
			last = nodes[ nodes.length - 1 ];
			if ( event.shiftKey && document.activeElement === first ) {
				event.preventDefault();
				last.focus();
			} else if ( ! event.shiftKey && document.activeElement === last ) {
				event.preventDefault();
				first.focus();
			}
		}

		container.addEventListener( 'keydown', onKeydown );

		return function () {
			container.removeEventListener( 'keydown', onKeydown );
		};
	}

	MRP.ui = {
		el: el,
		append: append,
		replace: replace,
		text: text,
		fmt: fmt,
		toast: toast,
		focus: focus,
		focusFirst: focusFirst,
		trapFocus: trapFocus,
		FOCUSABLE: FOCUSABLE,
		MONTHS: MONTHS
	};
}( window, document ) );
