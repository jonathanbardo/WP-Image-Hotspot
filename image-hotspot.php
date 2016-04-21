<?php
/**
 * Plugin Name: Image Hotspot
 * Description: Set hotspot point on your uploaded image and crop accordingly.
 * Author: Jonathan Bardo
 * Version: 0.1.0
 * Text Domain: image-hotspot
 * Domain Path: /languages
 */

/*
 Thanks to Robert O'Rourke from interconnect/it for the inspiration and the base of this plugin!
 http://interconnectit.com
*/

defined( 'IMAGE_HOTSPOT_PATH' ) or define( 'IMAGE_HOTSPOT_PATH', plugin_dir_path( __FILE__ ) );
defined( 'IMAGE_HOTSPOT_URL' ) or define( 'IMAGE_HOTSPOT_URL',  plugins_url( '', __FILE__ ) );

// track attachment being modified
add_action( 'plugins_loaded', array( 'WP_Image_Hotspot', 'instance' ) );

class WP_Image_Hotspot {

	/**
	 * @var int|null Reference to currently edited attachment post
	 */
	protected static $attachment_id;

	/**
	 * @var placeholder for current faces array
	 */
	protected $hotspots = array();

	/**
	 * Holds our meta key reference
	 */
	const META_KEY = 'hotspots';

	/**
	 * Reusable object instance.
	 *
	 * @type object
	 */
	protected static $instance = null;

	/**
	 * Creates a new instance. Called on 'after_setup_theme'.
	 * May be used to access class methods from outside.
	 *
	 * @see    __construct()
	 * @return void
	 */
	public static function instance() {
		null === self::$instance AND self::$instance = new self;
		return self::$instance;
	}


	private function __construct() {
		add_action( 'init', array( $this, 'init' ) );
	}

	public function init() {
		// get current attachment ID
		add_filter( 'get_attached_file',               array( $this, 'set_attachment_id' ), 10, 2 );
		add_filter( 'update_attached_file',            array( $this, 'set_attachment_id' ), 10, 2 );

		// image resize dimensions
		add_filter( 'image_resize_dimensions',         array( $this, 'crop' ), 10, 6 );

		// javascript
		add_action( 'admin_enqueue_scripts',           array( $this, 'admin_scripts' ) );

		// save hotspot
		add_action( 'wp_ajax_hotspot_save',            array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_hotspot_delete',          array( $this, 'ajax_delete' ) );
		add_filter( 'wp_prepare_attachment_for_js',    array( $this, 'add_hotspot_info' ), 10, 3 );
	}


	/**
	 * Function that deals with enqueuing our custom script and also helps printing a pointer for help
	 */
	public function admin_scripts() {
		// @todo limits when we enqueue everything

		$dismissed  = explode( ',', (string) get_user_meta( get_current_user_id(), 'dismissed_wp_pointers', true ) );
		$pointer_id = 'hotspot_admin_pointers_1_0_explications';

		$script_args = array(
			'ajax_url' 		 => admin_url( '/admin-ajax.php' ),
			'hotspot_nonce'  => wp_create_nonce( 'hotspot_nonce' ),
			'btn_title'      => esc_html__( 'Add image hotspot', 'image-hotspot' ),
			'pointer'        => false,
		);

		if ( ! in_array( $pointer_id, $dismissed ) ) {
			$new_pointer_content  = '<h3>' . __( 'Add a hotspot', 'image-hotspot' ) . '</h3>';
			$new_pointer_content .= '<p>' . __( 'Easily add an hotspot point by clicking a point of interest on the image. When cropping, the point you selected will stay visible.', 'image-hotspot' ) . '</p>';

			$script_args['pointer'] = array(
				'content'   => $new_pointer_content,
				'active'    => 'true',
				'position'  => array(
					'edge'  => 'top',
					'align' => 'center',
					'my'    => 'left-45px top',
					'at'    => 'left bottom',
				),
			);

			$script_args['pointer_id'] = $pointer_id;

			wp_enqueue_style( 'wp-pointer' );
			wp_enqueue_script( 'wp-pointer' );
		}

		$suffix = SCRIPT_DEBUG ? '' : '.min';

		wp_enqueue_script( 'image_hotspot', IMAGE_HOTSPOT_URL . "/js/image-hotspot{$suffix}.js", array( 'image-edit' ), '0.2', true );
		wp_localize_script( 'image_hotspot', 'imagehotspot', $script_args );

		// stylesheet
		wp_enqueue_style( 'image_hotspot', IMAGE_HOTSPOT_URL . "/css/image-hotspot{$suffix}.css" );
	}

	/**
	 * Return information about the hotspot
	 * @param $response
	 * @param $attachment
	 * @param $meta
	 * @return
	 */
	public function add_hotspot_info( $response, $attachment, $meta ) {
		if ( wp_attachment_is_image( $attachment ) ) {
			$response['hotspot'] = get_post_meta( $attachment->ID, 'hotspots', true );
		}

		return $response;
	}

	public function ajax_save() {
		check_ajax_referer( 'hotspot_nonce', 'hotspot_nonce' );

		$response = array();
		$att_id   = isset( $_POST[ 'attachment_id' ] ) ? intval( $_POST[ 'attachment_id' ] ) : false;

		// hotspots
		if ( isset( $_POST[ 'hotspots' ] ) ) {
			if ( $_POST['hotspots'] ) {
				// Make sure we have solid data
				array_filter( $_POST['hotspots'], function( $val ) {
					$keys_allowed = array(
						'x',
						'y',
					);

					$val = array_intersect_key( $val, array_flip( $keys_allowed ) );

					if (
						isset( $val[ 'x' ], $val[ 'y' ] )
						&& array_filter( $val, 'is_int' )
					) {
						return $val;
					} else {
						return false;
					}
				} );

				update_post_meta( $att_id, self::META_KEY, $_POST['hotspots'] );
			} else {
				delete_post_meta( $att_id, self::META_KEY );
			}
		}

		// regenerate thumbs
		$resized = $this->regenerate_thumbs( $att_id );

		if ( ! empty( $resized ) ) {
			$response[ 'resized' ] = $resized;
			$response[ 'msg' ]     = esc_html__( 'Hotspot successfully updated.', 'image-hotspot' );
			wp_send_json_success( $response );
		} else {
			$response[ 'msg' ]     = esc_html__( 'There was an error updating the hotspot.', 'image-hotspot' );
			wp_send_json_error( $response );
		}
	}

	/**
	 * Delete hotspot information because image changed
	 */
	public function ajax_delete() {
		check_ajax_referer( 'hotspot_nonce', 'hotspot_nonce' );

		$att_id = isset( $_POST[ 'attachment_id' ] ) ? intval( $_POST[ 'attachment_id' ] ) : false;

		if ( $att_id ) {
			delete_post_meta( $att_id, self::META_KEY );
			wp_send_json_success();
		} else {
			wp_send_json_error();
		}
	}

	public function get_cropped_sizes() {
		global $_wp_additional_image_sizes;

		$sizes      = array();
		$size_names = get_intermediate_image_sizes();

		foreach ( $size_names as $size ) {
			if ( in_array( $size, array( 'thumbnail', 'medium', 'large' ) ) ) {
				$width  = intval( get_option( $size . '_size_w' ) );
				$height = intval( get_option( $size . '_size_h' ) );
				$crop 	= get_option( $size . '_crop' );
			} else {
				$width  = $_wp_additional_image_sizes[ $size ][ 'width' ];
				$height = $_wp_additional_image_sizes[ $size ][ 'height' ];
				$crop  	= $_wp_additional_image_sizes[ $size ][ 'crop' ];
			}

			if ( $crop ) {
				$sizes[ $size ] = array(
					'width'  => $width,
					'height' => $height,
					'crop'   => $crop,
				);
			}
		}

		return $sizes;
	}


	public function regenerate_thumbs( $attachment_id ) {

		// this sets up the faces & hotspots arrays
		$file = get_attached_file( $attachment_id );

		// 5 minutes per image should be PLENTY
		@set_time_limit( 5 * MINUTE_IN_SECONDS );

		// resize thumbs
		$metadata = wp_generate_attachment_metadata( $attachment_id, $file );

		if ( is_wp_error( $metadata ) ) {
			return array( 'id' => $attachment_id, 'error' => $metadata->get_error_message() );
		}

		if ( empty( $metadata ) ) {
			return array( 'id' => $attachment_id, 'error' => __( 'Unknown failure reason.', 'image-hotspot' ) );
		}

		// If this fails, then it just means that nothing was changed (old value == new value)
		wp_update_attachment_metadata( $attachment_id, $metadata );

		$sizes   = $this->get_cropped_sizes();
		$resized = array();

		foreach ( $sizes as $size => $atts ) {
			$resized[ $size ] = wp_get_attachment_image_src( $attachment_id, $size );
		}

		return $resized;
	}

	/**
	 * Alters the crop location of the GD image editor class by detecting faces
	 * and centering the crop around them
	 *
	 * @param array $output The parameters for imagecopyresampled()
	 * @param int $orig_w Original width
	 * @param int $orig_h Original Height
	 * @param int $dest_w Target width
	 * @param int $dest_h Target height
	 * @param bool $crop Whether to crop image or not
	 *
	 * @return array
	 */
	public function crop( $output, $orig_w, $orig_h, $dest_w, $dest_h, $crop ) {

		if ( ! $crop || empty( $this->hotspots ) ) {
			return $output;
		}

		if ( is_array( $output ) ) {
			list( $dest_x, $dest_y, $src_x, $src_y, $new_w, $new_h, $src_w, $src_h ) = $output;
		}

		$hotspot_src_x     = $hotspot_src_y     = PHP_INT_MAX;
		$hotspot_src_max_x = $hotspot_src_max_w = 0;
		$hotspot_src_max_y = $hotspot_src_max_h = 0;

		// create bounding box
		foreach ( $this->hotspots as $hotspot ) {
			$hotspot = array_map( 'absint', $hotspot );

			// left and top most x,y
			if ( $hotspot_src_x > $hotspot['x'] ) {
				$hotspot_src_x = $hotspot['x'];
			}

			if ( $hotspot_src_y > $hotspot['y'] ) {
				$hotspot_src_y = $hotspot['y'];
			}

			// right and bottom most x,y
			if ( $hotspot_src_max_x < $hotspot['x'] ) {
				$hotspot_src_max_x = $hotspot['x'];
			}

			if ( $hotspot_src_max_y < $hotspot['y'] ) {
				$hotspot_src_max_y = $hotspot['y'];
			}
		}

		$hotspot_src_w = $hotspot_src_max_x - $hotspot_src_x;
		$hotspot_src_h = $hotspot_src_max_y - $hotspot_src_y;

		// crop the largest possible portion of the original image that we can size to $dest_w x $dest_h
		$aspect_ratio = $orig_w / $orig_h;

		// preserve settings already filtered in
		if ( null === $output ) {
			$new_w = min( $dest_w, $orig_w );
			$new_h = min( $dest_h, $orig_h );

			if ( ! $new_w ) {
				$new_w = intval( $new_h * $aspect_ratio );
			}

			if ( ! $new_h ) {
				$new_h = intval( $new_w / $aspect_ratio );
			}
		}

		$size_ratio = max( $new_w / $orig_w, $new_h / $orig_h );

		$crop_w = round( $new_w / $size_ratio );
		$crop_h = round( $new_h / $size_ratio );

		$src_x = floor( ( $orig_w - $crop_w ) / 2 );
		$src_y = floor( ( $orig_h - $crop_h ) / 2 );

		// bounding box
		if ( 0 == $src_x ) {
			$src_y = ( $hotspot_src_y + ( $hotspot_src_h / 2 ) ) - ( $crop_h / 2 );
			$src_y = min( max( 0, $src_y ), $orig_h - $crop_h );
		}

		if ( 0 == $src_y ) {
			$src_x = ( $hotspot_src_x + ( $hotspot_src_w / 2 ) ) - ( $crop_w / 2 );
			$src_x = min( max( 0, $src_x ), $orig_w - $crop_w );
		}

		// the return array matches the parameters to imagecopyresampled()
		// int dst_x, int dst_y, int src_x, int src_y, int dst_w, int dst_h, int src_w, int src_h
		return array( 0, 0, (int) $src_x, (int) $src_y, (int) $new_w, (int) $new_h, (int) $crop_w, (int) $crop_h );
	}

	/**
	 * Hacky use of attached_file filters to get current attachment ID being resized
	 * Used to store face location and dimensions
	 *
	 * @param string $file          File path
	 * @param int $attachment_id Attachment ID
	 *
	 * @return string    The file path
	 */
	public function set_attachment_id( $file, $attachment_id ) {
		self::$attachment_id = $attachment_id;

		$hotspots = get_post_meta( $attachment_id, self::META_KEY, true );

		if ( ! empty( $hotspots ) ) {
			$this->hotspots = $hotspots;
		}

		return $file;
	}

}
