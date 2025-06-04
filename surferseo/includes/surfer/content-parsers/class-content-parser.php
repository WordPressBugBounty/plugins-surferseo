<?php
/**
 *  General Parser object, that handles all general parsing function.
 *
 * @package SurferSEO
 * @link https://surferseo.com
 */

namespace SurferSEO\Surfer\Content_Parsers;

/**
 * Object that imports data from different sources into WordPress.
 */
class Content_Parser {

	/**
	 * Title of the imported post.
	 *
	 * @var string
	 */
	protected $title;

	/**
	 * Image processing mode.
	 *
	 * @var string
	 */
	protected $image_processing_mode = 'sync';


	/**
	 * Returns content for chosen editor.
	 *
	 * @param string $content - Content from Surfer.
	 * @return string
	 */
	public function parse_content( $content ) {

		$this->title = wp_strip_all_tags( $this->get_title_from_content( $content ) );

		return $content;
	}

	/**
	 * Returns title of the imported post.
	 *
	 * @param string $content - Content from Surfer.
	 * @return string
	 */
	public function parse_title( $content ) {
		return $this->get_title_from_content( $content );
	}

	/**
	 * Returns title of the imported post.
	 *
	 * @return string
	 */
	public function return_title() {

		return $this->title;
	}

	/**
	 * Runs actions that require post ID
	 *
	 * @param int $post_id - ID of the post.
	 * @return void
	 */
	public function run_after_post_insert_actions( $post_id ) {
	}


	/**
	 * Extract h1 from content, to use it as post title.
	 *
	 * @param string $content - Content from Surfer.
	 * @return string
	 */
	protected function get_title_from_content( $content ) {

		preg_match( '~<h1[^>]*>(.*?)</h1>~i', $content, $match );
		$title = $match[1];

		return $title;
	}

	/**
	 * Download image to media library or queue for background processing.
	 *
	 * @param string $image_url - URL of the image.
	 * @param string $image_alt - Alt text for the image.
	 * @param bool   $url_only - return only URL.
	 * @return string
	 */
	protected function download_img_to_media_library( $image_url, $image_alt, $url_only = true ) {

		$existing_attachment = $this->find_existing_attachment( $image_url );
		if ( $existing_attachment ) {
			if ( $url_only ) {
				return wp_get_attachment_url( $existing_attachment );
			}
			return array(
				'url' => wp_get_attachment_url( $existing_attachment ),
				'id'  => $existing_attachment,
			);
		}

		if ( 'async' === $this->image_processing_mode ) {
			$this->queue_image_download( $image_url, $image_alt );
			if ( $url_only ) {
				return $image_url;
			}
			return array(
				'url' => $image_url,
				'id'  => 0,
			);
		}

		$image_upload = $this->download_image_sync( $image_url );

		if ( ! $image_upload ) {
			return $image_url;
		}

		if ( $url_only ) {
			return $image_upload['url'];
		}
		return $image_upload;
	}

	/**
	 * Queue image for background download.
	 *
	 * @param string $image_url - URL of the image.
	 * @param string $image_alt - Alt text for the image.
	 * @return void
	 */
	private function queue_image_download( $image_url, $image_alt ) {
		$queue   = get_option( 'surfer_image_download_queue', array() );
		$queue[] = array(
			'url'       => $image_url,
			'alt'       => $image_alt,
			'timestamp' => time(),
		);
		update_option( 'surfer_image_download_queue', $queue );

		if ( ! wp_next_scheduled( 'surfer_process_image_queue' ) ) {
			wp_schedule_single_event( time() + 5, 'surfer_process_image_queue' );
		}
	}

	/**
	 * Find existing attachment by URL.
	 *
	 * @param string $image_url - URL of the image.
	 * @return int|false
	 */
	private function find_existing_attachment( $image_url ) {
		global $wpdb;

		$attachment_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} 
			 WHERE meta_key = 'surfer_file_original_url' 
			 AND meta_value LIKE %s",
				$image_url
			)
		);

		return $attachment_id ? (int) $attachment_id : false;
	}

	/**
	 * Upload image from Surfer to WordPress and replace src to local one.
	 *
	 * @param string $image_url - URL to the image.
	 * @return int
	 */
	private function download_image_sync( $image_url ) {

		if ( empty( $image_url ) || ! wp_http_validate_url( $image_url ) ) {
			return 0;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$file_name     = basename( $image_url );
		$tmp_directory = download_url( $image_url );

		if ( is_wp_error( $tmp_directory ) ) {
			return 0;
		}

		$extension = pathinfo( $image_url, PATHINFO_EXTENSION );
		if ( empty( $extension ) || '' === $extension ) {
			$headers = get_headers( $image_url );
			foreach ( $headers as $header ) {
				if ( false !== strpos( $header, 'Content-Disposition' ) ) {
					preg_match( '~filename="(.*?)\.(.*?)"~i', $header, $match );
					$extension  = $match[2];
					$file_name .= '.' . $match[2];
					break;
				}
			}
		}

		$file_array = array(
			'tmp_name' => $tmp_directory,
			'name'     => $file_name,
			'type'     => 'image/' . $extension,
		);

		$post_data = array(
			'post_mime_type' => 'image/' . $extension,
		);

		$attachment_id = media_handle_sideload( $file_array, 0, null, $post_data );
		update_post_meta( $attachment_id, 'surfer_file_name', $file_name );
		update_post_meta( $attachment_id, 'surfer_file_original_url', $image_url );
		@unlink( $tmp_directory ); // phpcs:ignore

		return array(
			'url' => wp_get_attachment_url( $attachment_id ),
			'id'  => $attachment_id,
		);
	}

	/**
	 * Updates alt param for image.
	 *
	 * @param int    $image_id - ID of the attachment to update.
	 * @param string $image_alt - possible alt attribute for image.
	 * @return void
	 */
	private function update_image_alt( $image_id, $image_alt = '' ) {

		if ( '' !== $image_alt ) {
			update_post_meta( $image_id, '_wp_attachment_image_alt', trim( $image_alt ) );
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
	protected function get_inner_html( $node ) {
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
	 * Handles target attribute for links.
	 * If link is internal, removes target attribute.
	 * If link is external, adds target="_blank" attribute.
	 *
	 * @param DOMDocument $doc - DOMDocument object.
	 * @return DOMDocument
	 */
	protected function handle_links_target_attribute( $doc ) {

		$links = $doc->getElementsByTagName( 'a' );

		$internal_links_rel    = Surfer()->get_surfer_settings()->get_option( 'content-importer', 'internal_links_rel', false );
		$internal_links_target = Surfer()->get_surfer_settings()->get_option( 'content-importer', 'internal_links_target', '_self' );
		$external_links_rel    = Surfer()->get_surfer_settings()->get_option( 'content-importer', 'external_links_rel', false );
		$external_links_target = Surfer()->get_surfer_settings()->get_option( 'content-importer', 'external_links_target', '_blank' );

		foreach ( $links as $link ) {
			$link_url = $link->getAttribute( 'href' );

			$link->removeAttribute( 'target' );
			if ( false !== strpos( $link_url, rtrim( get_home_url(), '/' ) ) ) {
				$link->setAttribute( 'target', $internal_links_target );

				if ( $internal_links_rel ) {
					$link->removeAttribute( 'rel' );
					$link->setAttribute( 'rel', join( ' ', $internal_links_rel ) );
				}
			}

			if ( false === strpos( $link_url, rtrim( get_home_url(), '/' ) ) ) {
				$link->setAttribute( 'target', $external_links_target );

				if ( $external_links_rel ) {
					$link->removeAttribute( 'rel' );
					$link->setAttribute( 'rel', join( ' ', $external_links_rel ) );
				}
			}
		}

		return $doc;
	}


	/**
	 * Get image processing mode.
	 *
	 * @param string $processing_mode - processing mode.
	 * @return void
	 */
	public function set_image_processing_mode( $processing_mode ) {
		$this->image_processing_mode = $processing_mode;
	}
}
