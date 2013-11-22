<?php
if (!class_exists('WP_List_Table')) {
	require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

/**
 * KokenSyncAlbumsTable Class
 */
class KokenSyncAlbumsTable extends WP_List_Table {

	function __construct() {

		parent::__construct( array(
			'singular' => 'kokensyncalbum',
			'plural' => 'kokensyncalbums'
		) );
	}

	function column_default($item, $column_name) {

		switch ( $column_name ) {
			case 'modified_on':
				return $item->modified_on->datetime;
				break;
			default:
				return $item->$column_name;
		}
	}

	function get_columns() {
		$columns = array(
			'title' => __( 'Album title' ),
			'images' => __( 'Images' ),
			'sync' => __( 'Sync' ),
			'status' => __( 'Status' )
		);
		return $columns;
	}

	//function get_sortable_columns() {
	//	$sortable_columns = array(
	//		'title' => array('title', false),
	//	);
	//	return $sortable_columns;
	//}

	function extra_tablenav($which) {

		if ( $which == 'top' ) {
			?>
				<form action="" method="post">
					<p class="submit" style="display: inline;">
						<input type="submit" id="KokenSyncSyncAlbums" value="Refresh albums" />
					</p>
				</form>
			<?
		}
	}

	function prepare_items() {
		$per_page = 20;
		$columns = $this->get_columns();
		$hidden = array();
		$sortable = $this->get_sortable_columns();

		$this->_column_headers = array( $columns, $hidden, $sortable );

		$data = KokenSync::get_albums(array(
			'synced' => false,
			'status' => false
		));

//		function usort_reorder( $a, $b ) {
//
//			// if no sort, default to title
//			$orderby = ( ! empty( $_REQUEST['orderby'] ) ) ? $_REQUEST['orderby'] : 'last_updated';
//
//			// if no order, default to DESC
//			$order = ( ! empty( $_REQUEST['order'] ) ) ? $_REQUEST['order'] : 'desc';
//
//			// determine sort order 
//			$result = strcmp( $a[ $orderby ], $b[ $orderby ] );
//
//			// send final sort direction to usort
//			return ( $order === 'asc' ) ? $result : -$result;
 //		}
 //		usort( $data, 'usort_reorder' );	
 		
 		$current_page = $this->get_pagenum();

 		$total_items = count( $data );

 		$data = array_slice( $data, ( ( $current_page - 1 ) * $per_page ), $per_page );

 		$this->items = $data;

 		$this->set_pagination_args( array(
 			'total_items' => $total_items,
 			'per_page' => $per_page,
 			'total_pages' => ceil( $total_items / $per_page )
 		) );
	}

	//function display_rows() {
		//$albums = $this
	//}

	function display_rows() {
		$albums = $this->items;

		list( $columns, $hidden ) = $this->get_column_info();

		if ( !empty( $albums ) ) {

			foreach ( $albums as $album ) {

				$synced = $album->synced_time == '0000-00-00 00:00:00' ? false : true;
				$published = $album->status == 'published' ? true : false;

				echo '<tr data-album-id="' . $album->album_id . '">';

				foreach ( $columns as $column_name => $column_display_name ) {

					$class = "class='$column_name column-$column_name'";
					$style = "";
					if (in_array($column_name, $hidden)) {
						$style = '"display: none;"';
					}
					$attributes = $class . $style;

					switch ( $column_name ) {
						case 'title':
							?>
								<td <?php echo $attributes ?>>
									<?php if ( $synced && $published ) : ?>
										<a target="_new" href="<?php echo home_url() ?>/portfolio/<?php echo $album->slug ?>/"><?php echo $album->title ?></a>
									<?php else : ?>
										<?php echo $album->title ?>
									<?php endif ?>
								</td>
							<?php
							break;
						case 'images':
							?>
								<td <?php echo $attributes ?>>
									<?php echo $album->image_count ?>
								</td>
							<?php
							break;
						case 'sync':
							?>
								<td <?php echo $attributes ?>>
									<?php if ( $album->image_count > 0 ) : ?>
										<button href="#" class="KokenSyncButton KokenSyncAlbum">Sync now</button>
		
										<?php if ( $synced ) : ?>
											<br /><small class="KokenSyncAlbumMessage">Synced: <?php echo $album->synced_time ?></small>
										<?php else : ?>
											<br /><small class="KokenSyncAlbumMessage">Never synced</small>
										<?php endif ?>

									<?php else : ?>
										<button disabled>No images</button>
										<br /><small>Add images via Koken</small>
									<?php endif ?>
								</td>
							<?php
							break;
						case 'status':
							?>
								<td <?php echo $attributes ?>>

									<select<?php if ( !$synced ) echo ' disabled' ?> class="KokenSyncStatusSelect">
										<option value="unpublished"<?php if ( $album->status == 'unpublished' ) echo ' selected' ?>>Unpublished</option>
										<option value="published"<?php if ( $album->status == 'published' ) echo ' selected' ?>>Published</option>
										<!--<option value="protected"<?php if ( $album->status == 'protected' ) echo ' selected' ?>>Protected</option>-->
									</select>

								</td>
							<?php
							break;
					}

				}

				echo '</tr>';

			}

		}
	}


}