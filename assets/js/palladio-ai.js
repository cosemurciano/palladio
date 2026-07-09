/**
 * Palladio — assistente AI (admin).
 *
 * Pilota i pulsanti del metabox AI: generazione scheda, applicazione bozza
 * e generazione traduzione. Ogni azione chiama admin-ajax con il nonce.
 */
( function () {
	'use strict';

	var cfg = window.PalladioAI || {};

	function post( action, data ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce || '' );
		Object.keys( data || {} ).forEach( function ( k ) {
			body.append( k, data[ k ] );
		} );

		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} ).then( function ( r ) {
			return r.json();
		} );
	}

	function init( box ) {
		var postId = box.getAttribute( 'data-post' );
		var status = box.querySelector( '.palladio-ai-status' );
		var draftBox = box.querySelector( '.palladio-ai-draft' );
		var i18n = cfg.i18n || {};

		function setStatus( msg, isError ) {
			status.textContent = msg;
			status.style.color = isError ? '#b91c1c' : '#3c434a';
		}

		function busy( on ) {
			box.querySelectorAll( 'button[data-palladio-ai]' ).forEach( function ( b ) {
				b.disabled = on;
			} );
		}

		box.addEventListener( 'click', function ( event ) {
			var btn = event.target.closest( 'button[data-palladio-ai]' );
			if ( ! btn ) {
				return;
			}
			event.preventDefault();

			var op = btn.getAttribute( 'data-palladio-ai' );
			busy( true );
			setStatus( i18n.working || 'Working…', false );

			if ( 'generate' === op ) {
				post( 'palladio_ai_generate', { post: postId } ).then( function ( res ) {
					busy( false );
					if ( res && res.success ) {
						if ( draftBox ) {
							draftBox.style.display = '';
						}
						setStatus( i18n.genOk || 'Done', false );
					} else {
						setStatus( ( i18n.error || 'Error' ) + ': ' + msg( res ), true );
					}
				} ).catch( fail );
			} else if ( 'apply' === op ) {
				post( 'palladio_ai_apply', { post: postId } ).then( function ( res ) {
					busy( false );
					if ( res && res.success ) {
						setStatus( i18n.applyOk || 'Applied', false );
						window.location.reload();
					} else {
						setStatus( ( i18n.error || 'Error' ) + ': ' + msg( res ), true );
					}
				} ).catch( fail );
			} else if ( 'translate' === op ) {
				var sel = box.querySelector( '#palladio-ai-lang' );
				post( 'palladio_ai_translate', { post: postId, lang: sel ? sel.value : '' } ).then( function ( res ) {
					busy( false );
					if ( res && res.success ) {
						setStatus( i18n.transOk || 'Translated', false );
					} else {
						setStatus( ( i18n.error || 'Error' ) + ': ' + msg( res ), true );
					}
				} ).catch( fail );
			} else if ( 'build' === op ) {
				post( 'palladio_ai_build', { post: postId } ).then( function ( res ) {
					busy( false );
					if ( res && res.success ) {
						setStatus( i18n.buildOk || 'Built', false );
						window.location.reload();
					} else {
						setStatus( ( i18n.error || 'Error' ) + ': ' + msg( res ), true );
					}
				} ).catch( fail );
			} else if ( 'uploadDocs' === op ) {
				post( 'palladio_ai_upload_docs', { post: postId } ).then( function ( res ) {
					busy( false );
					if ( res && res.success ) {
						setStatus( ( res.data && res.data.message ) ? res.data.message : ( i18n.docsOk || 'Uploaded' ), false );
					} else {
						setStatus( ( i18n.error || 'Error' ) + ': ' + msg( res ), true );
					}
				} ).catch( fail );
			}
		} );

		function msg( res ) {
			return ( res && res.data && res.data.message ) ? res.data.message : '';
		}

		function fail() {
			busy( false );
			setStatus( ( i18n.error || 'Error' ), true );
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.palladio-ai-box' ).forEach( init );
	} );
}() );
