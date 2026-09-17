/**
 * Shared clipboard helper for every surface.
 *
 * The async Clipboard API is refused in some contexts even over HTTPS, and in
 * others - an unfocused document especially - it neither resolves nor rejects.
 * A caller awaiting it would sit on "working" forever, so the promise is raced
 * against a timeout and falls back to a hidden textarea.
 *
 * @package PageBuilderCacheControl
 */

( function () {
	'use strict';

	var TIMEOUT_MS = 2500;

	/**
	 * Selection-based copy. Works without clipboard permissions, but requires
	 * the document to accept a selection.
	 *
	 * @param {string} text Text to copy.
	 * @return {Promise<void>} Resolves when copied.
	 */
	function legacyCopy( text ) {
		return new Promise( function ( resolve, reject ) {
			var field = document.createElement( 'textarea' );

			field.value = text;
			field.setAttribute( 'readonly', 'readonly' );
			field.style.position = 'fixed';
			field.style.top = '0';
			field.style.opacity = '0';
			document.body.appendChild( field );
			field.select();

			try {
				if ( document.execCommand( 'copy' ) ) {
					resolve();
				} else {
					reject( new Error( 'copy rejected' ) );
				}
			} catch ( error ) {
				reject( error );
			} finally {
				document.body.removeChild( field );
			}
		} );
	}

	/**
	 * @param {string} text Text to place on the clipboard.
	 * @return {Promise<void>} Resolves once copied by whichever route worked.
	 */
	window.pbccCopyText = function ( text ) {
		var clipboard = window.navigator.clipboard;

		if ( ! clipboard || ! clipboard.writeText ) {
			return legacyCopy( text );
		}

		var timed = new Promise( function ( resolve, reject ) {
			window.setTimeout( function () {
				reject( new Error( 'clipboard timed out' ) );
			}, TIMEOUT_MS );
		} );

		// If the API stalls or refuses, fall back rather than hanging. A late
		// success from the original promise is harmless - it copies the same text.
		return Promise.race( [ clipboard.writeText( text ), timed ] ).catch( function () {
			return legacyCopy( text );
		} );
	};
} )();
