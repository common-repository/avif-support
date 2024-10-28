<?php
namespace GPLSCore\GPLS_PLUGIN_AVFSTW;

use GPLSCore\GPLS_PLUGIN_AVFSTW\AJAXs\SettingsAJAX;
use GPLSCore\GPLS_PLUGIN_AVFSTW\Pages\SettingsPage;
use GPLSCore\GPLS_PLUGIN_AVFSTW\Utils\Img\ImgUtilsTrait;

/**
 * SVG Support Class.
 */
class SVGSupport extends Base {

	use ImgUtilsTrait;

	/**
	 * Singleton Instance.
	 *
	 * @var self
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->setup();
		$this->hooks();
	}

	/**
	 * Singleton Instance Init.
	 *
	 * @return self
	 */
	public static function init() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Setup.
	 *
	 * @return void
	 */
	private function setup() {
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	private function hooks() {
		add_filter( 'getimagesize_mimes_to_exts', array( $this, 'filter_mime_to_exts' ), PHP_INT_MAX, 1 );
		add_filter( 'mime_types', array( $this, 'filter_mime_types' ), PHP_INT_MAX, 1 );
		add_filter( 'upload_mimes', array( $this, 'filter_allowed_mimes' ), PHP_INT_MAX, 1 );
		add_filter( 'file_is_displayable_image', array( $this, 'fix_svg_displayable' ), PHP_INT_MAX, 2 );
		add_filter( 'wp_check_filetype_and_ext', array( $this, 'handle_exif_andfileinfo_fail' ), PHP_INT_MAX, 5 );
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'fix_svg_images' ), 1, 3 );
	}

	/**
	 * Fix Avif Image Support.
	 *
	 * @param array  $metadata
	 * @param int    $attachment_id
	 * @param string $context
	 * @return array
	 */
	public function fix_svg_images( $metadata, $attachment_id, $context ) {
        if ( ! AvifSupport::is_svg_support_enabled() ) {
            return $metadata;
        }

        if ( 'update' === $context ) {
            return $metadata;
        }

		// If it's empty, It's already failed.
		if ( empty( $metadata ) ) {
			return $metadata;
		}

		$attachemnt_post = get_post( $attachment_id );
		if ( ! $attachemnt_post || is_wp_error( $attachemnt_post ) ) {
			return $metadata;
		}

		if ( 'image/svg+xml' !== $attachemnt_post->post_mime_type ) {
			return $metadata;
		}


		$file = get_attached_file( $attachment_id );

		// Fix svg dimensions.
		$dims = $this->get_svg_dimensions( $file );
		if ( is_array( $dims ) ) {
			$metadata['width']  = $dims['width'];
			$metadata['height'] = $dims['height'];
			$new_sizes          = wp_get_registered_image_subsizes();
			$new_sizes          = apply_filters( 'intermediate_image_sizes_advanced', $new_sizes, $metadata, $attachment_id );
			$new_metadata       = _wp_make_subsizes( $new_sizes, $file, $metadata, $attachment_id );
		}

		return $new_metadata;
	}

	/**
	 * Filter Mime to Ext.
	 *
	 * @param array $mime_to_exsts
	 *
	 * @return array
	 */
	public function filter_mime_to_exts( $mime_to_exsts ) {
        if ( ! AvifSupport::is_svg_support_enabled() ) {
            return $mime_to_exsts;
        }
		$mime_to_exsts['image/svg+xml'] = 'svg';
		return $mime_to_exsts;
	}

	/**
	 * Filter Mimes.
	 *
	 * @param array $mimes
	 * @return array
	 */
	public function filter_mime_types( $mimes ) {
        if ( ! AvifSupport::is_svg_support_enabled() ) {
            return $mimes;
        }
		$mimes['svg'] = 'image/svg+xml';
		return $mimes;
	}

	/**
	 * Filter Allowed Mimes.
	 *
	 * @param array    $mimes
	 * @return array
	 */
	public function filter_allowed_mimes( $mimes ) {
        if ( ! AvifSupport::is_svg_support_enabled() ) {
            return $mimes;
        }
		$mimes['svg'] = 'image/svg+xml';
		return $mimes;
	}

	/**
	 * Fix svg Displayable Image.
	 *
	 * @param boolean $result
	 * @param string  $path
	 * @return boolean
	 */
	public function fix_svg_displayable( $result, $path ) {
        if ( ! AvifSupport::is_svg_support_enabled() ) {
            return $result;
        }
		if ( str_ends_with( $path, '.svg' ) ) {
			return true;
		}

		return $result;
	}

	/**
	 * Handle the fail of exif and fileinfo to detect SVG [ Rare scenario - Trash Hostings ].
	 *
	 * @param array        $filename_and_type_arr
	 * @param string       $file_path
	 * @param string       $filename
	 * @param array        $mimes
	 * @param string|false $real_mime
	 * @return array
	 */
	public function handle_exif_andfileinfo_fail( $filename_and_type_arr, $file_path, $filename, $mimes, $real_mime ) {
        if ( ! AvifSupport::is_svg_support_enabled() ) {
            return $filename_and_type_arr;
        }
		// ext and type found? proceed.
		if ( $filename_and_type_arr['ext'] && $filename_and_type_arr['type'] ) {
			return $filename_and_type_arr;
		}

		// // Not SVG, return.
		if ( ! str_ends_with( $filename, '.svg' ) ) {
			return $filename_and_type_arr;
		}

		// Valid svg.
		if ( self::get_svg_dimensions( $file_path ) ) {
			$filename_and_type_arr['type'] = 'image/svg+xml';
			$filename_and_type_arr['ext']  = 'svg';
		}

		return $filename_and_type_arr;
	}

	/**
	 * Get SVG Dimensions.
	 *
	 * @param string $svg_path
	 * @return false|float[]
	 */
	public function get_svg_dimensions( $file_path ) {
		// Load the SVG file content.
		$svg_content = file_get_contents( $file_path );

		// Create a new DOMDocument and load the SVG content.
		$dom = new \DOMDocument();
		libxml_use_internal_errors( true ); // Suppress XML parsing errors.
		$dom->loadXML( $svg_content );
		libxml_clear_errors();

		// Get the <svg> element.
		$svg = $dom->getElementsByTagName( 'svg' )->item( 0 );

		if ( ! $svg ) {
			return false; // Not an SVG file or missing <svg> tag.
		}

		// Extract the width and height attributes.
		$width  = $svg->getAttribute( 'width' );
		$height = $svg->getAttribute( 'height' );

		// Fallback if width and height are not set but viewBox is available.
		if ( ! $width || ! $height ) {
			$view_box = $svg->getAttribute( 'viewBox' );
			if ( $view_box ) {
				$view_box_values = explode( ' ', $view_box );
				if ( 4 === count( $view_box_values ) ) {
					$width  = $view_box_values[2];  // ViewBox width.
					$height = $view_box_values[3];  // ViewBox height.
				}
			}
		}

		// Ensure the values are numeric.
		return array(
			'width'  => (float) $width,
			'height' => (float) $height,
		);
	}
}
