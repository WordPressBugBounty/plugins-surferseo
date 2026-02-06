<?php
/**
 *  Object that exports content to Surfer.
 *
 * @package SurferSEO
 * @link https://surferseo.com
 */

namespace SurferSEO\Surfer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DOMDocument;
use DOMElement;
use SurferSEO\Surfer;
use SurferSEO\Surferseo;
use SurferSEO\Surfer\Surfer_Logger;

/**
 * Content exporter object.
 */
class Content_Exporter {


	/**
	 * Object construct.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'init' ) );
	}

	/**
	 * Init function.
	 */
	public function init() {
		add_filter( 'post_row_actions', array( $this, 'add_export_content_button_to_posts_list' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'add_export_content_button_to_posts_list' ), 10, 2 );

		add_filter( 'wp_ajax_surfer_create_content_editor', array( $this, 'create_content_editor' ) );
		add_filter( 'wp_ajax_surfer_update_content_editor', array( $this, 'update_content_editor' ) );
		add_filter( 'wp_ajax_surfer_remove_post_draft_connection', array( $this, 'remove_post_draft_connection' ) );
		add_filter( 'wp_ajax_surfer_check_draft_status', array( $this, 'check_draft_status' ) );
		add_filter( 'wp_ajax_surfer_get_locations', array( $this, 'get_locations' ) );
		add_filter( 'wp_ajax_surfer_get_post_sync_status', array( $this, 'get_post_sync_status' ) );

		add_filter( 'wp_ajax_surfer_gather_posts_to_reconnect', array( $this, 'gather_posts_to_reconnect' ) );
		add_filter( 'wp_ajax_surfer_reconnect_posts_with_drafts', array( $this, 'reconnect_posts_with_drafts' ) );
		add_action( 'wp_ajax_surfer_remove_old_backups', array( $this, 'surfer_remove_old_backups' ) );
	}

	/**
	 * Add export content button to post/page list.
	 *
	 * @param array   $actions - actions array.
	 * @param WP_Post $post - post object.
	 */
	public function add_export_content_button_to_posts_list( $actions, $post ) {
		$draft_id = get_post_meta( $post->ID, 'surfer_draft_id', true );

		if ( $draft_id ) {
			$actions['export_to_surfer'] = '<a href="' . Surfer()->get_surfer()->get_surfer_url() . '/drafts/' . intval( $draft_id ) . '" >' . __( 'Check in Surfer', 'surferseo' ) . '</a>';
		}

		return $actions;
	}

	/**
	 * Makes export content to Surfer.
	 */
	public function create_content_editor() {
		$json = file_get_contents( 'php://input' );
		$data = json_decode( $json );

		if ( ! surfer_validate_custom_request( $data->_surfer_nonce ) ) {
			echo wp_json_encode( array( 'message' => 'Security check failed.' ) );
			wp_die();
		}

		$keywords         = isset( $data->keywords ) ? sanitize_text_field( wp_unslash( $data->keywords ) ) : false;
		$location         = isset( $data->location ) ? sanitize_text_field( wp_unslash( $data->location ) ) : 'United States';
		$content          = isset( $data->content ) ? wp_kses_post( $data->content ) : false;
		$post_id          = isset( $data->post_id ) ? intval( $data->post_id ) : false;
		$meta_title       = isset( $data->post_id ) ? sanitize_text_field( wp_unslash( Surfer()->get_surfer()->get_post_meta_title( $data->post_id ) ) ) : false;
		$meta_description = isset( $data->post_id ) ? sanitize_text_field( wp_unslash( Surfer()->get_surfer()->get_post_meta_description( $data->post_id ) ) ) : false;
		$workspace_id     = isset( $data->workspace_id ) ? sanitize_text_field( wp_unslash( $data->workspace_id ) ) : false;

		if ( false === $keywords || '' === $keywords || empty( $keywords ) ) {
			echo wp_json_encode( array( 'message' => 'You need to provide at least one keyword.' ) );
			wp_die();
		}

		if ( ! is_array( $keywords ) ) {
			$keywords = explode( ',', $keywords );
		}

		$params = array(
			'keywords'         => $keywords,
			'location'         => $location,
			'content'          => $content,
			'wp_post_id'       => $post_id,
			'url'              => apply_filters( 'surfer_api_base_url', get_site_url() ),
			'meta_title'       => $meta_title,
			'meta_description' => $meta_description,
			'workspace_id'     => $workspace_id,
		);

		list(
			'code'     => $code,
			'response' => $response,
		) = Surfer()->get_surfer()->make_surfer_request( '/import_content', $params );

		if ( 200 === $code || 201 === $code ) {
			$this->save_post_surfer_details( $post_id, $params['keywords'], $params['location'], $response['id'], $response['permalink_hash'] );
		}

		echo wp_json_encode( $response );
		wp_die();
	}

	/**
	 * Makes update content to Surfer.
	 */
	public function update_content_editor() {
		$logger = Surfer()->get_surfer()->get_surfer_logger();
		$json   = file_get_contents( 'php://input' );
		$data   = json_decode( $json );

		if ( ! surfer_validate_custom_request( $data->_surfer_nonce ) ) {
			$logger->log_export( '', '', null, 'Security check failed.' );
			echo wp_json_encode( array( 'message' => 'Security check failed.' ) );
			wp_die();
		}

		try {
			$original_content = isset( $data->content ) ? $data->content : '';
			$content          = isset( $data->content ) ? wp_kses_post( $this->parse_content_for_surfer( $data->content ) ) : false;

			$keywords = isset( $data->keywords ) ? $data->keywords : false;

			$draft_id         = isset( $data->draft_id ) ? intval( $data->draft_id ) : false;
			$post_id          = isset( $data->post_id ) ? intval( $data->post_id ) : false;
			$permalink_hash   = isset( $data->permalink_hash ) ? sanitize_text_field( wp_unslash( $data->permalink_hash ) ) : false;
			$keywords         = is_array( $data->keywords ) ? array_map( 'sanitize_text_field', $data->keywords ) : sanitize_text_field( wp_unslash( $data->keywords ) );
			$location         = isset( $data->location ) ? sanitize_text_field( wp_unslash( $data->location ) ) : false;
			$meta_title       = isset( $data->post_id ) ? sanitize_text_field( wp_unslash( Surfer()->get_surfer()->get_post_meta_title( $data->post_id ) ) ) : false;
			$meta_description = isset( $data->post_id ) ? sanitize_text_field( wp_unslash( Surfer()->get_surfer()->get_post_meta_description( $data->post_id ) ) ) : false;

			$params = array(
				'draft_id'         => $draft_id,
				'content'          => $content,
				'wp_post_id'       => $post_id,
				'url'              => apply_filters( 'surfer_api_base_url', get_site_url() ),
				'meta_title'       => $meta_title,
				'meta_description' => $meta_description,
			);

			list(
				'code'     => $code,
				'response' => $response,
			) = Surfer()->get_surfer()->make_surfer_request( '/import_content_update', $params );

			if ( 200 === $code || 201 === $code ) {
				$logger->log_export( $original_content, $content, true );
				update_post_meta( $post_id, 'surfer_last_post_update', round( microtime( true ) * 1000 ) );
				update_post_meta( $post_id, 'surfer_last_post_update_direction', 'from WordPress to Surfer' );

				$this->save_post_surfer_details( $post_id, $keywords, $location, $draft_id, $permalink_hash );
			} else {
				$error_message = isset( $response['message'] ) ? $response['message'] : 'Unknown API error';
				$logger->log_export( $original_content, $content, $error_message );
			}

			echo wp_json_encode( $response );
			wp_die();

		} catch ( \Exception $e ) {
			$logger->log_export( $original_content ?? '', '', null, $e->getMessage() );
			echo wp_json_encode( array( 'message' => 'Export failed: ' . $e->getMessage() ) );
			wp_die();
		}
	}

	/**
	 * Saves Surfer details about post.
	 *
	 * @param int    $post_id - ID of the post.
	 * @param string $keyword - keyword for the post.
	 * @param string $location - location for the post.
	 * @param int    $draft_id - ID of the draft in Surfer.
	 * @param string $permalink_hash - hash of the post permalink.
	 * @return void
	 */
	private function save_post_surfer_details( $post_id, $keyword, $location, $draft_id, $permalink_hash ) {

		update_post_meta( $post_id, 'surfer_last_post_update', round( microtime( true ) * 1000 ) );
		update_post_meta( $post_id, 'surfer_last_post_update_direction', 'from WordPress to Surfer' );
		update_post_meta( $post_id, 'surfer_keywords', $keyword );
		update_post_meta( $post_id, 'surfer_location', $location );
		update_post_meta( $post_id, 'surfer_draft_id', $draft_id );
		update_post_meta( $post_id, 'surfer_permalink_hash', $permalink_hash );
	}

	/**
	 * Allows to check draft status.
	 */
	public function check_draft_status() {
		$json = file_get_contents( 'php://input' );
		$data = json_decode( $json );

		if ( ! surfer_validate_custom_request( $data->_surfer_nonce ) ) {
			echo wp_json_encode( array( 'message' => 'Security check failed.' ) );
			wp_die();
		}

		$draft_id = isset( $data->draft_id ) ? intval( $data->draft_id ) : false;
		$post_id  = isset( $data->post_id ) ? intval( $data->post_id ) : false;

		$params = array(
			'draft_id' => $draft_id,
		);

		list(
			'code'     => $code,
			'response' => $response,
		) = Surfer()->get_surfer()->make_surfer_request( '/check_draft_status', $params );

		if ( 200 === $code || 201 === $code ) {

			$status = $response['draft_ready'];

			if ( isset( $response['draft_status'] ) && 'failed' === $response['draft_status'] ) {
				$status = -1;
			}

			update_post_meta( $post_id, 'surfer_scrape_ready', $status );
		}

		echo wp_json_encode( $response );
		wp_die();
	}

	/**
	 * Parse content to match Surfer formatting, and keep whole HTML.
	 *
	 * @param string $content - content to parse.
	 * @return string
	 */
	private function parse_content_for_surfer( $content ) {

		$content = wp_unslash( $content );
		$content = do_shortcode( $content );

		$doc = new DOMDocument();

		$utf8_fix_prefix = '<html><head><meta http-equiv="content-type" content="text/html; charset=utf-8" /></head><body>';
		$utf8_fix_suffix = '</body></html>';

		$doc->loadHTML( $utf8_fix_prefix . $content . $utf8_fix_suffix, LIBXML_HTML_NODEFDTD | LIBXML_SCHEMA_CREATE );

		$parsed_content = '';

		$this->parse_dom_node( $doc, $parsed_content );

		return $parsed_content;
	}

	/**
	 * Function iterates by HTML tags in provided content.
	 *
	 * @param DOMDocument $parent_node - node to parse.
	 * @param string      $content     - reference to content variable, to store Gutenberg output.
	 * @return void
	 */
	private function parse_dom_node( $parent_node, &$content ) {
		// @codingStandardsIgnoreLine
		foreach ( $parent_node->childNodes as $node ) {

			// @codingStandardsIgnoreLine
			if ( in_array( $node->nodeName, array( 'html', 'body' ) ) ) {
				$this->parse_dom_node( $node, $content );
				break;
			}

			$node_content = $this->get_inner_html( $node );

			// We need to get IMGs from <p> tag, to allow Surfer to handle this.
			if ( strlen( $node_content ) > 0 && false !== strpos( $node_content, '<img' ) ) {
				$content .= $node_content;
				// @codingStandardsIgnoreLine
			} elseif ( 'li' === $node->nodeName ) {
				$content .= '<li>' . $node_content . '</li>' . PHP_EOL;
				// @codingStandardsIgnoreLine
			} elseif ( 'p' === $node->nodeName ) {
				$content .= '<p>' . $node_content . '</p>' . PHP_EOL;
				// @codingStandardsIgnoreLine
			} elseif ( in_array( $node->nodeName, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ) ) ) {
				// @codingStandardsIgnoreLine
				$content .= '<' . $node->nodeName . '>' . $node_content . '</' . $node->nodeName . '>' . PHP_EOL;
				// @codingStandardsIgnoreLine
			} elseif ( 'img' === $node->nodeName ) { // @codingStandardsIgnoreLine
				$attributes           = array();
				$attributes['src']    = $node->getAttribute( 'src' );
				$attributes['alt']    = $node->getAttribute( 'alt' );
				$attributes['title']  = $node->getAttribute( 'title' );
				$attributes['width']  = $node->getAttribute( 'width' );
				$attributes['height'] = $node->getAttribute( 'height' );
				$attributes['class']  = $node->getAttribute( 'class' );
				$content             .= '<img' . $this->glue_attributes( $attributes ) . ' />' . PHP_EOL;
			} elseif ( $node->hasChildNodes() ) {
				// @codingStandardsIgnoreLine
				$this->parse_dom_node( $node, $content );
			}
		}
	}

	/**
	 * Turns attributes array into HTML string.
	 *
	 * @param array $attributes_array - array of attributes.
	 * @return string
	 */
	protected function glue_attributes( $attributes_array ) {

		$attributes = ' ';

		foreach ( $attributes_array as $key => $value ) {
			$attributes .= $key . '="' . $value . '" ';
		}
		$attributes = rtrim( $attributes );

		return $attributes;
	}

	/**
	 * Extract inner HTML for provided node.
	 *
	 * @param DOMElement $node - node element to parse.
	 * @return string
	 */
	private function get_inner_html( $node ) {
		$inner_html = '';

		// @codingStandardsIgnoreLine
		foreach ( $node->childNodes as $child ) {

			// @codingStandardsIgnoreLine
			$content = $child->ownerDocument->saveXML( $child );

			if ( '<li/>' !== $content ) {
				$inner_html .= $content;
			}
		}

		return $inner_html;
	}

	/**
	 * Returns available locations.
	 */
	public function get_locations() {
		$json = file_get_contents( 'php://input' );
		$data = json_decode( $json );

		if ( ! surfer_validate_custom_request( $data->_surfer_nonce ) ) {
			echo wp_json_encode( array( 'message' => 'Security check failed.' ) );
			wp_die();
		}

		echo wp_json_encode( Surfer()->get_surfer()->surfer_hardcoded_location() );
		wp_die();
	}

	/**
	 * Removes a draft connection.
	 */
	public function remove_post_draft_connection() {
		$json = file_get_contents( 'php://input' );
		$data = json_decode( $json );

		if ( ! surfer_validate_custom_request( $data->_surfer_nonce ) ) {
			echo wp_json_encode( array( 'message' => 'Security check failed.' ) );
			wp_die();
		}

		$post_id  = isset( $data->post_id ) ? intval( $data->post_id ) : false;
		$draft_id = isset( $data->draft_id ) ? intval( $data->draft_id ) : false;

		$params = array(
			'draft_id'   => $draft_id,
			'wp_post_id' => $post_id,
			'url'        => apply_filters( 'surfer_api_base_url', get_site_url() ),
		);

		list(
			'code'     => $code,
			'response' => $response,
		) = Surfer()->get_surfer()->make_surfer_request( '/disconnect_draft', $params );

		delete_post_meta( $post_id, 'surfer_draft_id' );
		delete_post_meta( $post_id, 'surfer_scrape_ready' );
		delete_post_meta( $post_id, 'surfer_permalink_hash' );
		delete_post_meta( $post_id, 'surfer_last_post_update' );
		delete_post_meta( $post_id, 'surfer_last_post_update_direction' );
		delete_post_meta( $post_id, 'surfer_keywords' );
		delete_post_meta( $post_id, 'surfer_location' );

		$response = array(
			'connection_removed' => true,
			'surfer_response'    => $response,
		);

		echo wp_json_encode( $response );
		wp_die();
	}

	/**
	 * Gets post sync status from WordPress and Surfer.
	 */
	public function get_post_sync_status() {
		$json = file_get_contents( 'php://input' );
		$data = json_decode( $json );

		if ( ! surfer_validate_custom_request( $data->_surfer_nonce ) ) {
			echo wp_json_encode( array( 'message' => 'Security check failed.' ) );
			wp_die();
		}

		$draft_id = isset( $data->draft_id ) ? intval( $data->draft_id ) : false;
		$post_id  = isset( $data->post_id ) ? intval( $data->post_id ) : false;

		$params = array(
			'draft_id' => $draft_id,
		);

		list(
			'code'     => $code,
			'response' => $response,
		) = Surfer()->get_surfer()->make_surfer_request( '/get_draft_sync_status', $params );

		if ( 200 === $code || 201 === $code ) {
			$response['code']                    = $code;
			$response['wp_last_update_date']     = get_the_modified_date( 'M d, Y H:i:s', $post_id );
			$response['keywords']                = get_post_meta( $post_id, 'surfer_keywords', true );
			$response['location']                = get_post_meta( $post_id, 'surfer_location', true );
			$response['surfer_last_update_date'] = gmdate( 'M d, Y H:i:s', strtotime( $response['surfer_last_update_date'] ) );

			$wp_last_sync_date = get_post_meta( $post_id, 'surfer_last_post_update', true );

			if ( strlen( $wp_last_sync_date ) > 10 ) {
				$wp_last_sync_date = substr( $wp_last_sync_date, 0, 10 );
			}

			// Should be the same, but we want to be sure!
			if ( strtotime( $response['last_sync_date'] ) <= $wp_last_sync_date ) {
				$response['last_sync_date']      = gmdate( 'M d, Y H:i:s', $wp_last_sync_date );
				$response['last_sync_direction'] = get_post_meta( $post_id, 'surfer_last_post_update_direction', true );
			}
		}

		echo wp_json_encode( $response );
		wp_die();
	}

	/**
	 * Gather posts to reconnect.
	 */
	public function gather_posts_to_reconnect() {

		if ( ! surfer_validate_ajax_request() || ! check_ajax_referer( 'surfer-ajax-nonce', '_surfer_nonce', false ) ) {
			echo wp_json_encode( array( 'message' => 'Security check failed.' ) );
			wp_die();
		}

		$args = array(
			'post_type'   => 'post',
			'post_status' => array( 'publish', 'draft' ),
			'numberposts' => -1,
			'meta_query'  => array(
				array(
					'key'     => 'surfer_draft_id',
					'value'   => '',
					'compare' => '!=',
				),
			),
		);

		$posts = get_posts( $args );

		$ids = array();
		foreach ( $posts as $post ) {
			$ids[] = $post->ID;
		}

		echo wp_json_encode( array( 'posts' => $ids ) );
		wp_die();
	}

	/**
	 * Reconnect posts with drafts.
	 */
	public function reconnect_posts_with_drafts() {

		if ( ! surfer_validate_ajax_request() || ! check_ajax_referer( 'surfer-ajax-nonce', '_surfer_nonce', false ) ) {
			echo wp_json_encode( array( 'message' => 'Security check failed.' ) );
			wp_die();
		}

		$posts = isset( $_POST['posts'] ) ? array_map( 'intval', wp_unslash( $_POST['posts'] ) ) : false;

		if ( ! $posts ) {
			echo wp_json_encode( array( 'message' => 'No posts to reconnect.' ) );
			wp_die();
		}

		$response = '';
		foreach ( $posts as $post_id ) {

			$draft_id = get_post_meta( $post_id, 'surfer_draft_id', true );
			$drafts   = $this->search_for_drafts( $post_id );
			$keyword  = get_post_meta( $post_id, 'surfer_keywords', true );

			if ( is_array( $keyword ) ) {
				$keyword = $keyword[0];
			}

			if ( ! isset( $drafts[ $draft_id ] ) ) {
				$response .= 'Post ' . $post_id . ' has no draft ' . $draft_id . ' in Surfer. Checked drafts: ' . count( $drafts ) . ' Search by keyword: ' . $keyword . PHP_EOL;
				continue;
			}
			$draft_details = $drafts[ $draft_id ];

			$this->save_post_surfer_details( $post_id, $draft_details['keyword'], $draft_details['location'], $draft_id, $draft_details['permalinkHash'] );
			$response .= 'Post ' . $post_id . ' reconnected with Draft ' . $draft_id . PHP_EOL;
		}

		echo wp_json_encode( $response );
		wp_die();
	}

	/**
	 * Searches for drafts in Surfer.
	 *
	 * @param int $post_id - ID of the post.
	 */
	private function search_for_drafts( $post_id ) {

		$keyword = get_post_meta( $post_id, 'surfer_keywords', true );

		if ( is_array( $keyword ) ) {
			$keyword = $keyword[0];
		}

		$params = array(
			'query_keyword' => $keyword,
			'per_page'      => 100,
		);

		list(
			'code'     => $code,
			'response' => $drafts_response,
		) = Surfer()->get_surfer()->make_surfer_request( '/get_user_drafts', $params );

		$drafts = array();
		if ( 200 === $code || 201 === $code ) {
			foreach ( $drafts_response['drafts'] as $draft ) {

				$drafts[ $draft['id'] ] = array(
					'keyword'       => $draft['keyword']['item'],
					'location'      => $draft['scrape']['location'],
					'permalinkHash' => $draft['permalink_hash'],
				);
			}
		}

		return $drafts;
	}

	/**
	 * Removes old backups.
	 */
	public function surfer_remove_old_backups() {

		if ( ! surfer_validate_ajax_request() || ! check_ajax_referer( 'surfer-ajax-nonce', '_surfer_nonce', false ) ) {
			echo wp_json_encode( array( 'message' => 'Security check failed.' ) );
			wp_die();
		}

		$posts = get_posts(
			array(
				'post_type'   => 'post',
				'post_status' => array( 'surfer-backup' ),
				'numberposts' => -1,
			)
		);

		foreach ( $posts as $post ) {
			wp_delete_post( $post->ID, true );
		}

		$response = count( $posts ) . ' of old backups removed.';

		echo wp_json_encode( $response );
		wp_die();
	}
}
