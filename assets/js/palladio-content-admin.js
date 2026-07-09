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
			return;
		}
		var tpl = rep.querySelector( '.pll-rep__tpl' );
		var rows = rep.querySelector( '.pll-rep__rows' );
		if ( ! tpl || ! rows ) {
			return;
		}
		var index = Date.now().toString( 36 ) + Math.floor( Math.random() * 1000 );
		var html = tpl.textContent.replace( /__i__/g, index );
		var wrap = document.createElement( 'div' );
		wrap.innerHTML = html.trim();
		var node = wrap.firstElementChild;
		rows.appendChild( node );
	}

	document.addEventListener( 'click', function ( e ) {
		var add = e.target.closest( '.pll-rep__add' );
		if ( add ) {
			e.preventDefault();
			addRow( add.getAttribute( 'data-add' ) );
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
