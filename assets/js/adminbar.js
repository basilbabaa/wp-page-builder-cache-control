/**
 * Cloudflare admin bar menu behaviour.
 *
 * Both items act in place and report back by swapping their own label, so
 * nothing reloads and you keep your scroll position. The purge item carries a
 * working admin-post href underneath, so preventing the default here is the
 * only thing that makes this the JavaScript path rather than the fallback.
 *
 * @package PageBuilderCacheControl
 */

( function () {
	'use strict';

	var FEEDBACK_MS = 2200;
	var data = window.pbccBarData;

	if ( ! data ) {
		return;
	}

	/**
	 * Swap an item's label, then put the original back.
	 *
	 * @param {HTMLElement} link  The menu item anchor.
	 * @param {string}      text  Text to show.
	 * @param {string}      state One of working, done, failed.
	 * @param {string}      reset Label to restore.
	 */
	function report( link, text, state, reset ) {
		window.clearTimeout( link._pbccTimer );

		link.innerHTML = text;
		link.classList.remove( 'is-working', 'is-done', 'is-failed' );
		link.classList.add( 'is-' + state );

		if ( 'working' === state ) {
			return;
		}

		link._pbccTimer = window.setTimeout( function () {
			link.innerHTML = reset;
			link.classList.remove( 'is-working', 'is-done', 'is-failed' );
		}, FEEDBACK_MS );
	}

	/**
	 * @param {string} url Endpoint to POST to.
	 * @return {Promise<Object>} Parsed response body.
	 */
	function post( url ) {
		return window
			.fetch( url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'X-WP-Nonce': data.nonce,
					'Content-Type': 'application/json'
				}
			} )
			.then( function ( response ) {
				return response.json().catch( function () {
					return { success: false, message: 'HTTP ' + response.status };
				} );
			} );
	}

	function onPurge( event ) {
		event.preventDefault();

		var link = event.currentTarget;

		if ( link.classList.contains( 'is-working' ) ) {
			return;
		}

		report( link, data.i18n.working, 'working', data.i18n.purgeLabel );

		post( data.purgeUrl )
			.then( function ( body ) {
				var ok = !! ( body && body.success );

				report(
					link,
					ok ? data.i18n.purged : data.i18n.failed,
					ok ? 'done' : 'failed',
					data.i18n.purgeLabel
				);
			} )
			.catch( function () {
				report( link, data.i18n.failed, 'failed', data.i18n.purgeLabel );
			} );
	}

	function onCopy( event ) {
		event.preventDefault();

		var link = event.currentTarget;

		if ( link.classList.contains( 'is-working' ) ) {
			return;
		}

		report( link, data.i18n.working, 'working', data.i18n.copyLabel );

		post( data.previewUrl )
			.then( function ( body ) {
				if ( ! body || ! body.url ) {
					throw new Error( 'no link' );
				}

				return window.pbccCopyText( body.url ).then(
					function () {
						report( link, data.i18n.copied, 'done', data.i18n.copyLabel );
					},
					function () {
						window.console.log( 'Uncached preview link:', body.url );
						report( link, data.i18n.failed, 'failed', data.i18n.copyLabel );
					}
				);
			} )
			.catch( function () {
				report( link, data.i18n.failed, 'failed', data.i18n.copyLabel );
			} );
	}

	function init() {
		// Bound by class, not node id: which items exist depends on what is
		// configured, and either one can be absent without the other.
		var purge = document.querySelector( '#wpadminbar .pbcc-adminbar__purge a' );
		var copy = document.querySelector( '#wpadminbar .pbcc-adminbar__preview a' );

		if ( purge && data.purgeUrl ) {
			purge.addEventListener( 'click', onPurge );
		}

		if ( copy ) {
			copy.addEventListener( 'click', onCopy );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
