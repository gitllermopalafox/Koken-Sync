<div class="wrap">

	<h2><?php echo esc_html( get_admin_page_title() ); ?></h2>

	<form id="koken-albums-table" action="">
		<input type="hidden" name="page" value="<?php echo $_REQUEST['page'] ?>" />
		<?php $albums_table->display(); ?>
	</form>

</div>