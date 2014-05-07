<?php

/**
 * KokenSyncSettings
 *
 * Handles WP settings.
 */
class KokenSyncSettings {

	/**
	 * Instance of this class
	 */
	protected static $instance = null;

	/**
	 * Parent page name
	 */
	private $parent_page_name = 'koken_sync_admin';

	/**
	 * Settings page name
	 */
	private $settings_page_name = 'koken_sync_admin_settings';

	/**
	 * Intialization
	 */
	private function __construct() {

		$this->koken_sync = KokenSync::get_instance();
		$this->plugin_slug = $this->koken_sync->get_plugin_slug();

		/**
		 * Add submenu page
		 * Must be registered after the main menu page
		 */
		add_action( 'admin_menu', array( $this, 'add_submenu_page' ), 99 );

		/**
		 * Add settings section
		 */
		add_action( 'admin_init', array( $this, 'admin_init' ) );
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
	 * Add submenu page
	 */
	public function add_submenu_page() {

		add_submenu_page(
			$this->parent_page_name,
			'Koken Sync Settings',
			'Settings',
			'manage_options',
			$this->settings_page_name,
			array( $this, 'display_submenu_page' )
		);

	}

	/**
	 * Display submenu page
	 */
	public function display_submenu_page() {

		include_once( 'views/settings.php' );

	}

	/**
	 * Add settings section
	 */
	public function admin_init() {

		/**
		 * Add the general settings section
		 */
		add_settings_section(

			// ID to identify this section and register options
			'koken_sync_general_settings_section',

			// Title displayed on administration page
			'General settings',

			// Callback used to render the description of the section
			array( $this, 'general_settings_section_description' ),

			// Page on which to add this section of options
			$this->settings_page_name
		);

		/**
		 * Add Koken URL field
		 */
		add_settings_field(

			// ID to identify the field
			'koken_url',

			// Label to the left of the option
			'Koken URL',

			// Function to render the option
			array( $this, 'display_koken_url_field' ),

			// Page on which to display this field
			$this->settings_page_name,

			// Section in which to display this field
			'koken_sync_general_settings_section'
	
		);

		/**
		 * Register Koken URL setting
		 */
		register_setting(
			$this->settings_page_name,
			'koken_url'
		);
		
	}

	/**
	 * Description for the general settings section
	 */
	public function general_settings_section_description() {
		echo '<p>General settings for Koken Sync.</p>';
	}

	/**
	 * Display Koken URL field
	 */
	public function display_koken_url_field() {

		$koken_url = get_option('koken_url');

		?>

			<input type="text" id="koken_url" name="koken_url" value="<?php echo $koken_url ?>">

			<label for="koken_url">Your Koken application URL (no trailing slash).</label>

		<?php

	}

}