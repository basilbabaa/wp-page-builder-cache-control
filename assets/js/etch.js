/**
 * Mounts cache purge controls into the Etch builder's left settings rail.
 *
 * Etch renders its UI with Svelte into #etch-builder, so the rail does not exist
 * at script-execution time and may be re-rendered later. We anchor to the
 * semantic `.settings-bar__section.bottom` list (not the hashed svelte-* classes)
 * and borrow a sibling button's class list at run time, so the controls pick up
 * Etch's own button styling and keep working when its hashes change. A light
 * observer re-mounts the controls if the rail is rebuilt.
 *
 * @package PageBuilderCacheControl
 */

( function () {
	'use strict';

	var MOUNT_SELECTOR = '.settings-bar__section.bottom';
	var FEEDBACK_MS = 1800;

	var data = window.pbccData;

	if ( ! data || ! Array.isArray( data.controls ) || ! data.controls.length ) {
		return;
	}

	var STATE_ICONS = {
		success:
			'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m4 12.5 5 5L20 6.5"/></svg>',
		error:
			'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>'
	};

	/**
	 * Class list of an existing rail button, so ours match Etch's styling.
	 *
	 * @param {HTMLElement} mount The rail section.
	 * @return {string} Class list.
	 */
	function railButtonClasses( mount ) {
		var sample = mount.querySelector( 'button.etch-builder-button:not(.pbcc-control)' );

		return sample ? sample.className : 'etch-builder-button';
	}

	/**
	 * Build one rail button.
	 *
	 * @param {{id:string,label:string,icon:string,type?:string}} control Provider descriptor.
	 * @param {string} classes Borrowed Etch button classes.
	 * @return {HTMLButtonElement} The button.
	 */
	function createControl( control, classes ) {
		var button = document.createElement( 'button' );

		button.type = 'button';
		button.className = classes + ' pbcc-control';
		button.dataset.pbccProvider = control.id;
		button.dataset.pbccLabel = control.label;
		button.setAttribute( 'aria-label', control.label );
		button.title = control.label;
		button.innerHTML =
			'<span class="pbcc-control__icon pbcc-control__icon--default">' + control.icon + '</span>' +
			'<span class="pbcc-control__icon pbcc-control__icon--success">' + STATE_ICONS.success + '</span>' +
			'<span class="pbcc-control__icon pbcc-control__icon--error">' + STATE_ICONS.error + '</span>';

		button.addEventListener( 'click', function () {
			if ( 'preview' === control.type ) {
				copyPreviewLink( control, button );
				return;
			}

			purge( control, button );
		} );

		return button;
	}

	/**
	 * Reset any prior state, then flag the new one and schedule a reset.
	 *
	 * @param {HTMLElement} button Control.
	 * @param {string}      state  One of busy, success, error, or empty to clear.
	 */
	function setState( button, state ) {
		button.classList.remove( 'is-busy', 'is-success', 'is-error' );

		// Always drop a pending reset, or an old timer clears a newer result early.
		window.clearTimeout( button._pbccTimer );

		if ( ! state ) {
			return;
		}

		button.classList.add( 'is-' + state );

		if ( 'busy' === state ) {
			return;
		}

		button._pbccTimer = window.setTimeout( function () {
			button.classList.remove( 'is-success', 'is-error' );
			button.title = button.dataset.pbccLabel;
		}, FEEDBACK_MS );
	}

	function finish( button, ok, message ) {
		button.title = message;
		setState( button, ok ? 'success' : 'error' );
	}

	/**
	 * Fire the purge request and reflect the outcome on the icon itself.
	 *
	 * @param {Object}      control Provider descriptor.
	 * @param {HTMLElement} button  Control.
	 */
	function purge( control, button ) {
		if ( button.classList.contains( 'is-busy' ) ) {
			return;
		}

		setState( button, 'busy' );

		window
			.fetch( data.restUrl + encodeURIComponent( control.id ), {
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
			} )
			.then( function ( body ) {
				var ok = !! ( body && body.success );

				finish( button, ok, ok ? body.message : data.i18n.failed + ': ' + ( ( body && body.message ) || '' ) );
			} )
			.catch( function ( error ) {
				finish( button, false, data.i18n.failed + ': ' + error.message );
			} );
	}

	/**
	 * Mint an uncached-preview link and put it straight on the clipboard.
	 *
	 * @param {Object}      control Control descriptor.
	 * @param {HTMLElement} button  Control.
	 */
	function copyPreviewLink( control, button ) {
		if ( button.classList.contains( 'is-busy' ) ) {
			return;
		}

		setState( button, 'busy' );

		window
			.fetch( data.previewUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'X-WP-Nonce': data.nonce,
					'Content-Type': 'application/json'
				}
			} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( body ) {
				if ( ! body || ! body.url ) {
					throw new Error( ( body && body.message ) || 'no link returned' );
				}

				return window.pbccCopyText( body.url ).then(
					function () {
						finish( button, true, data.i18n.copied );
					},
					function () {
						// Clipboard refused - surface the link so it is not lost.
						window.console.log( 'Uncached preview link:', body.url );
						finish( button, false, data.i18n.copyFailed );
					}
				);
			} )
			.catch( function ( error ) {
				finish( button, false, data.i18n.failed + ': ' + error.message );
			} );
	}

	/**
	 * Put the controls at the top of the rail's bottom section, once.
	 *
	 * @param {HTMLElement} mount The rail section.
	 */
	function mount( mount ) {
		if ( mount.querySelector( '.pbcc-control' ) ) {
			return;
		}

		var classes = railButtonClasses( mount );
		var fragment = document.createDocumentFragment();

		data.controls.forEach( function ( control ) {
			fragment.appendChild( createControl( control, classes ) );
		} );

		mount.insertBefore( fragment, mount.firstChild );
	}

	function check() {
		var target = document.querySelector( MOUNT_SELECTOR );

		// Wait until Etch has drawn at least one of its own buttons to borrow from.
		if ( target && target.querySelector( 'button.etch-builder-button' ) ) {
			mount( target );
		}
	}

	function init() {
		var scheduled = false;
		var root = document.getElementById( 'etch-builder' ) || document.body;

		check();

		// Etch can rebuild the rail (page switch, panel changes); re-mount if so.
		// One querySelector per animation frame is cheap against Svelte's churn.
		new window.MutationObserver( function () {
			if ( scheduled ) {
				return;
			}

			scheduled = true;
			window.requestAnimationFrame( function () {
				scheduled = false;
				check();
			} );
		} ).observe( root, { childList: true, subtree: true } );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
