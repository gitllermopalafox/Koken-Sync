<?php

class KokenSyncAdmin {

	/**
	 * Instance of this class
	 */
	protected static $instance = null;

	/**
	 * Slug of the plugin screen
	 */
	protected $plugin_screen_hook_suffix = null;

	/**
	 * Initialize plugin
	 */
	private function __construct() {

		$this->koken_sync = KokenSync::get_instance();
		$this->plugin_slug = $this->koken_sync->get_plugin_slug();
		$this->plugin_path = $this->koken_sync->get_plugin_path();

		/**
		 * Load admin styles and scripts
		 */
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

		/**
		 * Add options page and menu item
		 */
		add_action( 'admin_menu', array( $this, 'add_admin_page' ), 99 );

		/**
		 * Register AJAX actions
		 */
		add_action( 'wp_ajax_koken_sync_refresh_albums', array( $this, 'ajax_refresh_albums' ) );
		add_action( 'wp_ajax_koken_sync_sync_album', array( $this, 'ajax_sync_album' ) );
		add_action( 'wp_ajax_koken_sync_set_album_status', array( $this, 'ajax_set_album_status' ) );
		add_action( 'wp_ajax_koken_sync_update_album_order', array( $this, 'ajax_update_album_order' ) );
	}

	/**
	 * Return an instance of this class
	 */
	public static function get_instance() {

		// If the single instance hasn't been set, set it now.
		if ( null == self::$instance ) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	/**
	 * Enqueue admin styles
	 */
	public function enqueue_admin_styles() {

		// Return early if no settings page is registered
		if ( ! isset( $this->plugin_screen_hook_suffix ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( $this->plugin_screen_hook_suffix == $screen->id ) {
			wp_enqueue_style( $this->plugin_slug . '-admin-styles', plugins_url( 'assets/admin.css', __FILE__ ), array(), KokenSync::VERSION );
		}
	}

	/**
	 * Enqueue admin scripts
	 */
	public function enqueue_admin_scripts() {

		// Return early if no settings page is registered
		if ( ! isset( $this->plugin_screen_hook_suffix ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( $this->plugin_screen_hook_suffix == $screen->id ) {
			wp_enqueue_script( 'jquery-ui-sortable' );
			wp_enqueue_script( $this->plugin_slug . '-admin-script', plugins_url( 'assets/admin.js', __FILE__ ), array( 'jquery' ), KokenSync::VERSION );
		}
	}

	/**
	 * Add options page and menu item
	 */
	public function add_admin_page() {

		$this->plugin_screen_hook_suffix = add_menu_page(
			'Koken Sync Albums',
			'Koken Sync',
			'manage_options',
			'koken_sync_admin',
			array( $this, 'display_admin_page' ),
			'dashicons-format-gallery',
			30
		);
	}

	/**
	 * Display admin page
	 */
	public function display_admin_page() {

		// Include admin albums table
		include_once( 'class-koken-sync-albums-table.php' );

		// Initialize the albums table and prepare items
		$albums_table = new KokenSyncAlbumsTable();
		$albums_table->prepare_items();

		// Include admin page
		include_once( 'views/admin.php' );
	}

	/**
	 * AJAX action: refresh albums
	 */
	public function ajax_refresh_albums() {

		global $wpdb;

		$albums_table = KokenSync::table_name('albums');

		// Keep track of synced albums
		$synced_albums = array();

		// Keep track of album data
		$album_data = array();

		// Get album data via Koken API
		$data = $this->koken_get('/albums/');

		// exit if no albums
		if ( !isset( $data->albums ) ) {
			echo json_encode(array(
				'error' => true,
				'message' => 'No albums were returned from Koken'
			));
			die();
		}

		foreach ( $data->albums as $album ) {

			// add id to synced albums
			$synced_albums[] = $album->id;

			// set up album fields
			$album_fields = array(
				'album_id' => $album->id,
				'time' => current_time('mysql'),
				'title' => esc_sql( esc_attr( $album->title ) ),
				'slug' => $album->slug,
				'summary' => esc_sql( esc_attr( $album->summary ) ),
				'description' => esc_sql( esc_attr( $album->description ) ),
				'image_count' => $album->counts->total,
				'modified' => $album->modified_on->datetime
			);

			// collect album data
			$album_data[] = '("' . join('", "', $album_fields) . '")';
		}

		// format cols for query
		$album_cols = array_keys( $album_fields );

		$album_query = $wpdb->query("
			INSERT INTO " . $albums_table . " (" . implode(',', $album_cols) . ") VALUES" . implode(',', $album_data) . "
			ON DUPLICATE KEY UPDATE
				time         = time,
				status		 = status,
				title        = VALUES(title),
				slug         = VALUES(slug),
				summary  	 = VALUES(summary),
				description  = VALUES(description),
				image_count  = VALUES(image_count),
				modified     = VALUES(modified)
			");

		if ( $album_query === false ) {
			echo json_encode(array(
				'error' => true,
				'message' => 'There was an error inserting albums into albums table.'
			));
			die();
		}

		echo json_encode(array(
			'message' => "Albums refreshed. $album_query new albums added."
		));

		// clean up old albums
		$this->clean_up_albums( $synced_albums );

		// End AJAX action
		die();
	}

	/**
	 * AJAX action: sync single album
	 *
	 * TODO: tame this beast.
	 */
	public function ajax_sync_album() {

		global $wpdb;

		// keep track of synced images
		$synced_images = array();

		// keep track of album data
		$image_data = array();
		$albums_images_data = array();
		$keywords = array();
		$keyword_data = array();
		$keywords_images_data = array();

		$current_time = current_time( 'mysql' );

		$album_id = $_POST['albumID'];

		$albums_table = KokenSync::table_name( 'albums' );
		$images_table = KokenSync::table_name( 'images' );
		$albums_images_table = KokenSync::table_name( 'albums_images' );
		$keywords_table = KokenSync::table_name( 'keywords' );
		$keywords_images_table = KokenSync::table_name( 'keywords_images' );

		$koken_data_path = '/albums/' . $album_id . '/content/';
		$koken_data = array();

		// Run first api request
		$data = $this->koken_get( $koken_data_path );

		// exit if no data returned
		if ( !isset( $data->content ) ) {
			$this->ajax_message( array( 'type' => 'error', 'message' => 'No images were returned from Koken.' ) );
		}

		// Koken returns a maximum of 100 items per request
		// So if there are over 100 items in the first request, run more requests
		$koken_requests = ceil( $data->total / 100 );

		$koken_data = array_merge( $data->content, $koken_data );

		for ( $i = 2; $i == $koken_requests; $i++ ) {
			// Run additional api request
			$data = $this->koken_get( $koken_data_path . 'page:' . $i );
			$koken_data = array_merge( $data->content, $koken_data );
		}

        $count = 0;

		foreach ( $koken_data as $image ) {

			// keep track of synced images
			$synced_images[] = $image->id;

			// set up image fields
			$image_fields = array(
				'image_id' => $image->id,
				'time' => $current_time,
				'title' => esc_sql( esc_attr( $image->title ) ),
				'slug' => $image->slug,
				'caption' => esc_sql( esc_attr( $image->caption ) ),
				'visibility' => $image->visibility->raw,
				'modified' => $image->modified_on->datetime,
				'cache_path' => esc_sql( serialize( $image->cache_path ) )
			);

			// set up album/image relationship fields
			$albums_images_fields = array(
				'album_id' => $album_id,
				'image_id' => $image->id,
                'sort_order' => $count
			);

			foreach ( $image->tags as $keyword ) {
				$keyword_slug = esc_sql( sanitize_title( $keyword ) );
				$keyword_id = md5( $keyword_slug );

				$keywords_images_data[] = '("' . $keyword_id . '", "' . $image->id . '")';

				// ignore if keyword is already in $keywords
				if ( !in_array( $keyword, $keywords ) ) {
					$keyword_data[] = '("' . $keyword_id . '", "' . $keyword_slug . '", "' . $keyword . '")';
					$keywords[] = $keyword;
				}
			}

			// collect image data
			$image_data[] = '("' . join('", "', $image_fields) . '")';
			$albums_images_data[] = '("' . join('", "', $albums_images_fields) . '")';

            $count++;
		}

		$image_cols = array_keys( $image_fields );
		$albums_images_cols = array_keys( $albums_images_fields );

		// insert images
		$image_query = $wpdb->query("
			INSERT INTO " . $images_table . " (" . implode(',', $image_cols) . ") VALUES" . implode(',', $image_data) . "
			ON DUPLICATE KEY UPDATE
				time         = time,
				title        = VALUES(title),
				slug         = VALUES(slug),
				caption      = VALUES(caption),
				visibility   = VALUES(visibility),
				modified     = VALUES(modified),
				cache_path   = VALUES(cache_path)
			");

		if ( $image_query === false ) {
			$this->ajax_message( array( 'type' => 'error', 'message' => 'There was a problem inserting images into the images table.' ) );
		}

		// update album synced time
		$album_synced_time_query = $wpdb->update( $albums_table, array( 'synced_time' => $current_time ), array( 'album_id' => $album_id ) );

		if ( $album_synced_time_query === false ) {
			$this->ajax_message( array( 'type' => 'error', 'message' => 'There was a problem updating the album sync time.' ) );
		}

		// set up album/image relationships
		$albums_images_query = $wpdb->query("
			INSERT INTO " . $albums_images_table . " (" . implode(',', $albums_images_cols) . ") VALUES" . implode(',', $albums_images_data) . "
			ON DUPLICATE KEY UPDATE
				album_id = album_id,
				image_id = image_id,
                sort_order = sort_order
		");

		if ( $albums_images_query === false ) {
			$this->ajax_message( array( 'type' => 'error', 'message' => 'There was a problem updating the album/image relationships.' ) );
		}

		if ( ! empty( $keyword_data ) ) {

			// add keywords
			$keywords_query = $wpdb->query("
				INSERT INTO " . $keywords_table . " (keyword_id,slug,keyword) VALUES" . implode(',', $keyword_data) . "
				ON DUPLICATE KEY UPDATE
					keyword_id = keyword_id,
					slug = slug,
					keyword = keyword
			");

			if ( $keywords_query === false ) {
				$this->ajax_message( array( 'type' => 'error', 'message' => 'There was a problem updating keywords.' ) );
			}

			// pre-clean keywords for synced images
			$prepared_synced_images = join( ',', $synced_images );
			$keywords_images_query = $wpdb->query("
				DELETE FROM " . $keywords_images_table . "
				WHERE image_id IN ($prepared_synced_images)
			");

			if ( $keywords_images_query === false ) {
				$this->ajax_message( array( 'type' => 'error', 'message' => 'There was a problem removing keywords before sync.' ) );
			}

			$keywords_images_query = $wpdb->query("
				INSERT IGNORE
				INTO " . $keywords_images_table . " (keyword_id,image_id) VALUES" . implode(',', $keywords_images_data) . "
			");

			if ( $keywords_images_query === false ) {
				$this->ajax_message( array( 'type' => 'error', 'message' => 'There was a problem updating keyword/image relationships.') );
			}

		}

		// clean up images that are no longer in this album 
		$cleanup = $this->clean_up_images( $synced_images, $album_id );

		if ( is_array( $cleanup ) ) {
			echo $cleanup;
			die();
		}

		$this->ajax_message( array( 'message' => $albums_images_query . ' images updated.' ) );
		die();
	}

	/**
	 * Clean up old albums
	 */
	public function clean_up_albums( $ids ) {

		global $wpdb;

		$albums_table = KokenSync::table_name('albums');

		$ids = join( ',', $ids );

		// clean up albums
		$wpdb->query("
			DELETE FROM " . $albums_table . "
			WHERE album_id NOT IN ($ids)
		");
	}

	/**
	 * Clean up images that are no longer included in an album
	 */
	public function clean_up_images( $ids_to_preserve, $album_id ) {

		global $wpdb;

		// Prepare $ids_to_preserve
		$ids_to_preserve = join( ',', $ids_to_preserve );

		// Get ids to remove
		$ids_to_remove = $wpdb->get_results( $wpdb->prepare("
			SELECT image_id
			FROM " . KokenSync::table_name('albums_images') . "
			WHERE album_id = %d
			AND image_id NOT IN ($ids_to_preserve)
		", $album_id), OBJECT_K );

		// Return query error
		if ( $ids_to_remove === false ) {
			return json_encode(array(
				'type' => 'error',
				'message' => 'There was a problem getting ids to remove.'
			));
		}

		$ids_to_remove = array_keys( $ids_to_remove );

		// Return if no ids to remove
		if ( empty( $ids_to_remove ) ) {
			return true;
		}

		$prepared_ids_to_remove = join( ',', $ids_to_remove );

		// Check $ids_to_remove for other album relationships
		$removed_ids_to_preserve = $wpdb->get_results( $wpdb->prepare("
			SELECT image_id
			FROM " . KokenSync::table_name('albums_images') . "
			WHERE image_id IN ($prepared_ids_to_remove)
			AND album_id != %d
		", $album_id), OBJECT_K );

		// Return query error
		if ( $removed_ids_to_preserve === false ) {
			return json_encode(array(
				'type' => 'error',
				'message' => 'There was a problem getting album relationships for removed images.'
			));
		}

		$removed_ids_to_preserve = array_keys( $removed_ids_to_preserve );
		$prepared_removed_ids_to_preserve = join( ',', $removed_ids_to_preserve );

		// Remove image relationships for this album
		$delete_relationships_query = $wpdb->query( $wpdb->prepare("
			DELETE FROM " . KokenSync::table_name('albums_images') . "
			WHERE album_id = %d
			AND image_id IN ($prepared_ids_to_remove)
		", $album_id ) );

		// Return query error
		if ( $delete_relationships_query === false ) {
			return json_encode(array(
				'type' => 'error',
				'message' => 'There was a problem removing album/image relationships.'
			));
		}

		// Update $ids_to_remove by removing $removed_ids_to_preserve
		foreach ( $ids_to_remove as $key => $id ) {
			if ( in_array( $id, $removed_ids_to_preserve ) ) {
				unset( $ids_to_remove[ $key ] );
			}
		}

		// Finally, delete image left over in $ids_to_remove
		$prepared_ids_to_remove = join( ',', $ids_to_remove );
		$delete_images_query = $wpdb->query("
			DELETE
			FROM " . KokenSync::table_name('images') . "
			WHERE image_id IN ($prepared_ids_to_remove)
		");

		// Return query error
		if ( $delete_images_query === false ) {
			return json_encode(array(
				'type' => 'error',
				'message' => 'There was a problem deleting removed images.'
			));
		}

		return true;
	}

	/**
	 * AJAX action: set album status
	 */
	public function ajax_set_album_status() {

		global $wpdb;

		$album_id = $_POST['albumID'];
		$status = $_POST['status'];

		$update_query = $wpdb->query( $wpdb->prepare("
			UPDATE " . KokenSync::table_name('albums') . "
			SET status = '%s'
			WHERE album_id = %d
		", $status, $album_id ) );

		// Return query error
		if ( $update_query === false ) {
			echo json_encode(array(
				'type' => 'error',
				'message' => 'There was a problem setting album status.'
			));
			die();
		}

		echo json_encode(array(
			'message' => "$album_id was set to $status"
		));

		die();
	}

	/**
	 * Update album order
	 */
	public function ajax_update_album_order() {

		global $wpdb;

		$order = $_POST['order'];

		$album_ids = array_keys( $order );

		$album_data = array();

		foreach ( $order as $key => $value ) {
			$album_data[] = '(' . $value . ', ' . $key . ')';
		}

		$order_query = $wpdb->query("
			INSERT INTO " . KokenSync::table_name('albums') . " (album_id, album_order) VALUES " . implode(',', $album_data) . "
			ON DUPLICATE KEY UPDATE
				album_order = VALUES(album_order)
		");

		die();
	}

	/**
	 * Send a message to an AJAX action
	 */
	public function ajax_message( $args = array() ) {

		extract( wp_parse_args( $args, array(
			'type' => 'message'
		) ) );

		echo json_encode(array(
			'type' => $type,
			'message' => $message
		));

		if ( $type == 'error' ) {
			die();
		}
	}

	/**
	 * Get data via Koken API
	 */
	protected function koken_get( $url ) {

		$data = $this->koken_call( $url );

		if ( ! $data ) {
			throw new Exception( 'Cannot connect with the Koken API' );
		}

		return $data;
	}

	/**
	 * Make a Koken API call
	 *
	 * From https://github.com/Haza/Koken-API-PHP
	 */
	protected function koken_call( $url ) {

		$base = $this->koken_sync->get_koken_url() .'/api.php';

		$ch = curl_init();
	    //curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
	    curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
	    curl_setopt($ch, CURLOPT_URL, $base . $url);
	    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
	    curl_setopt(
	      $ch,
	      CURLOPT_HTTPHEADER,
	      array('Content-type: application/json')
	    );
	    curl_setopt($ch, CURLOPT_USERAGENT, 'MozillaXYZ/1.0');
	    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
	    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

	    $output = curl_exec($ch);
	    curl_close($ch);
	    $decoded = json_decode($output);

	    return is_null($decoded) ? $output : $decoded;
	}
	

}