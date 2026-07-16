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

	// -------------------------------------------------------------------------
	// Landing edificio: filtri/ordinamento della sezione "Scegli le tue stanze".
	// -------------------------------------------------------------------------
	function initUnitFilters( group ) {
		var grid = group.closest( 'section' ) ? group.closest( 'section' ).querySelector( '[data-palladio-units]' ) : null;
		if ( ! grid ) {
			return;
		}

		var chips = group.querySelectorAll( '.pll-e-chip' );
		var cards = Array.prototype.slice.call( grid.querySelectorAll( '.pll-e-unit-card' ) );
		var original = cards.slice();
		var priceDesc = true;

		function setActive( chip ) {
			Array.prototype.forEach.call( chips, function ( c ) {
				c.classList.toggle( 'is-active', c === chip );
			} );
		}

		function reorder( list ) {
			list.forEach( function ( card ) {
				grid.appendChild( card );
			} );
		}

		group.addEventListener( 'click', function ( event ) {
			var chip = event.target.closest( '.pll-e-chip' );
			if ( ! chip ) {
				return;
			}
			var filter = chip.getAttribute( 'data-filter' );
			setActive( chip );

			// Reset visibilità.
			cards.forEach( function ( c ) { c.hidden = false; } );

			if ( 'all' === filter ) {
				reorder( original );
			} else if ( 'esterno' === filter ) {
				cards.forEach( function ( c ) {
					c.hidden = '1' !== c.getAttribute( 'data-esterno' );
				} );
			} else if ( 'prezzo' === filter ) {
				var byPrice = original.slice().sort( function ( a, b ) {
					var pa = parseFloat( a.getAttribute( 'data-prezzo' ) ) || 0;
					var pb = parseFloat( b.getAttribute( 'data-prezzo' ) ) || 0;
					return priceDesc ? pb - pa : pa - pb;
				} );
				priceDesc = ! priceDesc;
				reorder( byPrice );
			} else if ( 'piano' === filter ) {
				var byFloor = original.slice().sort( function ( a, b ) {
					return ( a.getAttribute( 'data-piano' ) || '' ).localeCompare( b.getAttribute( 'data-piano' ) || '' );
				} );
				reorder( byFloor );
			}
		} );
	}

	// -------------------------------------------------------------------------
	// Reveal allo scroll (manifesto, timeline).
	// -------------------------------------------------------------------------
	function initReveal() {
		var items = document.querySelectorAll( '.pll-reveal' );
		if ( ! items.length ) {
			return;
		}
		document.body.classList.add( 'js-reveal' );

		if ( ! ( 'IntersectionObserver' in window ) ) {
			Array.prototype.forEach.call( items, function ( el ) { el.classList.add( 'is-in' ); } );
			return;
		}

		var obs = new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				if ( entry.isIntersecting ) {
					entry.target.classList.add( 'is-in' );
					obs.unobserve( entry.target );
				}
			} );
		}, { threshold: 0.15 } );

		Array.prototype.forEach.call( items, function ( el ) { obs.observe( el ); } );
	}

	// -------------------------------------------------------------------------
	// Timeline / scroll-telling: media sticky con crossfade, i capitoli
	// avanzano con lo scroll; gli anni sono cliccabili. Senza JS (o senza
	// IntersectionObserver) la sezione resta figura + testo impilati.
	// -------------------------------------------------------------------------
	function initScrolly( section ) {
		var frames = section.querySelectorAll( '[data-scrolly-frame]' );
		var chapters = section.querySelectorAll( '[data-scrolly-chapter]' );
		var links = section.querySelectorAll( '[data-scrolly-goto]' );

		if ( ! chapters.length || ! ( 'IntersectionObserver' in window ) ) {
			return;
		}
		section.classList.add( 'is-scrolly' );

		function activate( index ) {
			Array.prototype.forEach.call( frames, function ( f ) {
				f.classList.toggle( 'is-active', parseInt( f.getAttribute( 'data-scrolly-frame' ), 10 ) === index );
			} );
			Array.prototype.forEach.call( links, function ( a ) {
				var on = parseInt( a.getAttribute( 'data-scrolly-goto' ), 10 ) === index;
				a.classList.toggle( 'is-active', on );
				if ( on ) {
					a.setAttribute( 'aria-current', 'true' );
				} else {
					a.removeAttribute( 'aria-current' );
				}
			} );
		}

		// Scroll automatico: il capitolo al centro del viewport è quello attivo.
		var observer = new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				if ( entry.isIntersecting ) {
					activate( parseInt( entry.target.getAttribute( 'data-scrolly-chapter' ), 10 ) );
				}
			} );
		}, { rootMargin: '-45% 0px -45% 0px', threshold: 0 } );

		Array.prototype.forEach.call( chapters, function ( ch ) {
			observer.observe( ch );
		} );

		// Click sugli anni: scorrimento morbido al capitolo.
		var smooth = ! ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches );
		section.addEventListener( 'click', function ( event ) {
			var link = event.target.closest( '[data-scrolly-goto]' );
			if ( ! link ) {
				return;
			}
			var target = section.querySelector( '[data-scrolly-chapter="' + link.getAttribute( 'data-scrolly-goto' ) + '"]' );
			if ( target ) {
				event.preventDefault();
				target.scrollIntoView( { behavior: smooth ? 'smooth' : 'auto', block: 'start' } );
			}
		} );
	}

	// -------------------------------------------------------------------------
	// Lightbox galleria: zoom modale con frecce (pulsanti + tastiera ←/→/Esc)
	// e swipe su touch. Senza JS i link aprono l'immagine grande direttamente.
	// -------------------------------------------------------------------------
	function initLightbox( group ) {
		var items = Array.prototype.slice.call( group.querySelectorAll( '[data-pll-lightbox]' ) );
		if ( ! items.length ) {
			return;
		}

		var overlay = null;
		var current = -1;
		var lastFocus = null;

		function build() {
			overlay = document.createElement( 'div' );
			overlay.className = 'pll-lightbox';
			overlay.setAttribute( 'role', 'dialog' );
			overlay.setAttribute( 'aria-modal', 'true' );
			overlay.innerHTML =
				'<button type="button" class="pll-lightbox__close" aria-label="Chiudi">&times;</button>' +
				'<button type="button" class="pll-lightbox__arrow pll-lightbox__arrow--prev" aria-label="Precedente">&#8592;</button>' +
				'<figure class="pll-lightbox__stage"><img alt=""><figcaption></figcaption></figure>' +
				'<button type="button" class="pll-lightbox__arrow pll-lightbox__arrow--next" aria-label="Successiva">&#8594;</button>' +
				'<span class="pll-lightbox__counter"></span>';
			document.body.appendChild( overlay );

			overlay.addEventListener( 'click', function ( e ) {
				if ( e.target.closest( '.pll-lightbox__close' ) || e.target === overlay ) {
					close();
				} else if ( e.target.closest( '.pll-lightbox__arrow--prev' ) ) {
					show( current - 1 );
				} else if ( e.target.closest( '.pll-lightbox__arrow--next' ) ) {
					show( current + 1 );
				}
			} );

			// Swipe su touch: soglia 40px in orizzontale.
			var touchX = null;
			overlay.addEventListener( 'touchstart', function ( e ) {
				touchX = e.changedTouches[0].clientX;
			}, { passive: true } );
			overlay.addEventListener( 'touchend', function ( e ) {
				if ( null === touchX ) {
					return;
				}
				var delta = e.changedTouches[0].clientX - touchX;
				touchX = null;
				if ( Math.abs( delta ) > 40 ) {
					show( current + ( delta < 0 ? 1 : -1 ) );
				}
			}, { passive: true } );
		}

		function onKey( e ) {
			if ( 'Escape' === e.key ) {
				close();
			} else if ( 'ArrowLeft' === e.key ) {
				show( current - 1 );
			} else if ( 'ArrowRight' === e.key ) {
				show( current + 1 );
			}
		}

		function show( index ) {
			current = ( index + items.length ) % items.length;
			var item = items[ current ];
			overlay.querySelector( 'img' ).src = item.getAttribute( 'data-pll-lightbox' );
			overlay.querySelector( 'figcaption' ).textContent = item.getAttribute( 'data-pll-caption' ) || '';
			overlay.querySelector( '.pll-lightbox__counter' ).textContent = ( current + 1 ) + ' / ' + items.length;
			var single = items.length < 2;
			overlay.querySelector( '.pll-lightbox__arrow--prev' ).hidden = single;
			overlay.querySelector( '.pll-lightbox__arrow--next' ).hidden = single;
		}

		function open( index ) {
			if ( ! overlay ) {
				build();
			}
			lastFocus = document.activeElement;
			overlay.classList.add( 'is-open' );
			document.body.classList.add( 'pll-lightbox-open' );
			document.addEventListener( 'keydown', onKey );
			show( index );
			overlay.querySelector( '.pll-lightbox__close' ).focus();
		}

		function close() {
			overlay.classList.remove( 'is-open' );
			document.body.classList.remove( 'pll-lightbox-open' );
			document.removeEventListener( 'keydown', onKey );
			if ( lastFocus && lastFocus.focus ) {
				lastFocus.focus();
			}
		}

		group.addEventListener( 'click', function ( e ) {
			var link = e.target.closest( '[data-pll-lightbox]' );
			if ( ! link ) {
				return;
			}
			e.preventDefault();
			open( items.indexOf( link ) );
		} );
	}

	// -------------------------------------------------------------------------
	// Ambient loop: più immagini in dissolvenza automatica (6s), navigabili
	// con le frecce o con lo swipe. Con una sola immagine resta statico.
	// -------------------------------------------------------------------------
	function initAmbient( section ) {
		var frames = section.querySelectorAll( '[data-ambient-frame]' );
		if ( frames.length < 2 ) {
			return;
		}
		var captions = section.querySelectorAll( '[data-ambient-caption]' );
		var current = 0;
		var timer = null;
		var reduced = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

		function show( index ) {
			current = ( index + frames.length ) % frames.length;
			Array.prototype.forEach.call( frames, function ( f ) {
				f.classList.toggle( 'is-active', parseInt( f.getAttribute( 'data-ambient-frame' ), 10 ) === current );
			} );
			Array.prototype.forEach.call( captions, function ( c ) {
				c.classList.toggle( 'is-active', parseInt( c.getAttribute( 'data-ambient-caption' ), 10 ) === current );
			} );
		}

		function restart() {
			if ( timer ) {
				clearInterval( timer );
			}
			if ( ! reduced ) {
				timer = setInterval( function () { show( current + 1 ); }, 6000 );
			}
		}

		section.addEventListener( 'click', function ( e ) {
			if ( e.target.closest( '[data-ambient-prev]' ) ) {
				show( current - 1 );
				restart();
			} else if ( e.target.closest( '[data-ambient-next]' ) ) {
				show( current + 1 );
				restart();
			}
		} );

		// Swipe orizzontale su touch.
		var touchX = null;
		section.addEventListener( 'touchstart', function ( e ) {
			touchX = e.changedTouches[0].clientX;
		}, { passive: true } );
		section.addEventListener( 'touchend', function ( e ) {
			if ( null === touchX ) {
				return;
			}
			var delta = e.changedTouches[0].clientX - touchX;
			touchX = null;
			if ( Math.abs( delta ) > 40 ) {
				show( current + ( delta < 0 ? 1 : -1 ) );
				restart();
			}
		}, { passive: true } );

		restart();
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var groups = document.querySelectorAll( '[data-palladio-filters]' );
		Array.prototype.forEach.call( groups, initGroup );

		var unitFilters = document.querySelectorAll( '[data-palladio-unit-filters]' );
		Array.prototype.forEach.call( unitFilters, initUnitFilters );

		var scrolly = document.querySelectorAll( '[data-palladio-scrolly]' );
		Array.prototype.forEach.call( scrolly, initScrolly );

		var lightboxes = document.querySelectorAll( '[data-pll-lightbox-group]' );
		Array.prototype.forEach.call( lightboxes, initLightbox );

		var ambients = document.querySelectorAll( '[data-pll-ambient]' );
		Array.prototype.forEach.call( ambients, initAmbient );

		initReveal();
	} );
}() );
