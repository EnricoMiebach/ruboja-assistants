/**
 * Front end of Ruboja Assistants.
 *
 * Asks the WordPress REST API for a one-time chat URL and shows it in an iframe,
 * either inline or in a floating panel. The API key stays on the server.
 */
( function () {
	'use strict';

	const config = window.RubojaAssistants || {};
	const i18n = config.i18n || {};

	function requestSession( assistant ) {
		const headers = { 'Content-Type': 'application/json' };

		if ( config.nonce ) {
			headers[ 'X-WP-Nonce' ] = config.nonce;
		}

		return fetch( config.restUrl + 'sessions', {
			method: 'POST',
			credentials: 'same-origin',
			headers,
			body: JSON.stringify( { assistant } ),
		} ).then( ( response ) =>
			response
				.json()
				.catch( () => ( {} ) )
				.then( ( data ) => {
					if ( ! response.ok || ! data.url ) {
						throw new Error( data.message || i18n.error );
					}
					return data;
				} )
		);
	}

	function setStatus( root, text ) {
		const status = root.querySelector( '.ruboja-assistant__status' );

		if ( status ) {
			status.textContent = text || '';
		}
	}

	function start( root, options ) {
		const state = root.dataset.state;

		if ( 'loading' === state || 'ready' === state ) {
			return;
		}

		const stage = root.querySelector( '.ruboja-assistant__stage' );
		const button = root.querySelector( '.ruboja-assistant__start' );

		root.dataset.state = 'loading';
		root.setAttribute( 'aria-busy', 'true' );

		if ( button ) {
			button.disabled = true;
		}

		setStatus( root, i18n.loading );

		requestSession( options.assistant )
			.then( ( session ) => {
				const frame = document.createElement( 'iframe' );
				const placeholder = stage.querySelector( '.ruboja-assistant__placeholder' );
				let revealed = false;

				// Ruboja needs a few seconds to load; keep the status text visible until then.
				const reveal = () => {
					if ( revealed ) {
						return;
					}
					revealed = true;
					frame.classList.add( 'is-loaded' );
					root.removeAttribute( 'aria-busy' );

					if ( placeholder ) {
						placeholder.remove();
					}
				};

				frame.className = 'ruboja-assistant__frame';
				frame.src = session.url;
				frame.title = options.title;
				frame.setAttribute( 'allow', 'clipboard-read; clipboard-write; microphone' );
				frame.setAttribute( 'referrerpolicy', 'strict-origin-when-cross-origin' );
				frame.addEventListener( 'load', reveal, { once: true } );
				window.setTimeout( reveal, 15000 );

				if ( button ) {
					button.hidden = true;
				}

				stage.appendChild( frame );
				root.dataset.state = 'ready';
			} )
			.catch( ( error ) => {
				root.dataset.state = 'error';
				root.removeAttribute( 'aria-busy' );

				if ( button ) {
					button.disabled = false;
					button.textContent = i18n.retry;
				}

				setStatus( root, error.message || i18n.error );
			} );
	}

	function initInline( root, options ) {
		const button = root.querySelector( '.ruboja-assistant__start' );

		if ( button ) {
			button.addEventListener( 'click', () => start( root, options ) );
		}

		if ( options.consent ) {
			return;
		}

		// Without a consent click, start as soon as the assistant scrolls into view.
		if ( ! ( 'IntersectionObserver' in window ) ) {
			start( root, options );
			return;
		}

		const observer = new IntersectionObserver( ( entries ) => {
			if ( entries.some( ( entry ) => entry.isIntersecting ) ) {
				observer.disconnect();
				start( root, options );
			}
		} );

		observer.observe( root );
	}

	function initFloating( root, options ) {
		const launcher = root.querySelector( '.ruboja-assistant__launcher' );
		const panel = root.querySelector( '.ruboja-assistant__panel' );
		const close = root.querySelector( '.ruboja-assistant__close' );
		const button = root.querySelector( '.ruboja-assistant__start' );

		// Fixed positioning breaks inside transformed theme containers, so live at body level.
		document.body.appendChild( root );

		function open() {
			panel.hidden = false;
			root.classList.add( 'is-open' );
			launcher.setAttribute( 'aria-expanded', 'true' );

			if ( ! options.consent ) {
				start( root, options );
			}

			( button && ! button.disabled && 'ready' !== root.dataset.state ? button : close ).focus();
		}

		function hide() {
			panel.hidden = true;
			root.classList.remove( 'is-open' );
			launcher.setAttribute( 'aria-expanded', 'false' );
			launcher.focus();
		}

		launcher.addEventListener( 'click', () => ( panel.hidden ? open() : hide() ) );
		close.addEventListener( 'click', hide );

		if ( button ) {
			button.addEventListener( 'click', () => start( root, options ) );
		}

		root.addEventListener( 'keydown', ( event ) => {
			if ( 'Escape' === event.key && ! panel.hidden ) {
				hide();
			}
		} );
	}

	function init() {
		document.querySelectorAll( '[data-ruboja-assistant]' ).forEach( ( root ) => {
			let options;

			try {
				options = JSON.parse( root.dataset.rubojaAssistant );
			} catch ( e ) {
				return;
			}

			root.removeAttribute( 'data-ruboja-assistant' );

			if ( 'floating' === options.mode ) {
				initFloating( root, options );
			} else {
				initInline( root, options );
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
