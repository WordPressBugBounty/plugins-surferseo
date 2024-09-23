<?php
/**
 *  Object that manage keyword surfer option in sidebar.
 *
 * @package SurferSEO
 * @link https://surferseo.com
 */

namespace SurferSEO\Surfer;

use SurferSEO\Surferseo;

/**
 * Object responsible for handlig keyword surfer in post edition.
 */
class Json_Schema {

	/**
	 * Object construct.
	 */
	public function __construct() {
		add_action( 'enqueue_block_editor_assets', array( $this, 'include_keyword_surfer_scripts' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'include_keyword_surfer_scripts' ) );

		add_action( 'add_meta_boxes', array( $this, 'add_json_schema_meta_box' ) );

		add_action( 'wp_ajax_surfer_get_post_json_schema', array( $this, 'get_json_schema' ) );
		add_action( 'wp_ajax_surfer_save_post_json_schema', array( $this, 'save_json_schema' ) );

		add_action( 'wp_head', array( $this, 'display_json_schema' ) );
	}

	/**
	 * Enqueue sidebar script.
	 */
	public function include_keyword_surfer_scripts() {
		$screen = get_current_screen();
		if ( ! in_array( $screen->post_type, surfer_return_supported_post_types(), true ) ) {
			return;
		}

		Surfer()->get_surfer()->enqueue_surfer_react_apps();
	}

	/**
	 * Creates metabox where we will store writing guidelines in iFrame.
	 *
	 * @return void
	 */
	public function add_json_schema_meta_box() {
		$current_screen = get_current_screen();

		// Add meta box only in classic editor (in Gutenber we have sidebar).
		if ( ! $current_screen->is_block_editor() ) {
			add_meta_box(
				'surfer_json_schema',
				__(
					'JSON Schema
				',
					'surferseo'
				),
				array( $this, 'render_json_schema_meta_box_content' ),
				'post',
				'side',
				'default'
			);
		}
	}

	/**
	 * Displays content of the keyword research box.
	 *
	 * @return void
	 */
	public function render_json_schema_meta_box_content() {

		?>
			<div id="surfer-json-schema"></div>
		<?php
	}

	/**
	 * Get json schema.
	 *
	 * @return void
	 */
	public function get_json_schema() {

		$json = file_get_contents( 'php://input' );
		$data = json_decode( $json );

		if ( ! surfer_validate_custom_request( $data->_surfer_nonce ) ) {
			echo wp_json_encode( array( 'message' => 'Security check failed.' ) );
			wp_die();
		}

		$post_id     = $data->post_id;
		$json_schema = get_post_meta( $post_id, '_surfer_json_schema', true );

		wp_send_json_success( array( 'schema' => $json_schema ) );
	}

	/**
	 * Save json schema.
	 *
	 * @return void
	 */
	public function save_json_schema() {

		$json = file_get_contents( 'php://input' );
		$data = json_decode( $json );

		if ( ! surfer_validate_custom_request( $data->_surfer_nonce ) ) {
			echo wp_json_encode( array( 'message' => 'Security check failed.' ) );
			wp_die();
		}

		$post_id     = $data->post_id;
		$json_schema = $data->json_schema;

		$t = update_post_meta( $post_id, '_surfer_json_schema', $json_schema );

		wp_send_json_success(
			array(
				'save_success' => $t,
				'schema_saved' => $json_schema,
			)
		);
	}

	/**
	 * Displays json schema in head.
	 */
	public function display_json_schema() {

		if ( ! is_single() ) {
			return;
		}

		$post_id     = get_the_ID();
		$json_schema = get_post_meta( $post_id, '_surfer_json_schema', true );

		if ( ! $json_schema ) {
			return;
		}

		echo '<script type="application/ld+json">' . esc_html( $json_schema ) . '</script>';
	}
}
