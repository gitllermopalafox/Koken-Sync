<?php

/**
 * Require parent class
 */
if ( !class_exists('WP_List_Table') ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * KokenSyncAlbumsTable Class
 */
class KokenSyncAlbumsTable extends WP_List_Table 
{
	function __construct() 
    {
		parent::__construct( array(
			'singular' => 'kokensyncalbum',
			'plural' => 'kokensyncalbums'
		) );
	}

    function get_columns() 
    {
        $columns = array(
            'order' => __( 'Order' ),
            'title' => __( 'Album title' ),
            'images' => __( 'Images' ),
            'sync' => __( 'Sync' ),
            'status' => __( 'Status' )
        );
        return $columns;
    }

	function column_default( $item, $column_name ) 
    {
		switch ( $column_name ) {
			case 'modified_on':
				return $item->modified_on->datetime;
				break;
			default:
				return $item->$column_name;
		}
	}

	function extra_tablenav( $which ) 
    {
		if ( $which == 'top' ) {
			?>
				<button class="button button-primary" id="KokenSyncSyncAlbums">Refresh albums</button>
			<?php
		}
	}

	function prepare_items() 
    {
		$per_page = 20;
		$columns = $this->get_columns();
		$hidden = array();
		$sortable = $this->get_sortable_columns();

		$this->_column_headers = array( $columns, $hidden, $sortable );

		$data = KokenSync::get_albums(array(
			'synced' => false,
			'status' => false,
			'orderby' => 'album_order'
		));
 		
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

    function column_order($item)
    {
        ?>
            <div class="jquery-ui-sortable-handle">
                <div class="dashicons dashicons-menu" style="color: #bbb;"></div>
            </div>
        <?php
    }

    function column_title($item)
    {
        ?>
            <span class="row-title"><?php echo $item->title ?></span>
        <?php
    }

    function column_images($item)
    {
        echo $item->image_count;
    }

    function column_sync($item)
    {
        $synced = $item->synced_time == '0000-00-00 00:00:00' ? false : true;

        if ($item->image_count > 0) {
            echo '<button href="#" class="button button-primary KokenSyncButton KokenSyncAlbum" data-album-id="' . $item->album_id .'">Sync now</button>';

            if ($synced) {
                echo '<br /><small class="KokenSyncAlbumMessage">Synced: ' . $item->synced_time . '</small>';
            }

        } else {
            echo '<button disabled>No images</button>';
            echo '<br /><small>Add images via Koken</small>';
        }
    }

    function column_status($item)
    {
        $synced = $item->synced_time == '0000-00-00 00:00:00' ? false : true;
        ?>
            <select<?php if (!$synced) echo ' disabled' ?> class="KokenSyncStatusSelect" data-album-id="<?php echo $item->album_id ?>">
                <option value="unpublished"<?php if ($item->status == 'unpublished') echo ' selected' ?>>Unpublished</option>
                <option value="published"<?php if ($item->status == 'published') echo ' selected' ?>>Published</option>
                <!--<option value="protected"<?php if ($item->status == 'protected') echo ' selected' ?>>Protected</option>-->
            </select>
        <?php
    }

    function single_row( $item )
    {
        static $row_class = '';
        $row_class = ( $row_class == '' ? ' class="alternate"' : '' );

        echo '<tr' . $row_class . ' id="' . $item->album_id . '">';
        $this->single_row_columns( $item );
        echo '</tr>';
    }


}