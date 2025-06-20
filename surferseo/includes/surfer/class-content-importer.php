<?php
/**
 *  Object that handle importing content from:
 *  - Surfer
 *  - Google Docs
 *
 * @package SurferSEO
 * @link https://surferseo.com
 */

namespace SurferSEO\Surfer;

use SurferSEO\Surferseo;
use SurferSEO\Surfer\Content_Parsers\Parsers_Controller;
use SurferSEO\Surfer\Surfer_Logger;


/**
 * Object that imports data from different sources into WordPress.
 */
class Content_Importer {

	/**
	 * Object to manager content parsing for different editors.
	 *
	 * @var Parsers_Controller
	 */
	protected $content_parser = null;

	/**
	 * Object to manage image processing.
	 *
	 * @var Surfer_Image_Processor
	 */
	protected $image_processor = null;

	/**
	 * Basic construct.
	 */
	public function __construct() {

		$this->image_processor = new Surfer_Image_Processor();
		$this->content_parser  = new Parsers_Controller();

		add_filter( 'init', array( $this, 'register_ajax_actions' ) );
	}

	/**
	 * Register ajax functions for React front-end.
	 */
	public function register_ajax_actions() {

		add_action( 'wp_ajax_surfer_pull_and_override_content', array( $this, 'pull_and_override_content' ) );
	}

	/**
	 * Save imported data in database.
	 *
	 * @param string $content - post content.
	 * @param array  $args    - array of optional params.
	 * @return int|WP_Error
	 */
	public function save_data_into_database( $content, $args = array() ) {
		$logger           = Surfer()->get_surfer()->get_surfer_logger();
		$original_content = $content;

		$this->increase_limits_for_import();

		try {
			$image_count = substr_count( $content, '<img' );
			$this->content_parser->set_image_processing_mode( $image_count );

			$content = $this->content_parser->parse_content( $content );
			$content = $this->sanitize_content_for_database( $content );
			$title   = isset( $args['post_title'] ) && strlen( $args['post_title'] ) > 0 ? $args['post_title'] : $this->content_parser->return_title();
			$title   = $this->sanitize_content_for_database( $title );

			$data = array(
				'post_title'   => $title,
				'post_content' => $content,
			);

			if ( isset( $args['post_id'] ) && $args['post_id'] > 0 ) {
				$provided_post_id = $args['post_id'];
				$data['ID']       = $provided_post_id;
				$post             = (array) get_post( $provided_post_id );
			}

			$this->resolve_post_author( $args, $data );
			$this->resolve_post_status( $args, $data );
			$this->resolve_post_type( $args, $data );
			$this->resolve_post_permalink( $args, $data );
			$this->resolve_post_category( $args, $data );
			$this->resolve_post_tags( $args, $data );
			$this->resolve_post_meta_details( $args, $data );

			if ( isset( $post ) && 'publish' === $post['post_status'] ) {
				// WordPress set current date as default and we do not want to change publication date.
				$data['post_date']     = $post['post_date'];
				$data['post_date_gmt'] = $post['post_date_gmt'];
			} else {
				$this->resolve_post_date( $args, $data );
			}

			$post_id = wp_insert_post( $data, true );
			if ( isset( $data['meta_input']['_aioseo_title'] ) ) {
				$this->update_aioseo_table( $data['meta_input']['_aioseo_title'], $data['meta_input']['_aioseo_description'], $post_id );
			}

			if ( ! is_wp_error( $post_id ) && isset( $args['draft_id'] ) ) {
				update_post_meta( $post_id, 'surfer_draft_id', $args['draft_id'] );
				update_post_meta( $post_id, 'surfer_permalink_hash', isset( $args['permalink_hash'] ) ? $args['permalink_hash'] : '' );
				update_post_meta( $post_id, 'surfer_keywords', $args['keywords'] );
				update_post_meta( $post_id, 'surfer_location', $args['location'] );
				update_post_meta( $post_id, 'surfer_scrape_ready', true );
				update_post_meta( $post_id, 'surfer_last_post_update', round( microtime( true ) * 1000 ) );
				update_post_meta( $post_id, 'surfer_last_post_update_direction', 'from Surfer to WordPress' );
			}

			$this->content_parser->run_after_post_insert_actions( $post_id );

			$logger->log_import( $original_content, $content, true );
			return $post_id;

		} catch ( \Exception $e ) {
			$logger->log_import( $original_content, '', null, $e->getMessage() );
			return new \WP_Error( 'import_exception', $e->getMessage() );
		}
	}

	/**
	 * Increase PHP limits for import operations.
	 *
	 * @return void
	 */
	private function increase_limits_for_import() {
		if ( ! ini_get( 'safe_mode' ) ) {
			set_time_limit( 300 );
		}

		$current_limit = ini_get( 'memory_limit' );
		if ( $current_limit && '-1' !== $current_limit ) {
			$current_bytes  = $this->convert_to_bytes( $current_limit );
			$required_bytes = 512 * 1024 * 1024;

			if ( $current_bytes < $required_bytes ) {
				wp_raise_memory_limit( '512M' );
			}
		}
	}

	/**
	 * Convert memory limit string to bytes.
	 *
	 * @param string $limit - Memory limit string.
	 * @return int
	 */
	private function convert_to_bytes( $limit ) {
		$limit  = trim( $limit );
		$last   = strtolower( $limit[ strlen( $limit ) - 1 ] );
		$number = (int) $limit;

		switch ( $last ) {
			case 'g':
				$number *= 1024 * 1024 * 1024;
				break;
			case 'm':
				$number *= 1024 * 1024;
				break;
			case 'k':
				$number *= 1024;
				break;
		}

		return $number;
	}

	/**
	 * Sanitizes content to prevent emoji-related database issues.
	 *
	 * @param string $content Content to sanitize.
	 * @return string Sanitized content.
	 */
	private function sanitize_content_for_database( $content ) {
		$content = wp_encode_emoji( $content );

		$content = preg_replace_callback(
			'/[\x{1F000}-\x{1F9FF}]/u',
			function ( $match_unicode_emoji ) {
				return '&#x' . dechex( ord( $match_unicode_emoji[0] ) ) . ';';
			},
			$content
		);

		return $content;
	}

	/**
	 * Fill $data array with proper attribute for post_author or leave empty to fill default.
	 *
	 * @param array $args - array of arguments pasted to request.
	 * @param array $data - pointer to array where we store data to put into post.
	 * @return void
	 */
	private function resolve_post_author( $args, &$data ) {

		if ( isset( $args['post_author'] ) && '' !== $args['post_author'] ) {

			$value = $args['post_author'];

			if ( is_numeric( $value ) && $value > 0 ) {
				$data['post_author'] = $value;
			} else {
				$data['post_author'] = $this->get_user_id_by_login( $value );
			}
		} else {
			$default = Surfer()->get_surfer_settings()->get_option( 'content-importer', 'default_post_author', false );

			if ( false !== $default ) {
				$data['post_author'] = $default;
			}
		}
	}

	/**
	 * Fill $data array with proper attribute for post_status or leave empty to fill default.
	 *
	 * @param array $args - array of arguments pasted to request.
	 * @param array $data - pointer to array where we store data to put into post.
	 * @return void
	 */
	private function resolve_post_status( $args, &$data ) {

		$allowed_statuses = array( 'publish', 'draft', 'pending', 'future', 'private' );

		if ( isset( $args['post_status'] ) && '' !== $args['post_status'] && in_array( $args['post_status'], $allowed_statuses, true ) ) {
			$data['post_status'] = $args['post_status'];
		} else {
			$default = Surferseo::get_instance()->get_surfer_settings()->get_option( 'content-importer', 'default_post_status', false );

			if ( false !== $default ) {
				$data['post_status'] = $default;
			}
		}
	}

	/**
	 * Fill $data array with proper attribute for post_type or leave empty to fill default.
	 *
	 * @param array $args - array of arguments pasted to request.
	 * @param array $data - pointer to array where we store data to put into post.
	 * @return void
	 */
	private function resolve_post_type( $args, &$data ) {

		$allowed_post_types = surfer_return_supported_post_types();

		if ( isset( $args['post_type'] ) && '' !== $args['post_type'] && in_array( $args['post_type'], $allowed_post_types, true ) ) {
			$data['post_type'] = $args['post_type'];
		} else {
			$data['post_type'] = 'post';
		}
	}

	/**
	 * Fill $data array with proper attribute for post_date or leave empty to fill default.
	 *
	 * @param array $args - array of arguments pasted to request.
	 * @param array $data - pointer to array where we store data to put into post.
	 * @return void
	 */
	private function resolve_post_date( $args, &$data ) {

		if ( isset( $args['post_date'] ) && strtotime( $args['post_date'] ) > time() ) {
			$data['post_date'] = gmdate( 'Y-m-d H:i:s', strtotime( $args['post_date'] ) );
		}
	}

	/**
	 * Fill $data array with proper attribute for post_name or leave empty to fill default.
	 *
	 * @param array $args - array of arguments pasted to request.
	 * @param array $data - pointer to array where we store data to put into post.
	 * @return void
	 */
	private function resolve_post_permalink( $args, &$data ) {

		if ( isset( $args['post_name'] ) && '' !== $args['post_name'] ) {
			$data['post_name'] = $args['post_name'];
		}
	}

	/**
	 * Fill $data array with proper attribute for post_category or leave empty to fill default.
	 *
	 * @param array $args - array of arguments pasted to request.
	 * @param array $data - pointer to array where we store data to put into post.
	 * @return void
	 */
	private function resolve_post_category( $args, &$data ) {

		if ( isset( $args['post_category'] ) && is_array( $args['post_category'] ) && ! empty( $args['post_category'] ) ) {

			$categories = array();
			foreach ( $args['post_category'] as $category ) {
				$categories[] = $category['value'];
			}

			$data['post_category'] = $categories;
		} else {
			$default = Surferseo::get_instance()->get_surfer_settings()->get_option( 'content-importer', 'default_category', false );

			if ( false !== $default ) {
				$data['post_category'] = array( $default );
			}
		}
	}

	/**
	 * Fill $data array with proper attribute for tags_input or leave empty to fill default.
	 *
	 * @param array $args - array of arguments pasted to request.
	 * @param array $data - pointer to array where we store data to put into post.
	 * @return void
	 */
	private function resolve_post_tags( $args, &$data ) {

		if ( isset( $args['post_tags'] ) && is_array( $args['post_tags'] ) && count( $args['post_tags'] ) > 0 ) {

			$tags = array();
			foreach ( $args['post_tags'] as $tag ) {
				$tags[] = $tag['value'];
			}

			$data['tags_input'] = $tags;
		} else {
			$default = Surferseo::get_instance()->get_surfer_settings()->get_option( 'content-importer', 'default_tags', false );

			if ( false !== $default ) {
				$data['tags_input'] = $default;
			}
		}
	}

	/**
	 * Fill the meta_title and meta_description if any SEO plugin is active.
	 *
	 * @param array $args - array of arguments pasted to request.
	 * @param array $data - pointer to array where we store data to put into post.
	 * @return void
	 */
	private function resolve_post_meta_details( $args, &$data ) {

		$chosen_seo_plugin = Surfer()->get_surfer_settings()->get_option( 'content-importer', 'default_seo_plugin', '' );

		if ( '' === $chosen_seo_plugin ) {
			$chosen_seo_plugin = $this->find_active_seo_plugin();
		}

		if ( ! isset( $data['meta_input'] ) ) {
			$data['meta_input'] = array();
		}

		// Yoast SEO is active.
		if ( 'yoast' === $chosen_seo_plugin ) {

			if ( isset( $args['meta_title'] ) && '' !== $args['meta_title'] ) {
				$data['meta_input']['_yoast_wpseo_title'] = $args['meta_title'];
			}

			if ( isset( $args['meta_description'] ) && '' !== $args['meta_description'] ) {
				$data['meta_input']['_yoast_wpseo_metadesc'] = $args['meta_description'];
			}
		}

		// All in One SEO is active.
		if ( 'aioseo' === $chosen_seo_plugin ) {

			if ( isset( $args['meta_title'] ) && '' !== $args['meta_title'] ) {
				$data['meta_input']['_aioseo_title'] = $args['meta_title'];
			}

			if ( isset( $args['meta_description'] ) && '' !== $args['meta_description'] ) {
				$data['meta_input']['_aioseo_description'] = $args['meta_description'];
			}
		}

		// Rank Math SEO.
		if ( 'rank_math' === $chosen_seo_plugin ) {

			if ( isset( $args['meta_title'] ) && '' !== $args['meta_title'] ) {
				$data['meta_input']['rank_math_title'] = $args['meta_title'];
			}

			if ( isset( $args['meta_description'] ) && '' !== $args['meta_description'] ) {
				$data['meta_input']['rank_math_description'] = $args['meta_description'];
			}
		}

		// Save in Surfer Meta to display.
		if ( 'surfer' === $chosen_seo_plugin ) {

			if ( isset( $args['meta_title'] ) && '' !== $args['meta_title'] ) {
				$data['meta_input']['_surferseo_title'] = $args['meta_title'];
			}

			if ( isset( $args['meta_description'] ) && '' !== $args['meta_description'] ) {
				$data['meta_input']['_surferseo_description'] = $args['meta_description'];
			}
		}
	}

	/**
	 * Checks which SEO plugin is active.
	 */
	private function find_active_seo_plugin() {

		if ( surfer_check_if_plugins_is_active( 'wordpress-seo/wp-seo.php' ) ) {
			return 'yoast';
		} elseif ( surfer_check_if_plugins_is_active( 'all-in-one-seo-pack/all_in_one_seo_pack.php' ) ) {
			return 'aioseo';
		} elseif ( surfer_check_if_plugins_is_active( 'seo-by-rank-math/rank-math.php' ) ) {
			return 'rank_math';
		}

		return 'surfer';
	}


	/**
	 * Returns ID of the user with given name.
	 *
	 * @param string $login - login of the user.
	 * @return int
	 */
	private function get_user_id_by_login( $login = false ) {

		$user_id = 0;
		$user    = get_user_by( 'login', $login );

		if ( false !== $user ) {
			$user_id = get_option( 'surfer_auth_user', 0 );
		}

		return $user_id;
	}


	/**
	 * Checks if plugin is active even if default function is not loaded.
	 *
	 * @param string $plugin - plugin name to check.
	 * @return bool
	 */
	public function check_if_plugins_is_active( $plugin ) {

		if ( ! function_exists( 'is_plugin_active' ) ) {
			return in_array( $plugin, (array) get_option( 'active_plugins', array() ), true );
		} else {
			return is_plugin_active( $plugin );
		}
	}

		/**
		 * Gets post sync status from WordPress and Surfer.
		 */
	public function pull_and_override_content() {
		$json = file_get_contents( 'php://input' );
		$data = json_decode( $json );

		if ( ! surfer_validate_custom_request( $data->_surfer_nonce ) ) {
			echo wp_json_encode( array( 'message' => 'Security check failed.' ) );
			wp_die();
		}

		$draft_id            = isset( $data->draft_id ) ? intval( $data->draft_id ) : false;
		$post_id             = isset( $data->post_id ) ? intval( $data->post_id ) : false;
		$content_from_surfer = isset( $data->content_from_surfer ) ? $data->content_from_surfer : false;

		$params = array(
			'draft_id' => $draft_id,
			'post_id'  => $post_id,
		);

		list(
			'code'     => $code,
			'response' => $response,
		) = Surfer()->get_surfer()->make_surfer_request( '/update_last_sync_date', $params );

		if ( 200 === $code || 201 === $code ) {

			$args = array(
				'draft_id' => $draft_id,
				'post_id'  => $post_id,
			);
			$this->save_data_into_database( $content_from_surfer, $args );
		}

		echo wp_json_encode( $response );
		wp_die();
	}

	/**
	 * Update All in One SEO table with new values.
	 *
	 * @param string $meta_title - title to update.
	 * @param string $meta_description - description to update.
	 * @param int    $post_id - post ID.
	 */
	private function update_aioseo_table( $meta_title, $meta_description, $post_id ) {

		$chosen_seo_plugin = Surfer()->get_surfer_settings()->get_option( 'content-importer', 'default_seo_plugin', '' );

		if ( '' === $chosen_seo_plugin ) {
			$chosen_seo_plugin = $this->find_active_seo_plugin();
		}

		if ( 'aioseo' !== $chosen_seo_plugin ) {
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'aioseo_posts';

		$have_field = $wpdb->query( $wpdb->prepare( 'SELECT * FROM %s WHERE post_id = %d', $table, $post_id ) );

		if ( $have_field ) {
			$wpdb->update(
				$table,
				array(
					'title'       => $meta_title,
					'description' => $meta_description,
				),
				array( 'post_id' => $post_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		} else {
			$wpdb->insert(
				$table,
				array(
					'post_id'     => $post_id,
					'title'       => $meta_title,
					'description' => $meta_description,
				),
				array( '%d', '%s', '%s' )
			);
		}
	}
}
