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
			body.append( 'history', JSON.stringify( history ) );

			fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} ).then( function ( r ) {
				return r.json();
			} ).then( function ( res ) {
				busy = false;
				status.textContent = '';
				if ( res && res.success && res.data && res.data.reply ) {
					add( 'assistant', res.data.reply );
					history.push( { role: 'user', content: text } );
					history.push( { role: 'assistant', content: res.data.reply } );
				} else {
					var m = ( res && res.data && res.data.message ) ? res.data.message : ( i18n.error || 'Error' );
					status.textContent = ( i18n.error || 'Error' ) + ': ' + m;
				}
			} ).catch( function () {
				busy = false;
				status.textContent = i18n.error || 'Error';
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var boxes = document.querySelectorAll( '.palladio-studio__box' );
		Array.prototype.forEach.call( boxes, init );
	} );
}() );
