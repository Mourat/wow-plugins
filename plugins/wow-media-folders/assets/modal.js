/**
 * The folder dropdown in the media modal.
 *
 * Two pieces: a filter view of the kind core uses for "Images / Audio /
 * Video", and one extension of the browser's toolbar so the dropdown is
 * rebuilt along with everything else in it.
 *
 * Filtering goes through the collection's props under the taxonomy's own
 * query_var, which core threads all the way to WP_Query. "No folder" cannot
 * travel that way — it is a NOT EXISTS, not a term — so it has its own
 * parameter, turned into a tax_query on the server.
 */

( function ( wp, _ ) {
	'use strict';

	var settings = window.wowMediaFoldersModal || {};
	var strings = settings.strings || {};

	if ( ! wp || ! wp.media || ! wp.media.view || ! wp.media.view.AttachmentFilters ) {
		return;
	}

	/**
	 * The two props that make up one choice.
	 *
	 * Both are always stated, null included: a filter view decides which
	 * option to show as selected by comparing every prop it names against the
	 * collection, so a prop left out of one choice would make it match while
	 * another folder is still in force.
	 */
	function props( slug, none ) {
		var set = {};

		set[ settings.taxonomy ] = slug;
		set[ settings.noneParam ] = none;

		return set;
	}

	var FolderFilter = wp.media.view.AttachmentFilters.extend( {
		id: 'wow-media-folder-filter',
		className: 'attachment-filters wow-media-folder-filter',

		createFilters: function () {
			var filters = {
				all: {
					text: strings.all,
					props: props( null, null ),
					priority: 10,
				},
				none: {
					text: strings.none,
					props: props( null, 1 ),
					priority: 20,
				},
			};

			_.each( settings.folders || [], function ( folder, index ) {
				filters[ folder.slug ] = {
					text: folder.text,
					props: props( folder.slug, null ),
					priority: 30 + index,
				};
			} );

			this.filters = filters;
		},
	} );

	/*
	 * The toolbar is built by the browser itself and thrown away with it, so
	 * the dropdown is added from inside that same method. Everything core
	 * puts there is left untouched; this only appends one more view.
	 */
	var createToolbar = wp.media.view.AttachmentsBrowser.prototype.createToolbar;

	wp.media.view.AttachmentsBrowser.prototype.createToolbar = function () {
		createToolbar.apply( this, arguments );

		/*
		 * `filters` is what core checks before drawing its own dropdowns.
		 * Without it the browser is a bare list — the files already attached
		 * to the post, say — where filtering by folder would be beside the
		 * point.
		 */
		if ( ! this.options.filters || ! settings.taxonomy ) {
			return;
		}

		/*
		 * -70 puts the folder filter after core's own two — filter by type at
		 * -80, filter by date at -75 — so it is reached in the order it is
		 * seen.
		 */
		this.toolbar.set( 'wowFolderFilterLabel', new wp.media.view.Label( {
			value: strings.label,
			attributes: { 'for': 'wow-media-folder-filter' },
			priority: -70,
		} ).render() );

		this.toolbar.set( 'wowFolderFilter', new FolderFilter( {
			controller: this.controller,
			model: this.collection.props,
			priority: -70,
		} ).render() );
	};
}( window.wp, window._ ) );
