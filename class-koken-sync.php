<?php

class KokenSync {

	/**
	 * Plugin version
	 *
	 * Used for cache-busting and script file references.
	 */
	const VERSION = '0.2.0';

	/**
	 * Koken URL
	 * TODO: make this a setting
	 */
	protected $koken_url = 'http://elcontraption.com/koken';

	/**
	 * Plugin slug
	 */
	protected $plugin_slug = 'koken-sync';

	/**
	 * Instance of this class
	 */
	protected static $instance = null;

	/**
	 * Initialize the plugin
	 */
	private function __construct() {

	}

	/**
	 * Return the plugin slug
	 */
	public function get_plugin_slug() {
		return $this->plugin_slug;
	}

	/**
	 * Get Koken URL
	 */
	public function get_koken_url() {
		return $this->koken_url;
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
	 * Plugin activation
	 */
	public static function activate() {
		self::add_tables();
	}

	/**
	 * Plugin deactivation
	 */
	public static function deactivate() {
		self::remove_tables();
	}

	/**
	 * Add tables (on activation)
	 */
	private static function add_tables() {

		global $wpdb;

		$tables = array(
			array(
				'name' => self::table_name('albums'),
				'query' => "
					CREATE TABLE " . self::table_name('albums') . " (
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
				'name' => self::table_name('images'),
				'query' => "
					CREATE TABLE " . self::table_name('images') . " (
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
				'name' => self::table_name('albums_images'),
				'query' => "
					CREATE TABLE " . self::table_name('albums_images') . " (
					album_id bigint NOT NULL,
					image_id bigint NOT NULL,
					PRIMARY KEY  (album_id,image_id)
				);"
			),
			array(
				'name' => self::table_name('keywords'),
				'query' => "
					CREATE TABLE " . self::table_name('keywords') . " (
					id mediumint(9) NOT NULL AUTO_INCREMENT,
					keyword_id BINARY(16) NOT NULL,
					slug VARCHAR(255) NOT NULL,
					keyword tinytext NOT NULL,
					UNIQUE KEY id (id),
					UNIQUE KEY (keyword_id)
				);"
			),
			array(
				'name' => self::table_name('keywords_images'),
				'query' => "
					CREATE TABLE " . self::table_name('keywords_images') . " (
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
	 * Remove tables (on deactivation)
	 */
	private static function remove_tables() {

		global $wpdb;

		$tables = array('albums', 'images', 'albums_images', 'keywords', 'keywords_images');

		// Run query
		foreach ( $tables as $table ) {
			$wpdb->query("DROP TABLE IF EXISTS " . self::table_name( $table ));
		}
	}

	/**
	 * Get a table name
	 */
	public static function table_name( $name ) {

		global $wpdb;

		return $wpdb->prefix . 'koken_sync_' . $name;
	}

	/**
	 * Get albums
	 */
	public static function get_albums( $args = array() ) {

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

		$albums_table = self::table_name('albums');

		$albums = $wpdb->get_results("
			SELECT *
			FROM " . $albums_table
			. $query_args
		);

		return $albums;
	}

	/**
	 * Get images with keywords and albums
	 */
	public static function get_images( $args = array() ) {

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