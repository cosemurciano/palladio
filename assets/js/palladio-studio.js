/**
 * Palladio — Agente Studio (admin).
 *
 * Chat che dialoga con l'endpoint AJAX dell'agente. Lo storico è tenuto lato
 * client (solo turni user/assistant) e reinviato a ogni messaggio; il loop di
 * tool gira sul server.
 */
( function () {
	'use strict';

	var cfg = window.PalladioStudio || {};
	var i18n = cfg.i18n || {};

	function init( box ) {
		var log = box.querySelector( '[data-studio-log]' );
		var form = box.querySelector( '[data-studio-form]' );
		var input = box.querySelector( '[data-studio-input]' );
		var status = box.querySelector( '[data-studio-status]' );
		var applyBox = box.querySelector( '[data-studio-apply]' );
		var focus = box.getAttribute( 'data-focus' ) || 0;
		var history = [];
		var busy = false;

		function add( role, text ) {
			var msg = document.createElement( 'div' );
			msg.className = 'palladio-studio__msg palladio-studio__msg--' + role;
			msg.textContent = text;
			log.appendChild( msg );
			log.scrollTop = log.scrollHeight;
			return msg;
		}

		// Esegue una singola richiesta AJAX (un passo). Restituisce una Promise
		// con { status, data, raw } dove data è il JSON di WordPress (o null).
		function post( params ) {
			var body = new URLSearchParams();
			body.append( 'action', 'palladio_studio_chat' );
			body.append( 'nonce', cfg.nonce || '' );
			Object.keys( params ).forEach( function ( k ) {
				body.append( k, params[ k ] );
			} );

			return fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} ).then( function ( r ) {
				// Leggi sempre il testo: se il PHP muore (timeout/fatal) la
				// risposta non è JSON e serve una diagnosi leggibile.
				return r.text().then( function ( raw ) {
					var data = null;
					try { data = JSON.parse( raw ); } catch ( e ) { /* non-JSON */ }
					return { status: r.status, data: data, raw: raw };
				} );
			} );
		}

		function fail( res ) {
			var d = res.data;
			var m;
			if ( d && d.data && d.data.message ) {
				m = d.data.message;
			} else if ( ! d ) {
				var snippet = ( res.raw || '' ).replace( /<[^>]*>/g, ' ' ).replace( /\s+/g, ' ' ).trim().slice( 0, 200 );
				m = 'HTTP ' + res.status + ( snippet ? ' — ' + snippet : '' ) + ' (probabile timeout PHP: riprova o alza max_execution_time)';
			} else {
				m = 'risposta senza dettagli dal server';
			}
			busy = false;
			status.textContent = ( i18n.error || 'Error' ) + ': ' + m;
		}

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var text = ( input.value || '' ).trim();
			if ( '' === text || busy ) {
				return;
			}
			busy = true;
			add( 'user', text );
			input.value = '';
			status.textContent = i18n.working || 'Working…';

			// Ogni richiesta esegue un passo dell'agente (una chiamata al
			// modello OPPURE un tool). Finché il server risponde done:false,
			// reinviamo con il "turn" ricevuto per far proseguire il loop,
			// senza mai tenere aperta una singola richiesta lunga (che veniva
			// troncata). Il cap server-side resta 10 round di modello.
			var MAX_STEPS = 60;

			function finish( reply ) {
				busy = false;
				status.textContent = '';
				add( 'assistant', reply );
				history.push( { role: 'user', content: text } );
				history.push( { role: 'assistant', content: reply } );
			}

			function step( turn, count ) {
				if ( count > MAX_STEPS ) {
					busy = false;
					status.textContent = ( i18n.error || 'Error' ) + ': ' + ( i18n.tooManySteps || 'troppi passi, riprova con una richiesta più semplice' );
					return;
				}

				var params = turn
					? { turn: turn }
					: {
						message: text,
						focus: focus,
						apply: ( applyBox && applyBox.checked ) ? '1' : '',
						history: JSON.stringify( history ),
					};

				post( params ).then( function ( res ) {
					var d = res.data;

					if ( ! d || ! d.success || ! d.data ) {
						fail( res );
						return;
					}

					if ( d.data.done ) {
						finish( String( d.data.reply || '' ) );
						return;
					}

					// Passo intermedio: mostra lo stato e prosegui.
					status.textContent = d.data.status || ( i18n.working || 'Working…' );
					step( d.data.turn, count + 1 );
				} ).catch( function ( err ) {
					busy = false;
					status.textContent = ( i18n.error || 'Error' ) + ': ' + ( err && err.message ? err.message : 'rete' );
				} );
			}

			step( '', 1 );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var boxes = document.querySelectorAll( '.palladio-studio__box' );
		Array.prototype.forEach.call( boxes, init );
	} );
}() );
