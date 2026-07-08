/**
 * Palladio — filtro client-side delle unità (progressive enhancement).
 *
 * Senza JS la griglia mostra tutte le unità. Con JS i chip filtrano per
 * lo stato (attributo data-stato sulle card).
 */
( function () {
	'use strict';

	function initGroup( group ) {
		var grid = group.parentNode.querySelector( '[data-palladio-grid]' );
		if ( ! grid ) {
			return;
		}

		var chips = group.querySelectorAll( '.palladio-filters__chip' );
		var cards = grid.querySelectorAll( '.palladio-unit-card' );

		group.addEventListener( 'click', function ( event ) {
			var chip = event.target.closest( '.palladio-filters__chip' );
			if ( ! chip ) {
				return;
			}

			var filter = chip.getAttribute( 'data-filter' ) || 'stato';
			var value = chip.getAttribute( 'data-value' );

			Array.prototype.forEach.call( chips, function ( c ) {
				var active = c === chip;
				c.classList.toggle( 'is-active', active );
				c.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
			} );

			Array.prototype.forEach.call( cards, function ( card ) {
				var match = '*' === value || card.getAttribute( 'data-' + filter ) === value;
				card.hidden = ! match;
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var groups = document.querySelectorAll( '[data-palladio-filters]' );
		Array.prototype.forEach.call( groups, initGroup );
	} );
}() );
