/**
 * Settings page: copy shortcodes to the clipboard.
 */
( function () {
	'use strict';

	const strings = window.RubojaAssistantsAdmin || {};

	document.addEventListener( 'click', ( event ) => {
		const button = event.target.closest( '.ruboja-assistants-admin__copy' );

		if ( ! button || ! navigator.clipboard ) {
			return;
		}

		const label = button.textContent;

		navigator.clipboard.writeText( button.dataset.copy ).then( () => {
			button.textContent = strings.copied || label;
			window.setTimeout( () => {
				button.textContent = label;
			}, 1500 );
		} );
	} );
} )();
