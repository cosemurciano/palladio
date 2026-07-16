/**
 * Palladio — editor contenuti strutturati (admin).
 *
 * Gestisce i repeater (aggiungi/rimuovi riga) e il media picker WordPress.
 */
( function () {
	'use strict';

	var cfg = window.PalladioContent || {};

	// ------------------------------------------------------------------ repeater
	function addRow( section ) {
		var rep = document.querySelector( '.pll-rep[data-section="' + section + '"]' );
		if ( ! rep ) {
			return null;
		}
		var tpl = rep.querySelector( '.pll-rep__tpl' );
		var rows = rep.querySelector( '.pll-rep__rows' );
		if ( ! tpl || ! rows ) {
			return null;
		}
		var index = Date.now().toString( 36 ) + Math.floor( Math.random() * 100000 );
		var html = tpl.textContent.replace( /__i__/g, index );
		var wrap = document.createElement( 'div' );
		wrap.innerHTML = html.trim();
		var node = wrap.firstElementChild;
		rows.appendChild( node );
		return node;
	}

	// Selezione multipla: una riga per ogni immagine scelta nel media picker.
	function addMulti( section ) {
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}
		var frame = window.wp.media( {
			title: cfg.choose || 'Images',
			button: { text: cfg.use || 'Use' },
			library: { type: 'image' },
			multiple: 'add',
		} );

		frame.on( 'select', function () {
			frame.state().get( 'selection' ).each( function ( model ) {
				var att = model.toJSON();
				var row = addRow( section );
				if ( ! row ) {
					return;
				}
				var box = row.querySelector( '[data-pll-media]' );
				if ( box ) {
					box.querySelector( '.pll-media__id' ).value = att.id;
					var url = ( att.sizes && att.sizes.thumbnail ) ? att.sizes.thumbnail.url : att.url;
					box.querySelector( '.pll-media__preview' ).innerHTML = '<img src="' + url + '" alt="">';
				}
				// Didascalia precompilata da quella del media, se presente.
				var caption = row.querySelector( 'input[name*="[caption]"]' );
				if ( caption && ! caption.value && att.caption ) {
					caption.value = att.caption;
				}
			} );
		} );

		frame.open();
	}

	// ---------------------------------------------------------------- riordino
	// Le righe si riordinano trascinando la maniglia, con i pulsanti ↑/↓ o con
	// i tasti freccia quando la maniglia ha il focus. L'ordine salvato segue
	// l'ordine del DOM (PHP conserva l'ordine di invio del form).
	function moveRow( row, dir ) {
		var sibling = ( dir < 0 ) ? row.previousElementSibling : row.nextElementSibling;
		if ( ! sibling ) {
			return;
		}
		if ( dir < 0 ) {
			row.parentNode.insertBefore( row, sibling );
		} else {
			row.parentNode.insertBefore( sibling, row );
		}
	}

	var dragged = null;

	document.addEventListener( 'dragstart', function ( e ) {
		var row = e.target.closest ? e.target.closest( '.pll-rep__row' ) : null;
		if ( ! row ) {
			return;
		}
		dragged = row;
		row.classList.add( 'is-dragging' );
		if ( e.dataTransfer ) {
			e.dataTransfer.effectAllowed = 'move';
			try { e.dataTransfer.setData( 'text/plain', '' ); } catch ( err ) { /* IE */ }
		}
	} );

	document.addEventListener( 'dragover', function ( e ) {
		if ( ! dragged ) {
			return;
		}
		var over = e.target.closest ? e.target.closest( '.pll-rep__row' ) : null;
		if ( ! over || over === dragged || over.parentNode !== dragged.parentNode ) {
			return;
		}
		e.preventDefault();
		var rect = over.getBoundingClientRect();
		var after = ( e.clientY - rect.top ) > rect.height / 2;
		over.parentNode.insertBefore( dragged, after ? over.nextSibling : over );
	} );

	document.addEventListener( 'dragend', function () {
		if ( dragged ) {
			dragged.classList.remove( 'is-dragging' );
			dragged = null;
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( ! e.target.classList || ! e.target.classList.contains( 'pll-rep__handle' ) ) {
			return;
		}
		if ( 'ArrowUp' === e.key || 'ArrowDown' === e.key ) {
			e.preventDefault();
			var row = e.target.closest( '.pll-rep__row' );
			if ( row ) {
				moveRow( row, 'ArrowUp' === e.key ? -1 : 1 );
				e.target.focus();
			}
		}
	} );

	document.addEventListener( 'click', function ( e ) {
		var multi = e.target.closest( '.pll-rep__add-multi' );
		if ( multi ) {
			e.preventDefault();
			addMulti( multi.getAttribute( 'data-add' ) );
			return;
		}

		var add = e.target.closest( '.pll-rep__add' );
		if ( add ) {
			e.preventDefault();
			addRow( add.getAttribute( 'data-add' ) );
			return;
		}

		var up = e.target.closest( '.pll-rep__move--up' );
		if ( up ) {
			e.preventDefault();
			moveRow( up.closest( '.pll-rep__row' ), -1 );
			return;
		}

		var down = e.target.closest( '.pll-rep__move--down' );
		if ( down ) {
			e.preventDefault();
			moveRow( down.closest( '.pll-rep__row' ), 1 );
			return;
		}

		var rem = e.target.closest( '.pll-rep__remove' );
		if ( rem ) {
			e.preventDefault();
			var row = rem.closest( '.pll-rep__row' );
			if ( row ) {
				row.parentNode.removeChild( row );
			}
			return;
		}

		var choose = e.target.closest( '.pll-media__choose' );
		if ( choose ) {
			e.preventDefault();
			openMedia( choose.closest( '[data-pll-media]' ) );
			return;
		}

		var clear = e.target.closest( '.pll-media__clear' );
		if ( clear ) {
			e.preventDefault();
			var box = clear.closest( '[data-pll-media]' );
			box.querySelector( '.pll-media__id' ).value = '';
			box.querySelector( '.pll-media__preview' ).innerHTML = '';
		}
	} );

	// --------------------------------------------------------------- media picker
	var frame = null;

	function openMedia( box ) {
		if ( ! box || ! window.wp || ! window.wp.media ) {
			return;
		}
		frame = window.wp.media( {
			title: cfg.choose || 'Image',
			button: { text: cfg.use || 'Use' },
			library: { type: 'image' },
			multiple: false,
		} );

		frame.on( 'select', function () {
			var att = frame.state().get( 'selection' ).first().toJSON();
			box.querySelector( '.pll-media__id' ).value = att.id;
			var url = ( att.sizes && att.sizes.thumbnail ) ? att.sizes.thumbnail.url : att.url;
			box.querySelector( '.pll-media__preview' ).innerHTML = '<img src="' + url + '" alt="">';
		} );

		frame.open();
	}
}() );
