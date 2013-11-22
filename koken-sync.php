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

	public $koken_path = 'http://expeditionaryart.elcontraption.com/koken';

	/**
	 * KokenSync constructor
	 */
	public function __construct() {

		// Define version constant
		define( 'KOKEN_SYNC_VERSION', $this->version );

		// Get plugin URL
		$this->plugin_url = plugins_url( '', __FILE__ ) . '/';

		$this->albums_table = $this->table_name( 'albums' );
		$this->images_table = $this->table_name( 'images' );
		$this->albums_images_table = $this->table_name( 'albums_images' );

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
			)
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
	function table_name($name)
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

		$albums_table = $this->albums_table;

		$koken = new Koken( $this->koken_path );
		$data = $koken->call('/albums');

		// exit if no albums
		if ( !isset( $data->albums ) ) {
			echo 'No albums were returned from Koken';
			die();
		}

		foreach ( $data->albums as $album ) {

			// keep track of synced albums
			$synced_albums[] = $album->id;

			// set up album fields
			$album_fields = array(
				'album_id' => $album->id,
				'time' => current_time( 'mysql' ),
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
			", null) );

		// clean up old albums
		$this->clean_up( $synced_albums );

		die();
	}

	/**
	 * Sync single album images from Koken
	 */
	function ajax_sync_album() {
		global $wpdb;

		// keep track of synced images
		$synced_images = array();

		// keep track of album data
		$image_data = array();
		$albums_images_data = array();

		$current_time = current_time( 'mysql' );

		$album_id = $_POST['albumID'];

		$albums_table = KokenSync::table_name( 'albums' );
		$images_table = KokenSync::table_name( 'images' );
		$albums_images_table = KokenSync::table_name( 'albums_images' );

		$koken = new Koken( $this->koken_path );
		$data = $koken->call('/albums/' . $album_id . '/content');

		// exit if no images
		if ( !isset( $data->content ) ) {
			echo 'No images were returned from Koken';
			die();
		}

		foreach ( $data->content as $image ) {

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

		// update album synced time
		$wpdb->update( $albums_table, array( 'synced_time' => $current_time ), array( 'album_id' => $album_id ) );

		// set up album/image relationships
		$albums_images_query = $wpdb->query( $wpdb->prepare("
			INSERT INTO " . $albums_images_table . " (" . implode(',', $albums_images_cols) . ") VALUES" . implode(',', $albums_images_data) . "
			", null) );
//
//		echo "
//			UPDATE " . $albums_table . "
//			SET status = '1'
//			WHERE album_id = '" . $album_id . "'
//		";
//		die();

		// clean up old albums
		//$this->clean_up( $synced_images );

		echo $albums_images_query;
		die();
	}

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
	 * Clean up
	 *
	 * Removes albums or images not included in $ids, after a sync
	 */
	function clean_up( $ids ) {
		global $wpdb;

		$ids = join( ',', $ids );

		$albums_table = $this->albums_table;

		// clean up albums
		$wpdb->query("
			DELETE FROM " . $albums_table . "
			WHERE album_id NOT IN ($ids)
		");

		// Clean up images
//		$wpdb->query("
//			DELETE FROM " . KokenSync::table_name('images') . "
//			WHERE album_id NOT IN ($ids)
//		");
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
	public function get_albums( $args ) {
		global $wpdb;

		$query_args = '';

		$defaults = array(
			'synced' => true,
			'status' => 'published'
		);
		extract( wp_parse_args( $args, $defaults ) );

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

		$albums = $wpdb->get_results( $wpdb->prepare("
			SELECT *
			FROM " . $albums_table
			. $query_args
		) );

		return $albums;
	}

	/**
	 * Get single album by id
	 *
	 * This is the one entry point for getting a single album
	 * By default it returns synced, published albums
	 */
	public function get_album( $args ) {
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

		$album = $wpdb->get_row( $wpdb->prepare($query, $params) );

		return $album;
	}

	/**
	 * Get album images
	 */
	public function get_album_images( $album_id ) {
		global $wpdb;

		$images = array();

		$albums_images_table = KokenSync::table_name('albums_images');
		$images_table = KokenSync::table_name('images');

		// get published albums
		$album = KokenSync::get_album(array(
			'id' => $album_id
		));

		// check that album is published
		if ( $album->status !== 'published' ) {
			return false;
		}

		$image_query = $wpdb->get_results( $wpdb->prepare("
			SELECT images.*
			FROM " . $albums_images_table . " albums_images
			INNER JOIN " . $images_table . " images
			ON albums_images.image_id = images.image_id
			WHERE albums_images.album_id = %d
		", $album_id ) );

		foreach ( $image_query as $image ) {
			$image->cache_path = unserialize( $image->cache_path );
			$images[] = $image;
		}

		return $images;
	}

	/**
	 * Get image
	 *
	 * By id or slug
	 *
	 * TODO: add published check?
	 */
	public function get_image( $args ) {
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

		$image = $wpdb->get_row( $wpdb->prepare( $query, $params ) );

		$image->cache_path = unserialize( $image->cache_path );

		return $image;
	}

	public function old_get_image_by_slug( $image_slug, $album_id ) {

		$cached_images = json_decode( get_transient( 'koken_sync_album_' . $album_id ) );

		// search $cached_images by $slug
		foreach ( $cached_images as $image ) {

			if ( $image->slug == $image_slug ) {
				return $image;
			}
		}

		return $image;
	}

	public function get_image_src( $args ) {

		$defaults = array(
			'image' => null,
			'width' => 300,
			'height' => 300,
			'quality' => 80,
			'sharpening' => 60,
			'resolution' => null
		);

		extract( wp_parse_args( $args, $defaults ) );

		$prefix = $image->cache_path->prefix;
		$extension = $image->cache_path->extension;

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