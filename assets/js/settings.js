/**
 * Settings screen field dependencies.
 *
 * Consolidation has nothing to consolidate into without the admin bar menu, so
 * that field follows this one. The server enforces the same rule on save - this
 * only makes the relationship visible while you are still deciding.
 *
 * @package PageBuilderCacheControl
 */

( function () {
	'use strict';

	function init() {
		var menu = document.querySelector( 'input[name="admin_bar"]' );
		var adopt = document.querySelector( 'input[name="adopt_menus"]' );

		// Either can be absent when locked by a wp-config constant, in which
		// case the server already rendered the correct state.
		if ( ! menu || ! adopt ) {
			return;
		}

		function sync() {
			adopt.disabled = ! menu.checked;

			if ( ! menu.checked ) {
				adopt.checked = false;
			}
		}

		menu.addEventListener( 'change', sync );
		sync();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
