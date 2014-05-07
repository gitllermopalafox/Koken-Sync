<div class="wrap">

	<div id="icon-options-general" class="icon32"></div>

	<h2><?php echo esc_html( get_admin_page_title() ); ?></h2>

	<form action="options.php" method="post">
		
		<?php settings_fields( 'koken_sync_admin_settings' ) ?>

		<?php do_settings_sections( 'koken_sync_admin_settings' ) ?>

		<?php submit_button() ?>

	</form>

</div>