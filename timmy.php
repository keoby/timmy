<?php
/**
 * Plugin Name: Timmy
 * Plugin URI: https://bitbucket.org/mindkomm/timmy
 * Description: Opt-in plugin for Timber Library to make it even more convenient to work with images.
 * Version: 0.1.0
 * Author: Lukas Gächter <@lgaechter>
 * Author URI: http://www.mind.ch
 */

require_once( 'functions-images.php' );

class Timmy
{
	public function __construct() {
		if ( class_exists( 'TimberImageHelper' ) && function_exists( 'get_image_sizes' ) ) {
			// Add filters to make TimberImages work with normal WordPress functionality
			add_filter( 'image_downsize', array( $this, 'filter_image_downsize' ), 10, 3 );
			add_filter( 'image_size_names_choose', array( $this, 'filter_image_size_names_choose' ) );
			add_filter( 'intermediate_image_sizes', array( $this, 'filter_intermediate_image_sizes' ) );
			add_filter( 'wp_generate_attachment_metadata', array( $this, 'filter_wp_generate_attachment_metadata' ), 10, 2 );

			// Add filters to integrate Timmy into Timber and Twig
			add_filter( 'timber_context', array( $this, 'filter_timber_context' ) );
			add_filter( 'get_twig', array( $this, 'filter_get_twig' ) );
		}
	}

	/**
	 * Image Functions for Timber context
	 */
	public function filter_timber_context( $context ) {
		$context['get_timber_image_responsive_acf'] = TimberHelper::function_wrapper( 'get_timber_image_responsive_acf' );
		return $context;
	}

	/**
	 * Sets filters to use Timmy functions in Twig
	 */
	public function filter_get_twig( $twig ) {
		$twig->addFilter( new Twig_SimpleFilter( 'get_timber_image', 'get_timber_image' ) );
		$twig->addFilter( new Twig_SimpleFilter( 'get_timber_image_src', 'get_timber_image_src' ) );
		$twig->addFilter( new Twig_SimpleFilter( 'get_timber_image_responsive', 'get_timber_image_responsive' ) );
		$twig->addFilter( new Twig_SimpleFilter( 'get_timber_image_responsive_src', 'get_timber_image_responsive_src' ) );

		return $twig;
	}

	/**
	 * We tell WordPress that we don’t have a intermedia sizes, because
	 * we have our own image thingy we want to work with.
	 *
	 * This might bring some small performance improvements (might, because
	 * not tested).
	 */
	public function filter_intermediate_image_sizes( $sizes ) {
		return array();
	}

	/**
	 * Hook into the filter that generates additional image sizes
	 * to generate all additional image size with TimberImageHelper
	 *
	 * This function will also run if you run Regenerate Thumbnails,
	 * so all additional images sizes registered with Timber will be
	 * first deleted and then regenerated through Timber.
	 */
	public function filter_wp_generate_attachment_metadata( $metadata, $attachment_id ) {
		if ( wp_attachment_is_image( $attachment_id ) ) {
			$this->timber_generate_sizes( $attachment_id );
		}

		return $metadata;
	}

	/**
	 * Replace the default image sizes with the sizes from the image config.
	 *
	 * The image will only be shown, if the config key 'show_in_ui' is not false.
	 *
	 * If you want to make the full size of the image available, create a new filter
	 * and add $sizes['full'] = __( 'Full Size' ); to the array.
	 */
	public function filter_image_size_names_choose( $sizes ) {
		$sizes = array();
		$img_sizes = get_image_sizes();

		foreach ($img_sizes as $key => $size) {
			if ( isset( $size['show_in_ui'] ) && false == $size['show_in_ui'] ) {
				continue;
			}

			$name = $key;
			if ( isset( $size['name'] ) ) {
				$name = $size['name'] . ' (' . $key . ')';
			}
			$sizes[ $key ] = $name;
		}

		return $sizes;
	}

	/**
	 * Creates an image size based on the parameters given in the image configuration
	 */
	public function filter_image_downsize( $false = false, $attachment_id, $size ) {
		$attachment = get_post( $attachment_id );

		/**
		 * Bail out if we try to downsize an SVG file or if we are in the backend
		 * and want to load a GIF.
		 *
		 * GIFs are also resized, but only the first still.
		 * We want to load the full GIF only in the frontend, because GIFs might
		 * get quite big. This would slow down the Backend big time, if we want
		 * to load the Media library with several GIFs.
		 */
		if ( 'image/svg+xml' === $attachment->post_mime_type ) {
			return false;
		} elseif ( ! is_admin() && 'image/gif' === $attachment->post_mime_type ) {
			return false;
		}

		$img_sizes = get_image_sizes();
		$should_resize = false;

		// Sort out which image size we need to take from our own image configuration
		if ( ! is_array( $size ) && isset( $img_sizes[ $size ] ) ) {
			$img_size = $img_sizes[ $size ];

			$should_resize = $this->timber_should_resize( $attachment->post_parent, $img_sizes[ $size ] );
			if ( ! $should_resize ) {
				return $false;
			}
		} else {
			/**
			 * When an image is requested without a size name or with
			 * dimensions only, we try to return the thumbnail. Otherwise
			 * we just take the first image in the image array.
			 */
			if ( isset( $img_sizes['thumbnail'] ) ) {
				$img_size = $img_sizes['thumbnail'];
			} else {
				$img_size = reset( $img_sizes );
			}
		}

		// Timber needs the file src as an url
		$file_src = wp_get_attachment_url( $attachment_id );

		/**
		 * Certain functions ask for the full size of an image
		 *
		 * WP SEO for example asks for the original size, which we’ll return here.
		 */
		if ( in_array( $size, array( 'original', 'full' ) ) ) {
			$file_meta = wp_get_attachment_metadata( $attachment_id );
			return array( $file_src, $file_meta['width'], $file_meta['height'], false );
		}

		$resize   = $img_size['resize'];

		$width    = $resize[0];
		$height   = isset( $resize[1] ) ? $resize[1] : 0;
		$crop     = isset( $resize[2] ) ? $resize[2] : 'default';
		$force    = isset( $resize[3] ) ? $resize[3] : false;

		// Resize the image for that size
		$src = TimberImageHelper::resize( $file_src, $width, $height, $crop, $force );

		// When the input size is an array of width and height
		if ( is_array( $size ) ) {
			$width = $size[0];
			$height = $size[1];
		}

		/**
		 * For the return, we also send in a fourth parameter,
		 * which stands for is_intermediate. It is true if $url is
		 * a resized image, false if it is the original.
		 */
		return array( $src, $width, $height, true );
	}

	/**
	 * Generate image sizes defined for Timmy with TimberImageHelper
	 *
	 * @param  int	$attachment_id	The attachment ID for which all images should be resized
	 * @return void
	 */
	private function timber_generate_sizes( $attachment_id ) {
		$img_sizes = get_image_sizes();
		$attachment = get_post( $attachment_id );

		// Timber needs the file src as an url
		$file_src = wp_get_attachment_url( $attachment_id );

		/**
		 * Delete all existing image sizes for that file.
		 *
		 * This way, when Regenerate Thumbnails will be used,
		 * all non-registered image sizes will be deleted as well.
		 * Because Timber creates image sizes when they’re needed,
		 * we can safely do this.
		 */
		TimberImageHelper::delete_generated_files( $file_src );

		foreach ($img_sizes as $key => $img_size) {

			if ( ! $this->timber_should_resize( $attachment->post_parent, $img_size ) ) {
				continue;
			}

			$resize = $img_size['resize'];

			// Get values for the default image
			$width    = $resize[0];
			$height   = isset( $resize[1] ) ? $resize[1] : 0;
			$crop     = isset( $resize[2] ) ? $resize[2] : 'default';
			$force    = isset( $resize[3] ) ? $resize[3] : false;

			image_downsize( $attachment_id, $key );

			if ( isset( $img_size['generate_srcset_sizes'] ) && false == $img_size['generate_srcset_sizes'] ) {
				continue;
			}

			// Generate additional image sizes used for srcset
			if ( isset( $img_size['srcset'] ) ) {
				foreach ($img_size['srcset'] as $src) {
					// Get width and height for the additional src
					if ( is_array( $src ) ) {
						$width = $src[0];
						$height = isset( $src[1] ) ? $src[1] : 0;
					} else {
						$width = round( $resize[0] * $src );
						$height = isset( $resize[1] ) ? round( $resize[1] * $src ) : 0;
					}

					// For the new source, we use the same $crop and $force values as the default image
					TimberImageHelper::resize( $file_src, $width, $height, $crop, $force );
				}
			}
		}
	}

	/**
	 * Check if we should pregenerate an image size based on the image configuration.
	 *
	 * @param  int      $attachment_parent_id
	 * @param  string   $img_size               The key of the size in the image configuration
	 * @return bool                             Whether the image should or can be resized
	 */
	private function timber_should_resize( $attachment_parent_id, $img_size ) {
		/**
		 * Normally we don’t have a post type associated with the attachment
		 *
		 * We use an empty array to tell the function that there is no post type
		 * associated with the attachment.
		 */
		$attachment_post_type = array( '' );

		// Check if image is attached to a post and sort out post type
		if ( 0 !== $attachment_parent_id ) {
			$parent = get_post( $attachment_parent_id );
			$attachment_post_type = array( $parent->post_type );
		}

		// Reset post types that should be applied as a standard
		$post_types_to_apply = array( '', 'page', 'post' );

		/**
		 * When a post type is given in the arguments, we generate the size
		 * only if the attachment is associated with that post.
		 */
		if ( array_key_exists( 'post_types', $img_size ) ) {
			$post_types_to_apply = $img_size['post_types'];
		}

		if ( ! in_array( 'all', $post_types_to_apply ) ) {
			// Check if we should really resize that picture
			$intersections = array_intersect( $post_types_to_apply, $attachment_post_type );

			if ( ! empty( $intersections ) ) {
				return true;
			}
		} else {
			return true;
		}

		return false;
	}
}

new Timmy();