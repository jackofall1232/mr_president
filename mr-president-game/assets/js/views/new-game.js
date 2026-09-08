/**
 * MRP.views.newGame — the new-administration form.
 *
 * Client-side validation mirrors the REST argument rules (spec section 8.3): the
 * president name is 2–60 characters of letters, spaces, apostrophes, periods and hyphens.
 * The server re-validates; this only spares the player a round trip.
 */
( function ( window ) {
	'use strict';

	var MRP = window.MRP = window.MRP || {};
	var views = MRP.views = MRP.views || {};

	var NAME_MIN = 2;
	var NAME_MAX = 60;
	var NAME_PATTERN = /^[\p{L}\p{M} .'-]+$/u;

	var OATH = 'I do solemnly swear that I will faithfully execute this office, and will to ' +
		'the best of my ability preserve, protect and defend the constitution of this republic.';

	/**
	 * Validate a president name.
	 *
	 * @param {string} raw Raw input value.
	 * @return {Object} `{valid, value, message}`.
	 */
	function validate( raw ) {
		var value = String( raw || '' ).replace( /\s+/g, ' ' ).trim();

		if ( value.length < NAME_MIN ) {
			return { valid: false, value: value, message: 'Enter a name of at least ' + NAME_MIN + ' characters.' };
		}
		if ( value.length > NAME_MAX ) {
			return { valid: false, value: value, message: 'Names are limited to ' + NAME_MAX + ' characters.' };
		}
		if ( ! NAME_PATTERN.test( value ) ) {
			return {
				valid: false,
				value: value,
				message: 'Use letters, spaces, apostrophes, periods and hyphens only.'
			};
		}

		return { valid: true, value: value, message: '' };
	}

	/**
	 * Render the new-game screen.
	 *
	 * @param {Object} state Store state.
	 * @return {Element} Screen node.
	 */
	function render( state ) {
		var ui = MRP.ui;
		var el = ui.el;
		var errorNode = el( 'p', { 'class': 'mrp-field__error', role: 'alert', hidden: 'hidden' } );
		var input = el( 'input', {
			type: 'text',
			id: 'mrp-president-name',
			'class': 'mrp-input',
			name: 'president_name',
			maxlength: String( NAME_MAX ),
			autocomplete: 'off',
			spellcheck: 'false',
			placeholder: 'e.g. Eleanor R. Hale',
			'aria-describedby': 'mrp-president-name-hint'
		} );
		var submit;
		var form;

		function showError( message ) {
			if ( message ) {
				errorNode.textContent = message;
				errorNode.removeAttribute( 'hidden' );
				input.setAttribute( 'aria-invalid', 'true' );
			} else {
				errorNode.textContent = '';
				errorNode.setAttribute( 'hidden', 'hidden' );
				input.removeAttribute( 'aria-invalid' );
			}
		}

		submit = el( 'button', {
			type: 'submit',
			'class': 'mrp-btn mrp-btn--primary mrp-btn--lg',
			disabled: state.busy ? 'disabled' : null
		}, state.busy ? 'Taking the oath…' : 'Take the oath' );

		form = el( 'form', {
			'class': 'mrp-form',
			novalidate: 'novalidate',
			onSubmit: function ( event ) {
				var result;

				event.preventDefault();
				result = validate( input.value );
				if ( ! result.valid ) {
					showError( result.message );
					ui.focus( input );

					return;
				}
				showError( '' );
				MRP.actions.createGame( result.value );
			}
		}, [
			el( 'label', { 'class': 'mrp-field__label', 'for': 'mrp-president-name', text: 'Name of the president-elect' } ),
			input,
			el( 'p', {
				'class': 'mrp-field__hint',
				id: 'mrp-president-name-hint',
				text: NAME_MIN + '–' + NAME_MAX + ' characters. This name appears on every briefing and headline.'
			} ),
			errorNode,
			el( 'p', { 'class': 'mrp-oath', text: OATH } ),
			el( 'div', { 'class': 'mrp-form__actions' }, [
				el( 'button', {
					type: 'button',
					'class': 'mrp-btn mrp-btn--ghost mrp-btn--lg',
					onClick: function () {
						MRP.actions.showTitle();
					}
				}, 'Back' ),
				submit
			] )
		] );

		input.addEventListener( 'input', function () {
			if ( errorNode.textContent ) {
				showError( '' );
			}
		} );

		window.setTimeout( function () {
			if ( input.isConnected ) {
				ui.focus( input );
			}
		}, 0 );

		return el( 'div', { 'class': 'mrp-screen mrp-animate-in' }, [
			el( 'div', { 'class': 'mrp-screen__inner' }, [
				el( 'div', { 'class': 'mrp-hero' }, [
					el( 'p', { 'class': 'mrp-hero__kicker', text: 'Inauguration Day' } ),
					el( 'h1', { 'class': 'mrp-hero__title', text: 'A New Administration' } ),
					el( 'div', { 'class': 'mrp-hero__rule' } )
				] ),
				form
			] )
		] );
	}

	views.newGame = { render: render, validate: validate, NAME_MIN: NAME_MIN, NAME_MAX: NAME_MAX };
}( window ) );
