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
		add_filter( 'wp_ajax_surfer_get_post_sync_status', array( $this, 'get_post_sync_status' ) );

		add_filter( 'wp_ajax_surfer_gather_posts_to_reconnect', array( $this, 'gather_posts_to_reconnect' ) );
		add_filter( 'wp_ajax_surfer_reconnect_posts_with_drafts', array( $this, 'reconnect_posts_with_drafts' ) );
	}

	/**
	 * Add export content button to post/page list.
	 *
	 * @param array   $actions - actions array.
	 * @param \WP_Post $post - post object.
	 */
	public function add_export_content_button_to_posts_list( $actions, $post ) {
		$draft_id = get_post_meta( $post->ID, 'surfer_draft_id', true );

		if ( $draft_id ) {
			$actions['export_to_surfer'] = '<a href="' . Surfer()->get_surfer()->get_surfer_url() . '/drafts/' . intval( $draft_id ) . '" >' . __( 'Check in Surfer', 'surferseo' ) . '</a>';
		}

		return $actions;
	}

	/**
	 * Returns ID of the post from the request, when the current user is allowed to sync it with Surfer.
	 *
	 * Request is denied when `post_id` is missing, the post does not exist, its type is not
	 * supported by Surfer, the user cannot edit it, or the nonce is wrong.
	 *
	 * @param object|null $data - decoded JSON body of the request.
	 * @return int - ID of the post, 0 when the request is not allowed.
	 */
	private function get_authorized_post_id( $data ) {

		$nonce   = isset( $data->_surfer_nonce ) ? $data->_surfer_nonce : '';
		$post_id = isset( $data->post_id ) ? intval( $data->post_id ) : 0;
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, surfer_return_supported_post_types(), true ) ) {
			return 0;
		}

		if ( ! surfer_validate_custom_request( $nonce, 'surfer-ajax-nonce', 'edit_post', $post->ID ) ) {
			return 0;
		}

		return $post->ID;
	}

	/**
	 * Returns ID of the Surfer draft connected with the post.
	 *
	 * Handlers that read or change an existing connection use this value only - a `draft_id`
	 * sent in the request is ignored, so a user who can edit one post cannot reach a draft
	 * connected with another post.
	 *
	 * @param int $post_id - ID of the post.
	 * @return int - ID of the draft, 0 when the post is not connected.
	 */
	private function get_connected_draft_id( $post_id ) {

		$draft_id = intval( get_post_meta( $post_id, 'surfer_draft_id', true ) );

		return $draft_id > 0 ? $draft_id : 0;
	}

	/**
	 * Checks if another post is connected with the Surfer draft.
	 *
	 * Every post type and every status except trash (and auto-draft and revisions, which never
	 * get the connection) counts - a draft, pending, scheduled or private post keeps
	 * its connection the same way a published one does. A trashed post does not block the draft,
	 * so it can be connected with a post that replaces the trashed one.
	 *
	 * @param int $draft_id - ID of the Surfer draft.
	 * @param int $post_id - ID of the post that should be skipped.
	 * @return bool
	 */
	private function draft_is_connected_with_another_post( $draft_id, $post_id ) {

		$posts = get_posts(
			array(
				'post_type'        => array_values( get_post_types() ),
				'post_status'      => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'post__not_in'     => array( $post_id ),
				'numberposts'      => 1,
				'fields'           => 'ids',
				'meta_query'       => array(
					array(
						'key'   => 'surfer_draft_id',
						'value' => (string) $draft_id,
					),
				),
			)
		);

		return ! empty( $posts );
	}

	/**
	 * Makes export content to Surfer.
	 */
	public function create_content_editor() {
		$data = surfer_get_json_request_body();

		if ( 0 === $this->get_authorized_post_id( $data ) ) {
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
		$data    = surfer_get_json_request_body();
		$post_id = $this->get_authorized_post_id( $data );

		if ( 0 === $post_id ) {
			echo wp_json_encode( array( 'message' => 'Security check failed.' ) );
			wp_die();
		}

		// The draft from the request can be the one already connected with this post, or
		// a draft to connect with a post that has none - never a draft of another post.
		$draft_id           = isset( $data->draft_id ) ? intval( $data->draft_id ) : 0;
		$connected_draft_id = $this->get_connected_draft_id( $post_id );

		if ( $draft_id < 1 || ( $connected_draft_id > 0 && $connected_draft_id !== $draft_id ) ) {
			echo wp_json_encode( array( 'message' => 'Security check failed.' ) );
			wp_die();
		}

		if ( $this->draft_is_connected_with_another_post( $draft_id, $post_id ) ) {
			echo wp_json_encode( array( 'message' => 'This Content Editor is already connected with another post. Disconnect it there first.' ) );
			wp_die();
		}

		try {
			$content = isset( $data->content ) ? wp_kses_post( $this->parse_content_for_surfer( $data->content ) ) : false;

			$keywords = isset( $data->keywords ) ? $data->keywords : false;

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
				update_post_meta( $post_id, 'surfer_last_post_update', round( microtime( true ) * 1000 ) );
				update_post_meta( $post_id, 'surfer_last_post_update_direction', 'from WordPress to Surfer' );

				$this->save_post_surfer_details( $post_id, $keywords, $location, $draft_id, $permalink_hash );
			}

			echo wp_json_encode( $response );
			wp_die();

		} catch ( \Exception $e ) {
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
		$data    = surfer_get_json_request_body();
		$post_id = $this->get_authorized_post_id( $data );

		if ( 0 === $post_id ) {
			echo wp_json_encode( array( 'message' => 'Security check failed.' ) );
			wp_die();
		}

		$draft_id = $this->get_connected_draft_id( $post_id );

		if ( 0 === $draft_id ) {
			echo wp_json_encode( array( 'message' => 'Post is not connected with a Surfer draft.' ) );
			wp_die();
		}

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
	 * @param \DOMDocument $parent_node - node to parse.
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
				/** @var \DOMElement $node An <img> node is always an element. */
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
			$attributes .= $key . '="' . esc_attr( $value ) . '" ';
		}
		$attributes = rtrim( $attributes );

		return $attributes;
	}

	/**
	 * Extract inner HTML for provided node.
	 *
	 * @param \DOMElement $node - node element to parse.
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
	 * Removes a draft connection.
	 */
	public function remove_post_draft_connection() {
		$data    = surfer_get_json_request_body();
		$post_id = $this->get_authorized_post_id( $data );

		if ( 0 === $post_id ) {
			echo wp_json_encode( array( 'message' => 'Security check failed.' ) );
			wp_die();
		}

		$draft_id = $this->get_connected_draft_id( $post_id );

		// Nothing to disconnect in Surfer - the answer has the same shape as a failed disconnection.
		if ( 0 === $draft_id ) {
			echo wp_json_encode(
				array(
					'connection_removed' => false,
					'surfer_response'    => array( 'message' => 'Post is not connected with a Surfer draft.' ),
				)
			);
			wp_die();
		}

		$params = array(
			'draft_id'   => $draft_id,
			'wp_post_id' => $post_id,
			'url'        => apply_filters( 'surfer_api_base_url', get_site_url() ),
		);

		$result   = Surfer()->get_surfer()->make_surfer_request( '/disconnect_draft', $params );
		$code     = isset( $result['code'] ) ? $result['code'] : null;
		$response = isset( $result['response'] ) ? $result['response'] : $result;

		// Connection is kept in WordPress until Surfer confirms it was removed (page not connected - no `code`).
		if ( 200 !== $code && 201 !== $code ) {
			echo wp_json_encode(
				array(
					'connection_removed' => false,
					'surfer_response'    => $response,
				)
			);
			wp_die();
		}

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
		$data    = surfer_get_json_request_body();
		$post_id = $this->get_authorized_post_id( $data );

		if ( 0 === $post_id ) {
			echo wp_json_encode( array( 'message' => 'Security check failed.' ) );
			wp_die();
		}

		$draft_id = $this->get_connected_draft_id( $post_id );

		if ( 0 === $draft_id ) {
			echo wp_json_encode( array( 'message' => 'Post is not connected with a Surfer draft.' ) );
			wp_die();
		}

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

		if ( ! surfer_validate_ajax_request() ) {
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

		if ( ! surfer_validate_ajax_request() ) {
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
}
