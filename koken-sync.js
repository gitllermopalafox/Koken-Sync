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

		$button.on( 'click', function ( event ) {

			// prevent concurrent sync actions
			if ( $button.hasClass('active') ) {
				return;
			}

			$button.attr('value', 'Refreshing albums...');
			$button.attr('disabled', 'disabled');

			$.ajax({
				type: 'POST',
				url: ajaxurl,
				data: data,
				error: function ( jqXHR, textStatus, errorThrown ) {
					console.log( jqXHR, textStatus, errorThrown );
				},
				success: function ( response ) {
					var response = $.parseJSON( response );

					if ( response.error ) {
						console.log( response.message );
						$button.attr( 'value', buttonValue );
						$button.removeAttr('disabled');
						return;
					}
					$button.attr( 'value', buttonValue );
					$button.removeAttr('disabled');
					location.reload();
				}
			});

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

		var currentlySyncing = [];

		$rows.on( 'click', function ( event ) {

			// only work with clicks on button
			if ( !$(event.target).hasClass('KokenSyncAlbum') ) {
				return;
			}

			var $button = $(this).find( '.KokenSyncAlbum' ),
				buttonValue = $button.text(),
				$message = $(this).find('.KokenSyncAlbumMessage'),
				albumID = $(this).attr('data-album-id');

			// check in to currentlySyncing
			currentlySyncing.push( albumID );

			var data = {
				action: 'koken_sync_sync_album',
				albumID: albumID
			};

			$button.text('Syncing album...');
			$button.attr('disabled', 'disabled');

			$.ajax({
				type: 'POST',
				url: ajaxurl,
				data: data,
				error: function ( jqXHR, textStatus, errorThrown ) {
					console.log( jqXHR, textStatus, errorThrown );
					return;
				},
				success: function ( response ) {
					var response = $.parseJSON( response );

					if ( response.error ) {
						console.log( response.message );
						$button.text(buttonValue);
						$button.removeAttr('disabled');
						$message.text( response.message );
						return;
					}

					// remove from currentlySyncing
					currentlySyncing = $.grep( currentlySyncing, function ( value ) {
						return value != albumID;
					} );

					$button.text(buttonValue);
					$button.removeAttr('disabled');

					// reload when no more albums are syncing
					if ( currentlySyncing.length === 0 ) {
						location.reload();
					}
				}
			});

			return false;
		} );
	};

	KokenSync.prototype.statusSelect = function () {
		var _this = this;

		var $table = $('table.kokensyncalbums'),
			$rows = $table.find('tbody tr');

		var currentlyUpdating = [];

		$rows.each( function () {

			var $select = $(this).find('.KokenSyncStatusSelect'),
				albumID = $(this).attr('data-album-id'),
				statusValue;

			$select.on( 'change', function () {
				statusValue = this.value;

				// add to currentlyUpdating
				currentlyUpdating.push( albumID );

				setStatus( albumID, statusValue );
			} );
		} );

		function setStatus( albumID, statusValue ) {

			var data = {
				action: 'koken_sync_set_album_status',
				albumID: albumID,
				status: statusValue
			};

			$.ajax({
				type: 'POST',
				url: ajaxurl,
				data: data,
				error: function ( jqXHR, textStatus, errorThrown ) {
					console.log( jqXHR, textStatus, errorThrown );
				},
				success: function ( response ) {
					var response = $.parseJSON( response );

					if ( response.error ) {
						console.log( response.message );
						return;
					}

					// remove from currentlyUpdating
					currentlyUpdating = $.grep( currentlyUpdating, function ( value ) {
						return value != albumID;
					} );

					if ( currentlyUpdating.length === 0 ) {
						location.reload();
					}
				}
			});
		}

	};

	// Instantiate plugin when DOM is ready
	$(function() {

		new KokenSync({});

	});

})(jQuery, window, document);