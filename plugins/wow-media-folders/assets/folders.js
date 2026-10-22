/**
 * The folder panel on the media library screen.
 *
 * Three jobs: put the panel beside the library, filter the library from it,
 * and move files into folders by dragging them.
 *
 * Nothing here patches a core prototype. Filtering rides on the collection's
 * props, which is the same road core's own type and date filters take, and
 * dragging leans on the `li.attachment[data-id]` markup that has been stable
 * since the media library was rewritten.
 */

( function ( $ ) {
	'use strict';

	var settings = window.wowMediaFolders || {};
	var strings = settings.strings || {};

	/** Files picked up by the pointer, for the length of one drag. */
	var dragging = [];

	/** Whether the drag in flight is ours, as opposed to files from disk. */
	var ourDrag = false;

	$( function () {
		var $panel = $( '#wow-folders' );

		if ( ! $panel.length ) {
			return;
		}

		layout( $panel );
		searchFolders( $panel );
		manageFolders( $panel );

		if ( 'grid' === settings.mode ) {
			filterGrid( $panel );
		}

		dragFiles( $panel );
	} );

	/**
	 * Lifts the panel out of the notice area and stands it beside the library.
	 *
	 * The panel is printed on all_admin_notices because upload.php offers no
	 * hook inside its body. Left there it would sit above the library, which
	 * works but reads badly.
	 */
	function layout( $panel ) {
		var $wrap = $( '#wpbody-content > .wrap' ).first();

		if ( ! $wrap.length ) {
			return;
		}

		$( '<div class="wow-folders-layout"></div>' )
			.insertBefore( $wrap )
			.append( $panel )
			.append( $wrap );
	}

	/**
	 * Narrows the list as you type. Purely local — no request, no reload.
	 *
	 * "All files" and "No folder" are hidden while a search is running: they
	 * are not folders, and leaving them behind made the list look as if
	 * nothing had been filtered at all.
	 */
	function searchFolders( $panel ) {
		var $items = $panel.find( '.wow-folders__item[data-term]' );
		var $fixed = $panel.find( '.wow-folders__item:not([data-term])' );
		var $empty = $panel.find( '.wow-folders__empty' );

		// `search` as well as `input`: in WebKit the clear button fires it.
		$panel.on( 'input search', '.wow-folders__search', function () {
			var needle = fold( this.value );
			var shown = 0;

			$items.each( function () {
				var $item = $( this );
				var name = fold( $item.find( '.wow-folders__name' ).text() );
				var slug = fold( String( $item.data( 'folder' ) || '' ) );
				var hit = '' === needle || -1 !== name.indexOf( needle ) || -1 !== slug.indexOf( needle );

				$item.toggle( hit );

				if ( hit ) {
					shown++;
				}
			} );

			$fixed.toggle( '' === needle );
			$empty.prop( 'hidden', 0 !== shown || '' === needle );
		} );
	}

	/**
	 * A string as it should be compared: lowercase, without accents.
	 *
	 * Album titles here are French — "création de jardins" — and nobody types
	 * the accents when looking for a folder. Without this, searching for
	 * "creation" found nothing, which read as a broken filter.
	 *
	 * NFD splits a letter into its base and its accent, and the range covers
	 * the combining marks; \p{Diacritic} would be neater but needs a newer
	 * engine than the admin's browser floor.
	 */
	function fold( value ) {
		var text = $.trim( String( value ) ).toLowerCase();

		return text.normalize ? text.normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' ) : text;
	}

	/**
	 * Filters the grid without a page load.
	 *
	 * The slug goes into the collection's props under the taxonomy's own
	 * query_var, which core threads through to WP_Query by itself. "No folder"
	 * cannot travel that way and gets its own parameter, which the plugin
	 * turns into a tax_query on the server.
	 */
	function filterGrid( $panel ) {
		$panel.on( 'click', '.wow-folders__link', function ( event ) {
			var library = grid();

			if ( ! library ) {
				return; // Let the link navigate; the filter still applies.
			}

			event.preventDefault();

			var $item = $( this ).closest( '.wow-folders__item' );
			var folder = String( $item.data( 'folder' ) || '' );

			library.props.set( settings.taxonomy, 'none' === folder || '' === folder ? null : folder );
			library.props.set( settings.noneParam, 'none' === folder ? 1 : null );

			markCurrent( $panel, $item );

			if ( window.history && window.history.replaceState ) {
				window.history.replaceState( {}, '', this.href );
			}
		} );
	}

	/**
	 * The collection behind the grid, when there is one.
	 */
	function grid() {
		var frame = window.wp && window.wp.media && window.wp.media.frame;

		if ( ! frame || ! frame.state ) {
			return null;
		}

		var state = frame.state();

		return state && state.get ? state.get( 'library' ) : null;
	}

	function markCurrent( $panel, $item ) {
		$panel.find( '.wow-folders__item' ).removeClass( 'is-current' );
		$item.addClass( 'is-current' );
	}

	/**
	 * New, rename and delete.
	 */
	function manageFolders( $panel ) {
		if ( ! settings.canEdit ) {
			return;
		}

		$panel.on( 'click', '.wow-folders__new', function () {
			var name = window.prompt( strings.newPrompt || '' );

			if ( ! name ) {
				return;
			}

			post( { task: 'create', name: name } ).done( function () {
				window.location.reload();
			} );
		} );

		$panel.on( 'click', '.wow-folders__rename', function () {
			var $item = $( this ).closest( '.wow-folders__item' );
			var $name = $item.find( '.wow-folders__name' );
			var name = window.prompt( strings.renamePrompt || '', $name.text() );

			if ( ! name || name === $name.text() ) {
				return;
			}

			post( { task: 'rename', term: $item.data( 'term' ), name: name } ).done( function ( response ) {
				$name.text( response.data.name );
			} );
		} );

		$panel.on( 'click', '.wow-folders__delete', function () {
			var $item = $( this ).closest( '.wow-folders__item' );
			var name = $item.find( '.wow-folders__name' ).text();
			var ask = ( strings.deleteAsk || '%s' ).replace( '%s', name );

			if ( ! window.confirm( ask ) ) {
				return;
			}

			post( { task: 'delete', term: $item.data( 'term' ) } ).done( function ( response ) {
				$item.remove();
				applyCounts( $panel, response.data.counts );
			} );
		} );
	}

	/**
	 * Dragging files onto a folder.
	 *
	 * `draggable` is set on the tile the moment a pointer goes down on it,
	 * rather than on every tile up front: the grid rebuilds its tiles
	 * constantly, and this way nothing has to be re-applied afterwards.
	 */
	function dragFiles( $panel ) {
		var $body = $( document.body );

		$body.on( 'pointerdown', 'li.attachment[data-id]', function () {
			this.setAttribute( 'draggable', 'true' );
		} );

		$body.on( 'dragstart', 'li.attachment[data-id]', function ( event ) {
			dragging = filesFor( parseInt( this.getAttribute( 'data-id' ), 10 ) );

			var transfer = event.originalEvent.dataTransfer;

			if ( transfer ) {
				transfer.effectAllowed = 'move';
				// Firefox refuses to start a drag without some payload.
				transfer.setData( 'text/plain', dragging.join( ',' ) );
			}

			ourDrag = true;
			$body.addClass( 'wow-folders-dragging' );
		} );

		/*
		 * The uploader's full-screen drop zone reacts to any drag at all: its
		 * handler never looks at what is being dragged
		 * (wp-plupload.js:287). Dragging a tile therefore paints the whole
		 * window blue at z-index 250000, and the folder underneath can never
		 * be reached.
		 *
		 * Hiding it is not enough on its own. Core's hide() only sets
		 * opacity to 0 and waits for the transition before calling hide(),
		 * and its show() sets an inline display:block that outlives our
		 * class. Between those two the zone is an invisible sheet that still
		 * catches drag events, so a show() can be queued mid-drag and land
		 * the moment the class comes off — a blue screen that never goes
		 * away, which is exactly what happens in Chrome.
		 *
		 * So three things, not one: hide it, keep events away from it, and
		 * put it back to a known resting state afterwards.
		 */
		document.addEventListener( 'dragover', shieldUploader, true );
		document.addEventListener( 'dragenter', shieldUploader, true );

		/*
		 * dragend does not always arrive — a drag cancelled with Escape or
		 * lost to a window switch can swallow it — so the state is also
		 * cleared on the events that inevitably follow.
		 */
		$( document ).on( 'dragend drop pointerup', endDrag );

		function endDrag() {
			if ( ! ourDrag ) {
				return;
			}

			ourDrag = false;
			dragging = [];
			$body.removeClass( 'wow-folders-dragging' );
			$panel.find( '.is-drop-target' ).removeClass( 'is-drop-target' );

			// Once now, and once past core's 250ms opacity transition, to
			// catch a show() that was already on its way.
			restUploader();
			window.setTimeout( restUploader, 350 );
		}

		$panel.on( 'dragenter dragover', '.wow-folders__item', function ( event ) {
			if ( ! dragging.length || ! droppable( $( this ) ) ) {
				return;
			}

			event.preventDefault();
			event.originalEvent.dataTransfer.dropEffect = 'move';
			$( this ).addClass( 'is-drop-target' );
		} );

		$panel.on( 'dragleave', '.wow-folders__item', function () {
			$( this ).removeClass( 'is-drop-target' );
		} );

		$panel.on( 'drop', '.wow-folders__item', function ( event ) {
			var $item = $( this );

			if ( ! dragging.length || ! droppable( $item ) ) {
				return;
			}

			event.preventDefault();
			event.stopPropagation();
			$item.removeClass( 'is-drop-target' );

			var ids = dragging.slice();
			var term = 'none' === String( $item.data( 'folder' ) ) ? 0 : $item.data( 'term' );

			dragging = [];

			post( { task: 'assign', term: term, ids: ids } ).done( function ( response ) {
				applyCounts( $panel, response.data.counts );
				dropFromView( $panel, ids );
			} );
		} );
	}

	/**
	 * Keeps drag events away from core's drop zone during one of our drags.
	 *
	 * Capture phase, so it runs before the jQuery handler core bound on the
	 * zone itself. Only our own drags are shielded; dropping real files to
	 * upload behaves exactly as it always did.
	 */
	function shieldUploader( event ) {
		if ( ! ourDrag || ! event.target || ! event.target.closest ) {
			return;
		}

		if ( event.target.closest( '.uploader-window, .uploader-editor' ) ) {
			event.stopPropagation();
		}
	}

	/**
	 * Returns core's drop zone to the state it starts the page in.
	 */
	function restUploader() {
		$( '.uploader-window, .uploader-editor' )
			.removeClass( 'drag-over' )
			.css( 'opacity', 0 )
			.hide();
	}

	/**
	 * "All files" accepts nothing: everything is in it already.
	 */
	function droppable( $item ) {
		return undefined !== $item.data( 'term' ) || 'none' === String( $item.data( 'folder' ) );
	}

	/**
	 * The files a drag carries.
	 *
	 * Dragging one of several selected tiles carries the whole selection, the
	 * way file managers behave. Dragging an unselected tile carries just it.
	 */
	function filesFor( id ) {
		var library = grid();
		var frame = window.wp && window.wp.media && window.wp.media.frame;
		var selection = frame && frame.state && frame.state().get ? frame.state().get( 'selection' ) : null;

		if ( library && selection && selection.length ) {
			var ids = selection.pluck( 'id' );

			if ( -1 !== $.inArray( id, ids ) ) {
				return ids;
			}
		}

		return [ id ];
	}

	/**
	 * Takes moved files off the screen when they no longer match the filter.
	 */
	function dropFromView( $panel, ids ) {
		var $current = $panel.find( '.wow-folders__item.is-current' );

		if ( ! $current.length || '' === String( $current.data( 'folder' ) || '' ) ) {
			return; // Showing everything, so nothing stopped matching.
		}

		$.each( ids, function ( _, id ) {
			$( 'li.attachment[data-id="' + id + '"]' ).remove();
			$( 'tr#post-' + id ).remove();
		} );
	}

	function applyCounts( $panel, counts ) {
		if ( ! counts ) {
			return;
		}

		$panel.find( '.wow-folders__item' ).each( function () {
			var $item = $( this );
			var folder = String( $item.data( 'folder' ) || '' );
			var key = 'none' === folder ? 'none' : ( '' === folder ? 'all' : String( $item.data( 'term' ) ) );

			if ( undefined !== counts[ key ] ) {
				$item.find( '.wow-folders__count' ).text( counts[ key ] );
			}
		} );
	}

	function post( data ) {
		var $panel = $( '#wow-folders' ).addClass( 'is-busy' );

		return $.post(
			settings.ajaxUrl,
			$.extend( { action: settings.action, nonce: settings.nonce }, data )
		).fail( function () {
			window.alert( strings.failed || '' );
		} ).always( function () {
			$panel.removeClass( 'is-busy' );
		} );
	}
} )( jQuery );
