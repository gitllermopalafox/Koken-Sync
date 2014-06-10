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
	 */
	protected $koken_url = null;

	/**
	 * Plugin slug
	 */
	protected $plugin_slug = 'koken-sync';

	/**
	 * Plugin path
	 */
	protected $plugin_path;

	/**
	 * Instance of this class
	 */
	protected static $instance = null;

	/**
	 * Initialize the plugin
	 */
	private function __construct() {

		$this->koken_url = get_option( 'koken_url' );

		$this->plugin_path = plugin_dir_path( __FILE__ );

	}

	/**
	 * Return the plugin slug
	 */
	public function get_plugin_slug() {
		return $this->plugin_slug;
	}

	/**
	 * Return the plugin path
	 */
	public function get_plugin_path() {
		return $this->plugin_path;
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
					album_order mediumint(9) NOT NULL,
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

		extract( wp_parse_args( $args, array(
			'synced' => true,
			'status' => 'published',
			'orderby' => 'album_order',
			'order' => 'ASC'
		) ) );

		$query = "SELECT * FROM " . self::table_name('albums');
		$params = array();

		// Convenience WHERE so we can use ANDs below
		$query .= " WHERE id > 0";

		if ( $synced ) {
			$query .= " AND synced_time != '0000-00-00 00:00:00'";
		}

		if ( $status ) {
			$query .= " AND status = %s";
			$params[] = $status;
		}

		if ( $orderby ) {
			$query .= " ORDER BY " . $orderby . " " . $order;
		}

		if ( ! empty( $params ) ) {
			$query = $wpdb->prepare( $query, implode(',', $params) );
		}

		return $wpdb->get_results( $query );
	}

	/**
	 * Get images
	 */
	public static function get_images( $args = array() ) {

		global $wpdb;

		extract( wp_parse_args( $args, array(
			'orderby' => 'image_id',
			'order' => 'ASC',

			// Get images from specific album
			'album_id' => NULL,
			'album_slug' => NULL,

			// Get specific images
			'image_id' => NULL,
			'image_slug' => NULL,

			// Eager load associations: array('albums', 'keywords')
			'load' => array()
		) ) );

		$query = "
			SELECT DISTINCT images.* 
			FROM " . self::table_name('images') . " images
			INNER JOIN " . self::table_name('albums_images') . " albums_images
			ON images.image_id = albums_images.image_id
			INNER JOIN " . self::table_name('albums') . " albums
			ON albums_images.album_id = albums.album_id
			WHERE albums.status = 'published'
		";
		$params = array();

		if ( $album_id ) {
			$query .= " AND albums.album_id = %d";
			$params[] = $album_id;
		}

		if ( ! $album_id && $album_slug ) {
			$query .= " AND albums.slug = %s";
			$params[] = $album_slug;
		}

//		if ( $image_id ) {
//			$query .= " AND images.image_id = %d";
//			$params[] = $image_id;
//		}
//
//		if ( ! $image_id && $image_slug ) {
//			$query .= " AND images.image_slug = %s";
//			$params[] = $image_slug;
//		}

		if ( ! empty( $params ) ) {
			$query = $wpdb->prepare( $query, implode(',', $params) );
		}

		// Run image query
		$images = $wpdb->get_results( $query );

		if ( empty( $images ) ) {
			return;
		}

		// Eager load associations?
		if ( ! empty( $load ) ) {
			$images = self::load_image_associations( $images, $load );
		}

		return $images;
	}

	/**
	 * Load image associations
	 */
	protected static function load_image_associations( $images, $associations ) {

		global $wpdb;

		$image_ids = array();
		$results = array();
		$collected_images = array();

		foreach ( $images as $image ) {
			$image_ids[] = $image->image_id;
			$collected_images[ $image->image_id ] = $image;
		}

		// Run queries for each association
		foreach ( $associations as $type ) {

			$singular_type = rtrim( $type, 's' );

			$results[ $type ] = $wpdb->get_results("
				SELECT " . $type . ".*, " . $type . "_images.image_id
				FROM " . self::table_name( $type . '_images' ) . " " . $type . "_images 
				INNER JOIN " . self::table_name( $type ) . " " . $type . " 
				ON " . $type . "_images." . $singular_type . "_id = " . $type . "." . $singular_type . "_id 
				WHERE " . $type . "_images.image_id IN (" . implode( ',', $image_ids ) . ")
			");
		}

		if ( empty( $results ) ) {
			return;
		}

		foreach ( $results as $type => $values ) {

			foreach ( $values as $value ) {

				$collected_images[ $value->image_id ]->{$type}[] = $value;

			}

		}

		return array_values( $collected_images );
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

    /**
     * Returns keywords
     */
    static function get_keywords()
    {
        
    }

}