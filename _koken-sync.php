<?php
/*
Plugin Name: Koken Sync
Description: Sets up Koken as an image service for publishing albums in WordPress
Version: 0.0.1
Author: Darin Reid
Author URI: http://elcontraption.com/
License: GPL2

Copyright 2013	Darin Reid	(email : darinreid@gmail.com)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License, version 2, as 
published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.	See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA	 02110-1301	 USA
*/

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( ! class_exists( 'KokenSync' ) ) :

/**
 * Main KokenSync Class
 */
class KokenSync {

/*

	* Pre-cache collections, far-future transients.
	* Keyword searches need to happen locally, and then be transient cached.

*/

	public $plugin_version = '0.0.1';

	public $plugin_dirname = 'koken-sync';

	public $plugin_location;

	// TODO: add this as an admin option
	public $koken_path = 'http://elcontraption.com/koken';

	/**
	 * KokenSync constructor
	 */
	public function __construct() {

		// Define version constant
		//define( 'KOKEN_SYNC_VERSION', $this->version );

		// Get plugin URL
		$this->plugin_url = plugins_url( '', __FILE__ ) . '/';

		$this->albums_table = $this->table_name( 'albums' );
		$this->images_table = $this->table_name( 'images' );
		$this->albums_images_table = $this->table_name( 'albums_images' );
		$this->keywords_table = $this->table_name( 'keywords' );
		$this->keywords_images_table = $this->table_name( 'keywords_images' );

		// Activation
		register_activation_hook( __FILE__, array( $this, 'activate' ) );

		// Deactivation
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

		// Admin only beyond this point
		if ( is_admin() ) {

			// Include required files
			$this->includes();

			// Enqueue styles and scripts
			add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );

			// Ajax action: sync albums
			add_action( 'wp_ajax_koken_sync_sync_albums', array( $this, 'ajax_sync_albums' ) );

			// Ajax action: sync album
			add_action( 'wp_ajax_koken_sync_sync_album', array( $this, 'ajax_sync_album' ) );

			// Ajax action: set album status
			add_action( 'wp_ajax_koken_sync_set_album_status', array( $this, 'ajax_set_album_status' ) );

		} // is_admin()
	}

	/**
	 * Activation
	 */
	function activate() {
		global $wpdb;

		$tables = array(
			array(
				'name' => $this->albums_table,
				'query' => "
					CREATE TABLE " . $this->albums_table . " (
					id mediumint(9) NOT NULL AUTO_INCREMENT,
					album_id bigint NOT NULL,
					time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
					title tinytext NOT NULL,
					slug tinytext NOT NULL,
					status VARCHAR(255) DEFAULT 'unpublished' NOT NULL,
					summary text NOT NULL,
					description text NOT NULL,
					image_count mediumint(9) NOT NULL,
					password tinytext NOT NULL,
					modified datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
					synced_time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
					UNIQUE KEY id (id),
					UNIQUE KEY (album_id)
				);"
			),
			array(
				'name' => $this->images_table,
				'query' => "
					CREATE TABLE " . $this->images_table . " (
					id mediumint(9) NOT NULL AUTO_INCREMENT,
					image_id bigint NOT NULL,
					time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
					title tinytext NOT NULL,
					slug tinytext NOT NULL,
					caption text NOT NULL,
					visibility tinytext NOT NULL,
					modified datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
					cache_path text NOT NULL,
					UNIQUE KEY id (id),
					UNIQUE KEY (image_id)
				);"
			),
			array(
				'name' => $this->albums_images_table,
				'query' => "
					CREATE TABLE " . $this->albums_images_table . " (
					album_id bigint NOT NULL,
					image_id bigint NOT NULL,
					PRIMARY KEY  (album_id,image_id)
				);"
			),
			array(
				'name' => $this->keywords_table,
				'query' => "
					CREATE TABLE " . $this->keywords_table . " (
					id mediumint(9) NOT NULL AUTO_INCREMENT,
					keyword_id BINARY(16) NOT NULL,
					slug VARCHAR(255) NOT NULL,
					keyword tinytext NOT NULL,
					UNIQUE KEY id (id),
					UNIQUE KEY (keyword_id)
				);"
			),
			array(
				'name' => $this->keywords_images_table,
				'query' => "
					CREATE TABLE " . $this->keywords_images_table . " (
					keyword_id BINARY(16) NOT NULL,
					image_id bigint NOT NULL,
					PRIMARY KEY  (keyword_id,image_id)
				);"
			),
		);

		// Run queries
		foreach ( $tables as $table ) {
			if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$table['name']}'" ) ) {
				$wpdb->query( $table['query'] );
			}
		}	
	}

	/**
	 * Deactivation
	 */
	function deactivate() {
		global $wpdb;

		$tables = array(
			$this->table_name( 'albums' )
		);

		// Run queries
		foreach ( $tables as $table ) {
			$wpdb->query( $wpdb->prepare("
				DROP TABLE IF EXISTS %s
			", $table ) );
		}
	}

	/**
	 * Include required files
	 */
	function includes() {

		// Koken API
		include_once( 'lib/Koken-API-PHP/lib/Koken.php' );

		// Albums table
		include_once( 'albums-table.php' );

		// Options page
		include_once( 'admin-options.php' );
	}

	/**
	 * Enqueue admin styles and scripts
	 */
	function admin_enqueue_scripts() {

		// Styles
		wp_enqueue_style( 'koken-sync', $this->plugin_url . 'koken-sync.css' );

		// Scripts
		wp_enqueue_script( 'koken-sync', $this->plugin_url . 'koken-sync.js', 'jquery' );
	}

	/**
	 * Get a table name
	 */
	static function table_name($name)
	{
		global $wpdb;
		return $wpdb->prefix . 'koken_sync_' . $name;
	}

	/**
	 * Sync all albums from Koken
	 *
	 * Intentionally writes limted info on albums to the DB.
	 * Add items to $album_fields if needed.
	 */
	function ajax_sync_albums() {
		global $wpdb;

		// keep track of synced albums
		$synced_albums = array();

		// keep track of album data
		$album_data = array();

		$albums_table = KokenSync::table_name('albums');

		$koken = new Koken( $this->koken_path );
		$data = $koken->call('/albums');

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
				'title' => esc_sql( $album->title ),
				'slug' => $album->slug,
				'summary' => esc_sql( $album->summary ),
				'description' => esc_sql( $album->description ),
				'image_count' => $album->counts->total,
				'modified' => $album->modified_on->datetime
			);

			// collect album data
			$album_data[] = '("' . join('", "', $album_fields) . '")';
		}

		// format for query
		$album_cols = array_keys( $album_fields );

		$album_query = $wpdb->query( $wpdb->prepare("
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
			", null ) );

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

		die();
	}

	/**
	 * Sync single album images from Koken
	 *
	 * TODO: clean this up and make more modular
	 */
	function ajax_sync_album() {
		global $wpdb;

		// keep track of synced images
		$synced_images = array();

		// keep track of album data
		$image_data = array();
		$albums_images_data = array();
		$keyword_data = array();
		$keywords_images_data = array();

		$current_time = current_time( 'mysql' );

		$album_id = $_POST['albumID'];

		$albums_table = KokenSync::table_name( 'albums' );
		$images_table = KokenSync::table_name( 'images' );
		$albums_images_table = KokenSync::table_name( 'albums_images' );
		$keywords_table = KokenSync::table_name( 'keywords' );
		$keywords_images_table = KokenSync::table_name( 'keywords_images' );

		$koken = new Koken( $this->koken_path );
		$koken_data_path = '/albums/' . $album_id . '/content/';
		$koken_data = array();

		// Run first api request
		$data = $koken->call( $koken_data_path );

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
			$data = $koken->call( $koken_data_path . 'page:' . $i );
			$koken_data = array_merge( $data->content, $koken_data );
		}

		foreach ( $koken_data as $image ) {

			// keep track of synced images
			$synced_images[] = $image->id;

			// set up image fields
			$image_fields = array(
				'image_id' => $image->id,
				'time' => $current_time,
				'title' => esc_sql( $image->title ),
				'slug' => $image->slug,
				'caption' => esc_sql( $image->caption ),
				'visibility' => $image->visibility->raw,
				'modified' => $image->modified_on->datetime,
				'cache_path' => esc_sql( serialize( $image->cache_path ) )
			);

			// set up album/image relationship fields
			$albums_images_fields = array(
				'album_id' => $album_id,
				'image_id' => $image->id
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
		}

		$image_cols = array_keys( $image_fields );
		$albums_images_cols = array_keys( $albums_images_fields );

		// insert images
		$image_query = $wpdb->query( $wpdb->prepare("
			INSERT INTO " . $images_table . " (" . implode(',', $image_cols) . ") VALUES" . implode(',', $image_data) . "
			ON DUPLICATE KEY UPDATE
				time         = time,
				title        = VALUES(title),
				slug         = VALUES(slug),
				caption      = VALUES(caption),
				visibility   = VALUES(visibility),
				modified     = VALUES(modified),
				cache_path   = VALUES(cache_path)
			", null) );

		if ( $image_query === false ) {
			$this->ajax_message( array( 'type' => 'error', 'message' => 'There was a problem inserting images into the images table.' ) );
		}

		// update album synced time
		$album_synced_time_query = $wpdb->update( $albums_table, array( 'synced_time' => $current_time ), array( 'album_id' => $album_id ) );

		if ( $album_synced_time_query === false ) {
			$this->ajax_message( array( 'type' => 'error', 'message' => 'There was a problem updating the album sync time.' ) );
		}

		// set up album/image relationships
		$albums_images_query = $wpdb->query( $wpdb->prepare("
			INSERT INTO " . $albums_images_table . " (" . implode(',', $albums_images_cols) . ") VALUES" . implode(',', $albums_images_data) . "
			ON DUPLICATE KEY UPDATE
				album_id = album_id,
				image_id = image_id
		"), null );

		if ( $albums_images_query === false ) {
			$this->ajax_message( array( 'type' => 'error', 'message' => 'There was a problem updating the album/image relationships.' ) );
		}

		if ( ! empty( $keyword_data ) ) {

			// add keywords
			$keywords_query = $wpdb->query( $wpdb->prepare("
				INSERT INTO " . $keywords_table . " (keyword_id,slug,keyword) VALUES" . implode(',', $keyword_data) . "
				ON DUPLICATE KEY UPDATE
					keyword_id = keyword_id,
					slug = slug,
					keyword = keyword
			"), null );

			if ( $keywords_query === false ) {
				$this->ajax_message( array( 'type' => 'error', 'message' => 'There was a problem updating keywords.' ) );
			}

			// pre-clean keywords for synced images
			$prepared_synced_images = join( ',', $synced_images );
			$keywords_images_query = $wpdb->query( $wpdb->prepare("
				DELETE FROM " . $keywords_images_table . "
				WHERE image_id IN ($prepared_synced_images)
			"), null );

			if ( $keywords_images_query === false ) {
				$this->ajax_message( array( 'type' => 'error', 'message' => 'There was a problem removing keywords before sync.' ) );
			}

			$keywords_images_query = $wpdb->query( $wpdb->prepare("
				INSERT IGNORE
				INTO " . $keywords_images_table . " (keyword_id,image_id) VALUES" . implode(',', $keywords_images_data) . "
			"), null );

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
	 * Send a message to an AJAX action
	 */
	function ajax_message( $args = array() ) {

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
	 * Clean up images
	 *
	 * Checks albums_images for images that are in this album
	 * Remove entry in albums_images
	 * AND IF image is NOT in any other albums, remove the image too.
	 *
	 * TODO: clean up and make more modular
	 */
	function clean_up_images( $ids_to_preserve, $album_id ) {
		global $wpdb;

		$ids_to_remove = array();

		$removed_ids_to_preserve = array();

		$albums_images_table = KokenSync::table_name( 'albums_images' );
		$images_table = KokenSync::table_name( 'images' );

		// prepare $ids_to_preserve
		$prepared_ids_to_preserve = join( ',', $ids_to_preserve );

		// get ids to remove
		$ids_to_remove_query = $wpdb->get_results( $wpdb->prepare("
			SELECT image_id
			FROM " . $albums_images_table . "
			WHERE album_id = %d
			AND image_id NOT IN ($prepared_ids_to_preserve)
		", $album_id ) );

		if ( $ids_to_remove_query === false ) {
			$error = json_encode(array(
				'error' => true,
				'message' => 'There was a problem getting ids to remove.'
			));
		}
		
		foreach ( $ids_to_remove_query as $obj ) {
			$ids_to_remove[] = $obj->image_id;
		}

		// prepare $ids_to_remove for query
		$prepared_ids_to_remove = join( ',', $ids_to_remove );

		// check $ids_to_remove for other album relationships
		$removed_ids_to_preserve_query = $wpdb->get_results( $wpdb->prepare("
			SELECT image_id
			FROM " . $albums_images_table . "
			WHERE image_id IN ($prepared_ids_to_remove)
			AND album_id != %d
		", $album_id ) );

		if ( $removed_ids_to_preserve_query === false ) {
			$error = json_encode(array(
				'error' => true,
				'message' => 'There was a problem getting album relationships for removed images.'
			));
		}

		// for $ids_to_remove with other album relationships,
		// add to $removed_ids_to_preserve
		foreach ( $removed_ids_to_preserve_query as $id ) {
			$removed_ids_to_preserve[] = $id->image_id;
		}

		// remove album/image relationships
		$delete_relationships_query = $wpdb->query( $wpdb->prepare("
			DELETE FROM " . $albums_images_table ."
			WHERE album_id = %d
			AND image_id IN ($prepared_ids_to_remove)
		", $album_id ) );

		if ( $delete_relationships_query === false ) {
			$error = json_encode(array(
				'error' => true,
				'message' => 'There was a problem removing album/image relationships.'
			));
		}

		// udpate $ids_to_remove by removing $removed_ids_to_preserve

		foreach ( $ids_to_remove as $key => $id ) {
			if ( in_array( $id, $removed_ids_to_preserve ) ) {
				unset( $ids_to_remove[ $key ] );
			}
		}

		// finally, delete images left over in $ids_to_remove
		$prepared_ids_to_remove = join( ',', $ids_to_remove );
		$delete_images_query = $wpdb->query( $wpdb->prepare("
			FROM " . $images_table . "
			WHERE image_id IN ($prepared_ids_to_remove)
		"), null );

		if ( $delete_images_query === false ) {
			$error = json_encode(array(
				'error' => true,
				'message' => 'There was a problem deleting removed images.'
			));
		}

		if ( isset( $error ) && !empty( $error ) ) {
			return $error;
		}

		return true;
	}

	/**
	 * Set album status
	 */
	function ajax_set_album_status() {
		global $wpdb;

		$album_id = $_POST['albumID'];
		$status = $_POST['status'];

		$albums_table = KokenSync::table_name( 'albums' );

		$update_query = $wpdb->query( $wpdb->prepare("
			UPDATE " . $albums_table . "
			SET status = '%s'
			WHERE album_id = %d
		", $status, $album_id ) );

		if ( $update_query ) {
			$response['status'] = $status;
			$response['message'] = "$album->title was updated to $status";
		} else {
			$response['status'] = false;
			$response['message'] = "There was a problem updating $album->title to $status";
		}

		echo json_encode( $response );
		die();
	}

	/**
	 * Clean up albums
	 *
	 * Removes albums included in $ids, after a sync
	 */
	function clean_up_albums( $ids ) {
		global $wpdb;

		$ids = join( ',', $ids );

		$albums_table = KokenSync::table_name( 'albums' );

		// clean up albums
		$wpdb->query("
			DELETE FROM " . $albums_table . "
			WHERE album_id NOT IN ($ids)
		");
	}

	/**
	 * Get images with keywords and albums
	 */
	static function get_images( $args = array() ) {

		global $wpdb;

		// Get published albums
		$albums = self::get_albums();

		// Get images
		$images = $wpdb->get_results("
			SELECT images.*, albums.album_id
			FROM " . self::table_name('images') . " images
			INNER JOIN " . self::table_name('albums_images') . " albums_images
			ON images.image_id = albums_images.image_id
			INNER JOIN " . self::table_name('albums') . " albums
			ON albums_images.album_id = albums.album_id
			WHERE albums.status = 'published'
		");

		// Collect image ids
		$image_ids = array();

		foreach ( $images as $image ) {
			$image_ids[] = $image->image_id;
		}

		$image_ids = array_unique( $image_ids );

		// Get albums
		$albums = $wpdb->get_results("
			SELECT albums.*, albums_images.image_id
			FROM " . self::table_name('albums_images') . " albums_images
			INNER JOIN " . self::table_name('albums') . " albums
			ON albums_images.album_id = albums.album_id
			WHERE albums_images.image_id IN (" . implode(',', $image_ids) . ")
		");

		// Get keywords
		$keywords = $wpdb->get_results("
			SELECT keywords.*, keywords_images.image_id
			FROM " . self::table_name('keywords_images') . " keywords_images
			INNER JOIN " . self::table_name('keywords') . " keywords
			ON keywords_images.keyword_id = keywords.keyword_id
			WHERE keywords_images.image_id IN (" . implode(',', $image_ids) . ")
		");

		$prepared_images = array();

		foreach ( $images as $image ) {

			if ( array_key_exists( $image->image_id , $prepared_images ) ) {

				continue;

			}

			$image->keywords = array();
			$image->albums = array();

			unset( $image->album_id );

			$prepared_images[ $image->image_id ] = $image;
		}

//		foreach ( $images as $image ) {
//
//			// Incorporate albums
//			if ( array_key_exists( $image->image_id, $prepared_images ) ) {
//
//				$prepared_images[ $image->image_id ]->albums[] = $image->album_id;
//
//				continue;
//
//			}
//
//			$image->keywords = array();
//
//			$image->albums = array( $image->album_id );
//
//			unset( $image->album_id );
//
//			$prepared_images[$image->image_id] = $image;
//
//		}

		// Incorporate keywords
		foreach ( $keywords as $keyword ) {

			$image_id = $keyword->image_id;

			unset( $keyword->image_id );

			$prepared_images[ $image_id ]->keywords[] = $keyword;

		}

		// Incorporate albums
		foreach ( $albums as $album ) {

			$image_id = $album->image_id;

			unset( $album->image_id );

			$prepared_images[ $image_id ]->albums[] = $album;

		}

		return $prepared_images;
	}

	/**
	 * Get all albums
	 *
	 * This is the ONE entry point for getting albums
	 * By default it returns synced, public albums
	 *
	 * TODO: 	add order_by and order options?
	 *
	 * Pass 'synced' => false to get all albums regardless of synced_time
	 * Pass 'status' => false to get all albums regardless of status
	 */
	static function get_albums( $args = array() ) {
		global $wpdb;

		$query_args = '';

		extract( wp_parse_args( $args, array(
			'synced' => true,
			'status' => 'published'
		) ) );

		if ( $synced ) {
			$query_args .= " WHERE synced_time != '0000-00-00 00:00:00'";
		}

		if ( $status ) {

			if ( $synced ) {
				$query_args .= " AND";
			} else {
				$query_args .= " WHERE";
			}

			$query_args .= " status = '" . $status . "'";
		}

		$albums_table = KokenSync::table_name('albums');

		$albums = $wpdb->get_results("
			SELECT *
			FROM " . $albums_table
			. $query_args
		);

		return $albums;
	}

	/**
	 * Get single album by id
	 *
	 * This is the one entry point for getting a single album
	 * By default it returns synced, published albums
	 */
	public function get_album( $args = array() ) {
		global $wpdb;

		$albums_table = KokenSync::table_name( 'albums' );

		$params = array();

		$defaults = array(
			'id' => null,
			'slug' => null,
			'synced' => true,
			'status' => 'published'
		);
		extract( wp_parse_args( $args, $defaults ) );

		// either $id or $slug must be present
		if ( !$id && !$slug ) {
			return false;
		}

		/**
		 * Build the query
		 */
		if ( $id ) {
			$query = "SELECT * FROM " . $albums_table . " WHERE album_id = %d";
			$params[] = $id;
		}

		if ( $slug ) {
			$query = "SELECT * FROM " . $albums_table . " WHERE slug = %s";
			$params[] = $slug;
		}

		if ( $synced ) {
			$query .= " AND synced_time != '0000-00-00 00:00:00'";
		}

		if ( $status ) {
			$query .= " AND status = %s";
			$params[] = $status;
		}

		$album = $wpdb->get_row($query, $params);

		return $album;
	}

	/**
	 * Get album images
	 */
	public function get_album_images( $album_id ) {
		global $wpdb;

		$images = array();
		$image_ids = array();

		$albums_images_table = KokenSync::table_name('albums_images');
		$images_table = KokenSync::table_name('images');
		$keywords_table = KokenSync::table_name('keywords');
		$keywords_images_table = KokenSync::table_name('keywords_images');

		// get album
		$album = KokenSync::get_album(array(
			'id' => $album_id
		));

		// check that album is published
		if ( $album->status !== 'published' ) {
			return false;
		}

		// get images
		$image_query = $wpdb->get_results( $wpdb->prepare("
			SELECT images.*
			FROM " . $albums_images_table . " albums_images
			INNER JOIN " . $images_table . " images
			ON albums_images.image_id = images.image_id
			WHERE albums_images.album_id = %d
		", $album_id ) );

		foreach ( $image_query as $image ) {
			$image_ids[] = $image->image_id;
		}

		// get keywords
		$keywords = $wpdb->get_results("
			SELECT keywords_images.image_id, keywords.slug, keywords.keyword
			FROM $keywords_images_table keywords_images
			INNER JOIN $keywords_table keywords
			ON keywords.keyword_id = keywords_images.keyword_id
		");

		$image_keywords = array();
		foreach ( $keywords as $keyword ) {
			$image_keywords[ $keyword->image_id ][] = array(
				'slug' => $keyword->slug,
				'keyword' => $keyword->keyword
			);
		}

		foreach ( $image_query as $image ) {
			$image->keywords = $image_keywords[ $image->image_id ];
			$images[] = $image;
		}

		return $images;
	}

	/**
	 * Get images by keywords
	 *
	 * keywords array should be slugs.
	 *
	 * TODO: allow searches for multiple categories using AND
	 */
	public function get_images_by_keywords( $args = array() ) {
		global $wpdb;

		$images_table = KokenSync::table_name('images');
		$keywords_table = KokenSync::table_name('keywords');
		$keywords_images_table = KokenSync::table_name('keywords_images');

		$defaults = array(
			'keywords' => array()
		);
		extract( wp_parse_args( $args, $defaults ) );

		// $keywords are required
		if ( empty( $keywords ) ) {
			return false;
		}

		// prepare keywords for query
		$keywords = "'" . join("','", $keywords) . "'";

		$images = $wpdb->get_results("
			SELECT DISTINCT images.*
			FROM $keywords_table keywords
			INNER JOIN $keywords_images_table keywords_images
			ON keywords.keyword_id = keywords_images.keyword_id
			INNER JOIN $images_table images
			ON keywords_images.image_id = images.image_id
			WHERE keywords.slug IN ($keywords)
		");

		return $images;
	}

	/**
	 * Get single image
	 *
	 * By id or slug
	 *
	 * TODO: add published check?
	 * Right now this gets the first image that matches
	 */
	public function get_image( $args = array() ) {
		global $wpdb;

		$images_table = KokenSync::table_name('images');

		$defaults = array(
			'id' => null,
			'slug' => null
		);
		extract( wp_parse_args( $args, $defaults ) );

		// either $id or $slug is required
		if ( !$id && !$slug ) {
			return false;
		}

		/**
		 * Build the query
		 */
		if ( $id ) {
			$query = "SELECT * FROM " . $images_table . " WHERE image_id = %d";
			$params[] = $id;
		}

		if ( $slug ) {
			$query = "SELECT * FROM " . $images_table . " WHERE slug = %s";
			$params[] = $slug;
		}

		$image = $wpdb->get_row($query, $params);

		$image->cache_path = unserialize( $image->cache_path );

		return $image;
	}

	/**
	 * Get image source
	 */
	static function get_image_src( $args = array() ) {

		$defaults = array(
			'image' => null,
			'width' => 300,
			'height' => 300,
			'quality' => 80,
			'sharpening' => 60,
			'resolution' => null
		);

		extract( wp_parse_args( $args, $defaults ) );

		$cache_path = unserialize( $image->cache_path );

		$prefix = $cache_path->prefix;
		$extension = $cache_path->extension;

		$url = $prefix . $width . '.' . $height . '.' . $quality . '.' . $sharpening;

		if ( isset( $resolution ) ) {
			$url .= '.' . $resolution;
		}

		$url .= '.' . $extension;

		return $url;
	}


}

/**
 * Initialize KokenSync class
 */
$koken_sync = new KokenSync();

endif; // class_exists check