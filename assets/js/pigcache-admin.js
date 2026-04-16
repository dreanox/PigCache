/**
 * PigCache — Admin page scripts.
 *
 * @package PigCache
 */
(function () {
	'use strict';

	document.querySelectorAll( '.pigcache-confirm' ).forEach( function ( el ) {
		el.addEventListener( 'click', function ( e ) {
			var msg = el.getAttribute( 'data-confirm' );
			if ( msg && ! window.confirm( msg ) ) {
				e.preventDefault();
			}
		} );
	} );
})();
