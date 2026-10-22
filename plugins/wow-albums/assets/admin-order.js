/**
 * Dragging rows in the album list.
 *
 * The order is written from the row positions on the current page, offset by
 * the earlier pages, so it only works in the full list with no search, no
 * filters and no column sorting.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		var settings = window.wowAlbumsOrder;
		var $list = $( '#the-list' );

		if ( ! settings || ! $list.length ) {
			return;
		}

		var $table = $list.closest( 'table' );

		if ( ! settings.sortable ) {
			$table.addClass( 'wow-albums-locked' );
			$list.find( '.wow-album-handle' ).attr( 'title', settings.lockedNotice );
			return;
		}

		$table.addClass( 'wow-albums-sortable' );

		// While dragging, the row leaves the table and its columns collapse,
		// so the widths are pinned on the copy that follows the cursor.
		function keepColumnWidths( event, ui ) {
			ui.children().each( function () {
				$( this ).width( $( this ).width() );
			} );

			return ui;
		}

		function collectOrder() {
			return $list
				.children( 'tr' )
				.map( function () {
					return this.id.replace( 'post-', '' );
				} )
				.get();
		}

		$list.sortable( {
			items: '> tr',
			axis: 'y',
			cursor: 'move',
			handle: '.wow-album-handle',
			helper: keepColumnWidths,
			placeholder: 'wow-album-placeholder',
			opacity: 0.8,
			update: function () {
				$table.addClass( 'wow-albums-saving' );

				$.post( settings.ajaxUrl, {
					action: settings.action,
					nonce: settings.nonce,
					offset: settings.offset,
					order: collectOrder()
				} )
					.fail( function () {
						window.alert( settings.errorNotice );
					} )
					.always( function () {
						$table.removeClass( 'wow-albums-saving' );
					} );
			}
		} );
	} );
} )( jQuery );
