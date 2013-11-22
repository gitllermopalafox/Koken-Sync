;(function($, window, document, undefined) {

	var KokenSync = function( options ) {
		this.init( options );
	};

	KokenSync.prototype = {};

	KokenSync.prototype.settings = {
	};

	KokenSync.prototype.init = function ( options ) {
		
		this.options = $.extend( true, {}, this.settings, options );

		// sync albums
		this.syncAlbums();

		// sync album
		this.syncAlbum();

		// status select
		this.statusSelect();
	};

	/**
	 * Sync albums
	 */
	KokenSync.prototype.syncAlbums = function () {
		var _this = this;

		var $button = $('#KokenSyncSyncAlbums');

		var buttonValue = $button.attr('value');

		var data = {
			action: 'koken_sync_sync_albums'
		};

		$button.on( 'click', function () {

			// prevent concurrent sync actions
			if ( $button.hasClass('active') ) {
				return;
			}

			$button.attr('value', 'Refreshing albums...');
			$button.addClass('active' );

			$.post( ajaxurl, data, function ( response ) {
				console.log( response );
			} ).success( function () {
				$button.attr( 'value', buttonValue );
				$button.removeClass( 'active' );
				location.reload();
			} );

			return false;
		} );
	};

	/**
	 * Sync album
	 */
	KokenSync.prototype.syncAlbum = function () {
		var _this = this;

		var $table = $('table.kokensyncalbums'),
			$rows = $table.find('tbody tr');

		$rows.on( 'click', function ( event ) {

			// only work with clicks on button
			if ( !$(event.target).hasClass('KokenSyncAlbum') ) {
				return;
			}

			var $button = $(this).find( '.KokenSyncAlbum' ),
				buttonValue = $button.text();

			var data = {
				action: 'koken_sync_sync_album',
				albumID: $(this).attr('data-album-id')
			};

			$button.text('Syncing album...');
			$button.addClass('active');

			$.post( ajaxurl, data, function ( response ) {
				//console.log(response);
			} ).success( function () {
				$button.text(buttonValue);
				$button.removeClass('active');
				location.reload();
			} );

			return false;
		} );
	};

	KokenSync.prototype.statusSelect = function () {
		var _this = this;

		var $table = $('table.kokensyncalbums'),
			$rows = $table.find('tbody tr');

		$rows.each( function () {

			var $select = $(this).find('.KokenSyncStatusSelect'),
				albumID = $(this).attr('data-album-id'),
				statusValue;

			$select.on( 'change', function () {
				statusValue = this.value;

				setStatus( albumID, statusValue );
			} );
		} );

		function setStatus( albumID, statusValue ) {

			var data = {
				action: 'koken_sync_set_album_status',
				albumID: albumID,
				status: statusValue
			};

			$.post( ajaxurl, data, function ( response ) {
				console.log( response );
			} ).success( function() {
				
			} );
		}

	};

	// Instantiate plugin when DOM is ready
	$(function() {

		new KokenSync({});

	});

})(jQuery, window, document);