<?php
// http://alisothegeek.com/2011/01/wordpress-settings-api-tutorial-2/

class KokenSyncOptions {

	// Array of sections for the plugin options page
	private $sections;

	/**
	 * Initialize
	 */
	function __construct() {

		/**
		 * Set up settings
		 */
		$this->settings = array();
		$this->get_settings();

		$this->sections['settings'] = __('Settings');

		add_action( 'admin_menu', array( &$this, 'add_pages' ) );
		add_action( 'admin_init', array( &$this, 'register_settings' ) );
	}

	/**
	 * Add pages to the plugin menu
	 */
	public function add_pages() {
		add_menu_page('Koken Sync', 'Koken', 'manage_options', 'kokensync-options', array(&$this, 'display_albums_page'), null, 30);
		add_submenu_page('kokensync-options', 'Albums', 'Albums', 'manage_options', 'kokensync-options', array( &$this, 'display_albums_page' ) );
		add_submenu_page('kokensync-options', 'Settings', 'Settings', 'manage_options', 'kokensync-settings', array( &$this, 'display_settings_page' ) );
	}

	/**
	 * The albums page
	 */
	public function display_albums_page() {

		$albums_table = new KokenSyncAlbumsTable();
		$albums_table->prepare_items();
		?>
			<div class="wrap">
				<h2><?php echo __('Koken Sync') ?></h2>

				<form id="albums-filter">
					<input type="hidden" name="page" value="<?php echo $_REQUEST['page'] ?>" />
					<?php $albums_table->display() ?>
				</form>
			</div>
		<?php
	}

	/**
	 * Settings page
	 */
	public function display_settings_page() {
		?>
			<div class="wrap">
				<h2><?php echo __( 'Koken Sync' ) ?></h2>
				<form action="options.php" method="post">
					<?php settings_fields( 'koken_sync_options' ) ?>
					<?php do_settings_sections( 'kokensync-settings' ) ?>
					<p class="submit"><input type="submit" name="Submit" class="button-primary" value="<?php echo __('Save Changes') ?>" /></p>
				</form>
			</div>
			
		<?php
	}

	/**
	 * Define all settings and their defaults
	 */
	public function get_settings() {

		$this->settings['plugin_settings'] = array(
			'title' => __('Test setting'),
			'desc' => __('Just a test'),
			'section' => 'settings',
			'type' => 'textarea',
			'class' => 'large-text'
		);
	}

	/**
	 * Register settings
	 */
	public function register_settings() {

		register_setting('koken_sync_options', 'koken_sync_options', array( &$this, 'validate_settings' ));

		foreach ( $this->sections as $slug => $title ) {
			add_settings_section( $slug, $title, array( &$this, 'display_settings_section' ), 'kokensync-settings' );
		}

		$this->get_settings();

		foreach ( $this->settings as $id => $setting ) {
			$setting['id'] = $id;
			$this->create_setting( $setting );
		}
	}

	public function validate_settings($input) {

		$valid_input = get_option('smugmug_sync_options');

		// prepare test setting
		$valid_input['plugin_settings'] = $input['plugin_settings'] ? $input['plugin_settings'] : '';

		//// prepare keyword aliases
		//$prepared_keyword_aliases = $this->prepare_keyword_aliases($input['keyword_aliases']);
		//$valid_input['keyword_aliases'] = ( $prepared_keyword_aliases ) ? $prepared_keyword_aliases : '';
//
		//// prepare example keywords
		//$prepared_example_keywords = $this->prepare_example_keywords($input['example_keywords']);
		//$valid_input['example_keywords'] = ( $prepared_example_keywords ) ? $prepared_example_keywords : '';

		return $valid_input;
	}

	/**
	 * Create a settings field
	 */
	public function create_setting( $args = array() ) {

		$defaults = array(
			'id'      => 'default_field',
			'title'   => 'Default Field',
			'desc'    => 'This is a default description.',
			'std'     => '',
			'type'    => 'text',
			'section' => 'settings',
			'choices' => array(),
			'class'   => ''
		);

		extract( wp_parse_args( $args, $defaults ) );

		$field_args = array(
			'type'      => $type,
			'id'        => $id,
			'desc'      => $desc,
			'std'       => $std,
			'choices'   => $choices,
			'label_for' => $id,
			'class'     => $class
		);

		if ( $type == 'checkbox' ) {
			$this->checkbox[] = $id;
		}

		add_settings_field( $id, $title, array( $this, 'display_setting' ), 'kokensync-settings', $section, $field_args );
	}

	/**
	 * Display a setting
	 */
	public function display_setting( $args = array() ) {

		extract( $args );

		$options = get_option( 'smugmug_sync_options' );

		if ( unserialize($options['keyword_aliases']) ) {
			$options['keyword_aliases'] = $this->display_keyword_aliases($options['keyword_aliases']);
		}

		if ( unserialize($options['example_keywords']) ) {
			$options['example_keywords'] = $this->display_example_keywords($options['example_keywords']);
		}

		if ( !isset($options[$id]) && 'type' != 'checkbox' ) {

			$options[$id] = $std;

		} elseif ( !isset($options[$id]) ) {
			$options[$id] = 0;
		}

		$field_class = '';

		if ( $class != '' ) {
			$field_class = ' ' . $class;
		}

		switch( $type ) {

			case 'heading':
				echo '</td></tr><tr valign="top"><td colspan="2"><h4>' . $desc . '</h4>';
				break;

			case 'checkbox':
				echo '<input class="checkbox' . $field_class . '" type="checkbox" id="' . $id . '" name="smugmug_sync_options[' . $id . ']" value="1" ' . checked( $options[$id], 1, false ) . ' /><label for="' . $id . '">' . $desc . '</label>';
				break;

			case 'select':
				echo '<select class="select' . $field_class . '" name="smugmug_sync_options[' . $id . ']">';

				foreach ( $choices as $value => $label )
					echo '<option value="' . esc_attr( $value ) . '"' . selected( $options[$id], $value, false ) . '>' . $label . '</option>';

				echo '</select>';

				if ( $desc != '' )
					echo '<br /><span class="description">' . $desc . '</span>';
				break;

			case 'radio':
				$i = 0;
				foreach ( $choices as $value => $label ) {
					echo '<input class="radio' . $field_class . '" type="radio" name="smugmug_sync_options[' . $id . ']" id="' . $id . $i . '" value="' . esc_attr( $value ) . '" ' . checked( $options[$id], $value, false ) . '> <label for="' . $id . $i . '">' . $label . '</label>';
					if ( $i < count( $options ) - 1 )
						echo '<br />';
					$i++;
				}

				if ( $desc != '' )
					echo '<br /><span class="description">' . $desc . '</span>';
				break;

			case 'textarea':
				echo '<textarea class="' . $field_class . '" id="' . $id . '" name="smugmug_sync_options[' . $id . ']" placeholder="' . $std . '" rows="5" cols="30">' . wp_htmledit_pre( $options[$id] ) . '</textarea>';

				if ( $desc != '' )
					echo '<br /><span class="description">' . $desc . '</span>';
				break;

			case 'password':
				echo '<input class="regular-text' . $field_class . '" type="password" id="' . $id . '" name="smugmug_sync_options[' . $id . ']" value="' . esc_attr( $options[$id] ) . '" />';

				if ( $desc != '' )
					echo '<br /><span class="description">' . $desc . '</span>';
				break;

			case 'text':
			default:
				echo '<input class="regular-text' . $field_class . '" type="text" id="' . $id . '" name="smugmug_sync_options[' . $id . ']" placeholder="' . $std . '" value="' . esc_attr( $options[$id] ) . '" />';

		 		if ( $desc != '' )
		 			echo '<br /><span class="description">' . $desc . '</span>';
				break;

		} // switch( $type )

	}

}

new KokenSyncOptions();