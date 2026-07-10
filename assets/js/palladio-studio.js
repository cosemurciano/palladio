/**
 * Palladio — Agente Studio (admin).
 *
 * Chat con l'endpoint AJAX dell'agente. Lo storico è persistente lato server
 * (user meta): il client lo riceve al caricamento e lo mostra con data/ora.
 * Il loop di tool gira sul server un passo per richiesta.
 */
( function () {
	'use strict';

	var cfg = window.PalladioStudio || {};
	var i18n = cfg.i18n || {};

	/**
	 * Formatta un timestamp (secondi) in ora — con data se non è oggi.
	 */
	function formatTime( seconds ) {
		var d = seconds ? new Date( seconds * 1000 ) : new Date();
		var now = new Date();
		var time = d.toLocaleTimeString( undefined, { hour: '2-digit', minute: '2-digit' } );
		var sameDay = d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth() && d.getDate() === now.getDate();
		if ( sameDay ) {
			return time;
		}
		return d.toLocaleDateString( undefined, { day: 'numeric', month: 'short' } ) + ', ' + time;
	}

	function init( box ) {
		var log = box.querySelector( '[data-studio-log]' );
		var form = box.querySelector( '[data-studio-form]' );
		var input = box.querySelector( '[data-studio-input]' );
		var status = box.querySelector( '[data-studio-status]' );
		var applyBox = box.querySelector( '[data-studio-apply]' );
		var resetBtn = box.querySelector( '[data-studio-reset]' );
		var memoryEl = box.querySelector( '[data-studio-memory]' );
		var focus = box.getAttribute( 'data-focus' ) || 0;
		var busy = false;

		function add( role, text, time ) {
			var wrap = document.createElement( 'div' );
			wrap.className = 'palladio-studio__msg palladio-studio__msg--' + role;

			var meta = document.createElement( 'div' );
			meta.className = 'palladio-studio__msg-meta';

			var who = document.createElement( 'span' );
			who.className = 'palladio-studio__msg-who';
			who.textContent = 'user' === role ? ( i18n.you || 'Tu' ) : ( i18n.agent || 'Agente' );

			var when = document.createElement( 'time' );
			when.className = 'palladio-studio__msg-time';
			when.textContent = formatTime( time );

			meta.appendChild( who );
			meta.appendChild( when );

			var body = document.createElement( 'div' );
			body.className = 'palladio-studio__msg-body';
			body.textContent = text;

			wrap.appendChild( meta );
			wrap.appendChild( body );
			log.appendChild( wrap );
			log.scrollTop = log.scrollHeight;
			return wrap;
		}

		function setTyping( on ) {
			var el = log.querySelector( '.palladio-studio__typing' );
			if ( on && ! el ) {
				el = document.createElement( 'div' );
				el.className = 'palladio-studio__typing';
				el.innerHTML = '<span></span><span></span><span></span>';
				log.appendChild( el );
				log.scrollTop = log.scrollHeight;
			} else if ( ! on && el ) {
				el.parentNode.removeChild( el );
			}
		}

		function setMemory( hasMemory ) {
			if ( memoryEl ) {
				memoryEl.textContent = hasMemory ? ( i18n.memoryOn || 'Memoria: attiva' ) : ( i18n.memoryOff || 'Memoria: vuota' );
				memoryEl.classList.toggle( 'is-on', !! hasMemory );
			}
		}

		// Storico persistente ricevuto dal server.
		( cfg.history || [] ).forEach( function ( m ) {
			if ( m && m.role && m.content ) {
				add( m.role, String( m.content ), m.time ? parseInt( m.time, 10 ) : 0 );
			}
		} );
		setMemory( !! cfg.hasMemory );

		// Esegue una singola richiesta AJAX. Restituisce una Promise con
		// { status, data, raw } dove data è il JSON di WordPress (o null).
		function post( action, params ) {
			var body = new URLSearchParams();
			body.append( 'action', action );
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
			setTyping( false );
			status.textContent = ( i18n.error || 'Error' ) + ': ' + m;
		}

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var text = ( input.value || '' ).trim();
			if ( '' === text || busy ) {
				return;
			}
			busy = true;
			add( 'user', text, 0 );
			input.value = '';
			status.textContent = i18n.working || 'Working…';
			setTyping( true );

			// Ogni richiesta esegue un passo dell'agente (una chiamata al
			// modello OPPURE un tool). Finché il server risponde done:false,
			// reinviamo con il "turn" ricevuto per far proseguire il loop,
			// senza mai tenere aperta una singola richiesta lunga (che veniva
			// troncata). Il cap server-side resta 10 round di modello.
			var MAX_STEPS = 60;

			function finish( data ) {
				busy = false;
				setTyping( false );
				status.textContent = '';
				add( 'assistant', String( data.reply || '' ), data.time ? parseInt( data.time, 10 ) : 0 );
				if ( 'undefined' !== typeof data.hasMemory ) {
					setMemory( !! data.hasMemory );
				}
			}

			function step( turn, count ) {
				if ( count > MAX_STEPS ) {
					busy = false;
					setTyping( false );
					status.textContent = ( i18n.error || 'Error' ) + ': ' + ( i18n.tooManySteps || 'troppi passi, riprova con una richiesta più semplice' );
					return;
				}

				var params = turn
					? { turn: turn }
					: {
						message: text,
						focus: focus,
						apply: ( applyBox && applyBox.checked ) ? '1' : '',
					};

				post( 'palladio_studio_chat', params ).then( function ( res ) {
					var d = res.data;

					if ( ! d || ! d.success || ! d.data ) {
						fail( res );
						return;
					}

					if ( d.data.done ) {
						finish( d.data );
						return;
					}

					// Passo intermedio: mostra lo stato e prosegui.
					status.textContent = d.data.status || ( i18n.working || 'Working…' );
					step( d.data.turn, count + 1 );
				} ).catch( function ( err ) {
					busy = false;
					setTyping( false );
					status.textContent = ( i18n.error || 'Error' ) + ': ' + ( err && err.message ? err.message : 'rete' );
				} );
			}

			step( '', 1 );
		} );

		// Invio con Enter (Shift+Enter = nuova riga).
		input.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key && ! e.shiftKey ) {
				e.preventDefault();
				if ( 'function' === typeof form.requestSubmit ) {
					form.requestSubmit();
				} else {
					form.dispatchEvent( new Event( 'submit', { cancelable: true } ) );
				}
			}
		} );

		// Nuova conversazione: svuota lo storico (e, a scelta, la memoria).
		if ( resetBtn ) {
			resetBtn.addEventListener( 'click', function () {
				if ( busy ) {
					return;
				}
				if ( ! window.confirm( i18n.confirmReset || 'Nuova conversazione?' ) ) {
					return;
				}
				var wipe = memoryEl && memoryEl.classList.contains( 'is-on' )
					? window.confirm( i18n.confirmWipe || 'Cancellare anche la memoria di progetto?' )
					: false;

				post( 'palladio_studio_reset', wipe ? { wipe_memory: '1' } : {} ).then( function ( res ) {
					var d = res.data;
					if ( d && d.success ) {
						log.innerHTML = '';
						status.textContent = '';
						setMemory( !! ( d.data && d.data.hasMemory ) );
					} else {
						fail( res );
					}
				} ).catch( function () {} );
			} );
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var boxes = document.querySelectorAll( '.palladio-studio__box' );
		Array.prototype.forEach.call( boxes, init );
	} );
}() );
