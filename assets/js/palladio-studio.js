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

			var body = new URLSearchParams();
			body.append( 'action', 'palladio_studio_chat' );
			body.append( 'nonce', cfg.nonce || '' );
			body.append( 'message', text );
			body.append( 'focus', focus );
			body.append( 'apply', ( applyBox && applyBox.checked ) ? '1' : '' );
			body.append( 'history', JSON.stringify( history ) );

			fetch( cfg.ajaxUrl, {
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
			} ).then( function ( res ) {
				busy = false;
				status.textContent = '';
				var d = res.data;

				if ( d && d.success && d.data && d.data.reply ) {
					add( 'assistant', d.data.reply );
					history.push( { role: 'user', content: text } );
					history.push( { role: 'assistant', content: d.data.reply } );
					return;
				}

				var m;
				if ( d && d.data && d.data.message ) {
					m = d.data.message;
				} else if ( ! d ) {
					var snippet = ( res.raw || '' ).replace( /<[^>]*>/g, ' ' ).replace( /\s+/g, ' ' ).trim().slice( 0, 200 );
					m = 'HTTP ' + res.status + ( snippet ? ' — ' + snippet : '' ) + ' (probabile timeout PHP: riprova o alza max_execution_time)';
				} else {
					m = 'risposta senza dettagli dal server';
				}
				status.textContent = ( i18n.error || 'Error' ) + ': ' + m;
			} ).catch( function ( err ) {
				busy = false;
				status.textContent = ( i18n.error || 'Error' ) + ': ' + ( err && err.message ? err.message : 'rete' );
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var boxes = document.querySelectorAll( '.palladio-studio__box' );
		Array.prototype.forEach.call( boxes, init );
	} );
}() );
