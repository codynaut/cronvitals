/**
 * CronVitals admin scripts.
 *
 * Adds a confirmation prompt to destructive actions (elements carrying the
 * .cronvitals-confirm class with a data-confirm message).
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var targets = document.querySelectorAll( '.cronvitals-confirm' );

		targets.forEach( function ( el ) {
			el.addEventListener( 'click', function ( event ) {
				var message = el.getAttribute( 'data-confirm' ) || 'Are you sure?';
				if ( ! window.confirm( message ) ) {
					event.preventDefault();
				}
			} );
		} );
	} );
} )();
