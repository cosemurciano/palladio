/**
 * Palladio — widget agent (frontend).
 *
 * Chat embeddabile che parla solo con l'endpoint REST del plugin. Dichiara
 * la natura AI dell'assistente. Nessuna chiave o chiamata AI lato browser.
 */
( function () {
	'use strict';

	var cfg = window.PalladioAgent || {};
	var i18n = cfg.i18n || {};
	var sessionId = '';
	var sending = false;

	function el( tag, cls, text ) {
		var node = document.createElement( tag );
		if ( cls ) {
			node.className = cls;
		}
		if ( text ) {
			node.textContent = text;
		}
		return node;
	}

	function build( root ) {
		var toggle = el( 'button', 'palladio-agent__toggle' );
		toggle.type = 'button';
		toggle.setAttribute( 'aria-expanded', 'false' );
		toggle.setAttribute( 'aria-label', i18n.open || 'Chat' );
		toggle.textContent = '💬';

		var panel = el( 'div', 'palladio-agent__panel' );
		panel.hidden = true;

		var header = el( 'div', 'palladio-agent__header' );
		header.appendChild( el( 'span', 'palladio-agent__title', i18n.title || 'Assistant' ) );
		var closeBtn = el( 'button', 'palladio-agent__close', '×' );
		closeBtn.type = 'button';
		closeBtn.setAttribute( 'aria-label', i18n.close || 'Close' );
		header.appendChild( closeBtn );

		var disclaimer = el( 'p', 'palladio-agent__disclaimer', cfg.disclaimer || '' );

		var log = el( 'div', 'palladio-agent__log' );
		log.setAttribute( 'aria-live', 'polite' );

		var form = el( 'form', 'palladio-agent__form' );
		var input = el( 'input', 'palladio-agent__input' );
		input.type = 'text';
		input.placeholder = i18n.placeholder || '';
		input.setAttribute( 'aria-label', i18n.placeholder || 'Message' );
		var send = el( 'button', 'palladio-agent__send', i18n.send || 'Send' );
		send.type = 'submit';
		form.appendChild( input );
		form.appendChild( send );

		panel.appendChild( header );
		panel.appendChild( disclaimer );
		panel.appendChild( log );
		panel.appendChild( form );

		root.appendChild( toggle );
		root.appendChild( panel );

		function openPanel( open ) {
			panel.hidden = ! open;
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			if ( open ) {
				if ( ! log.childNodes.length ) {
					addMessage( 'assistant', i18n.greeting || '' );
				}
				input.focus();
			}
		}

		function addMessage( role, text ) {
			var msg = el( 'div', 'palladio-agent__msg palladio-agent__msg--' + role );
			msg.textContent = text;
			log.appendChild( msg );
			log.scrollTop = log.scrollHeight;
			return msg;
		}

		toggle.addEventListener( 'click', function () {
			openPanel( panel.hidden );
		} );
		closeBtn.addEventListener( 'click', function () {
			openPanel( false );
		} );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			var text = input.value.trim();
			if ( '' === text || sending ) {
				return;
			}
			addMessage( 'user', text );
			input.value = '';
			sending = true;

			var typing = addMessage( 'assistant', '…' );

			var body = new URLSearchParams();
			body.append( 'message', text );
			body.append( 'session_id', sessionId );
			body.append( 'nonce', cfg.nonce || '' );

			fetch( cfg.endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} ).then( function ( r ) {
				return r.json().then( function ( data ) {
					return { ok: r.ok, data: data };
				} );
			} ).then( function ( res ) {
				sending = false;
				if ( res.ok && res.data && res.data.reply ) {
					sessionId = res.data.session_id || sessionId;
					typing.textContent = res.data.reply;
				} else {
					typing.textContent = ( res.data && res.data.error ) ? res.data.error : ( i18n.error || 'Error' );
				}
				log.scrollTop = log.scrollHeight;
			} ).catch( function () {
				sending = false;
				typing.textContent = i18n.error || 'Error';
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var root = document.getElementById( 'palladio-agent-root' );
		if ( root && cfg.endpoint ) {
			build( root );
		}
	} );
}() );
