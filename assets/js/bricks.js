/**
 * Mounts cache purge controls into the Bricks builder toolbar.
 *
 * Bricks renders its toolbar with Vue, so the mount point does not exist at
 * script-execution time. We watch for it rather than polling, and we anchor to
 * `.group-wrapper.end` - a semantic, stable node - instead of reaching into
 * Vue's internals, so this survives Bricks updates.
 *
 * @package PageBuilderCacheControl
 */

( function () {
	'use strict';

	// Bricks 2.4 moved the toolbar into #bricks-workspace and turned
	// #bricks-toolbar (id) into .bricks-toolbar (class). Match either, so one
	// build works across versions while the fleet updates.
	var MOUNT_SELECTOR = '#bricks-toolbar .group-wrapper.end, .bricks-toolbar .group-wrapper.end';
	var FEEDBACK_MS = 1800;
	var OBSERVE_TIMEOUT_MS = 20000;

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
	 * Build one toolbar item.
	 *
	 * @param {{id:string,label:string,icon:string}} control Provider descriptor.
	 * @return {HTMLLIElement} The toolbar item.
	 */
	function createControl( control ) {
		var li = document.createElement( 'li' );

		li.className = 'pbcc-control';
		li.dataset.pbccProvider = control.id;
		li.setAttribute( 'data-balloon', control.label );
		li.setAttribute( 'data-balloon-pos', 'bottom' );

		var button = document.createElement( 'button' );

		button.type = 'button';
		button.className = 'pbcc-control__button';
		button.setAttribute( 'aria-label', control.label );
		button.innerHTML =
			'<span class="pbcc-control__icon pbcc-control__icon--default">' + control.icon + '</span>' +
			'<span class="pbcc-control__icon pbcc-control__icon--success">' + STATE_ICONS.success + '</span>' +
			'<span class="pbcc-control__icon pbcc-control__icon--error">' + STATE_ICONS.error + '</span>';

		button.addEventListener( 'click', function () {
			if ( 'preview' === control.type ) {
				copyPreviewLink( control, li, button );
				return;
			}

			purge( control, li, button );
		} );

		li.appendChild( button );

		return li;
	}

	/**
	 * Reset any prior state, then flag the new one and schedule a reset.
	 *
	 * @param {HTMLElement} li    Toolbar item.
	 * @param {string}      state One of busy, success, error, or empty to clear.
	 */
	function setState( li, state ) {
		li.classList.remove( 'is-busy', 'is-success', 'is-error' );

		// Always drop a pending reset. Without this, clicking again while the
		// previous cycle's timer is still running lets that old timer clear the
		// new result early.
		window.clearTimeout( li._pbccTimer );

		if ( ! state ) {
			return;
		}

		li.classList.add( 'is-' + state );

		if ( 'busy' === state ) {
			return;
		}

		li._pbccTimer = window.setTimeout( function () {
			li.classList.remove( 'is-success', 'is-error' );
			li.setAttribute( 'data-balloon', li.dataset.pbccLabel );
		}, FEEDBACK_MS );
	}

	/**
	 * Fire the purge request and reflect the outcome on the icon itself.
	 *
	 * @param {Object}      control Provider descriptor.
	 * @param {HTMLElement} li      Toolbar item.
	 * @param {HTMLElement} button  Clickable element.
	 */
	function purge( control, li, button ) {
		if ( li.classList.contains( 'is-busy' ) ) {
			return;
		}

		li.dataset.pbccLabel = control.label;
		setState( li, 'busy' );
		button.disabled = true;

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

				li.setAttribute(
					'data-balloon',
					ok ? body.message : data.i18n.failed + ': ' + ( ( body && body.message ) || '' )
				);
				setState( li, ok ? 'success' : 'error' );
			} )
			.catch( function ( error ) {
				li.setAttribute( 'data-balloon', data.i18n.failed + ': ' + error.message );
				setState( li, 'error' );
			} )
			.finally( function () {
				button.disabled = false;
			} );
	}

	/**
	 * Mint an uncached-preview link and put it straight on the clipboard, so the
	 * next action is simply paste-into-incognito.
	 *
	 * @param {Object}      control Control descriptor.
	 * @param {HTMLElement} li      Toolbar item.
	 * @param {HTMLElement} button  Clickable element.
	 */
	function copyPreviewLink( control, li, button ) {
		if ( li.classList.contains( 'is-busy' ) ) {
			return;
		}

		li.dataset.pbccLabel = control.label;
		setState( li, 'busy' );
		button.disabled = true;

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
						li.setAttribute( 'data-balloon', data.i18n.copied );
						setState( li, 'success' );
					},
					function () {
						// Clipboard refused - surface the link so it is not lost.
						window.console.log( 'Uncached preview link:', body.url );
						li.setAttribute( 'data-balloon', data.i18n.copyFailed );
						setState( li, 'error' );
					}
				);
			} )
			.catch( function ( error ) {
				li.setAttribute( 'data-balloon', data.i18n.failed + ': ' + error.message );
				setState( li, 'error' );
			} )
			.finally( function () {
				button.disabled = false;
			} );
	}

	/**
	 * Insert the controls at the leading edge of the toolbar's right-hand group.
	 *
	 * @param {HTMLElement} mount The `.group-wrapper.end` list.
	 */
	function mount( mount ) {
		if ( mount.querySelector( '.pbcc-control' ) ) {
			return;
		}

		var fragment = document.createDocumentFragment();

		data.controls.forEach( function ( control ) {
			fragment.appendChild( createControl( control ) );
		} );

		mount.insertBefore( fragment, mount.firstChild );
	}

	/**
	 * Wait for the Vue-rendered toolbar, then mount once.
	 */
	function init() {
		var existing = document.querySelector( MOUNT_SELECTOR );

		if ( existing ) {
			mount( existing );
			return;
		}

		var observer = new MutationObserver( function () {
			var target = document.querySelector( MOUNT_SELECTOR );

			if ( target ) {
				observer.disconnect();
				mount( target );
			}
		} );

		observer.observe( document.body, { childList: true, subtree: true } );

		// Never leave an observer running against the whole builder indefinitely.
		window.setTimeout( function () {
			observer.disconnect();
		}, OBSERVE_TIMEOUT_MS );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
