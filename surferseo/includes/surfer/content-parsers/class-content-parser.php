<?php
/**
 *  General Parser object, that handles all general parsing function.
 *
 * @package SurferSEO
 * @link https://surferseo.com
 */

namespace SurferSEO\Surfer\Content_Parsers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
		$content     = $this->clear_lists( $content );

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

		$existing_attachment = surfer_find_attachment_by_original_url( $image_url );
		if ( $existing_attachment ) {
			surfer_update_image_alt( $existing_attachment, $image_alt );
			if ( $url_only ) {
				return wp_get_attachment_url( $existing_attachment );
			}
			return array(
				'url' => wp_get_attachment_url( $existing_attachment ),
				'id'  => $existing_attachment,
			);
		}

		// The Surfer URL stays in the content only until the background job downloads the
		// image and replaces it - see Surfer_Image_Processor.
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

		// A failed image is replaced with a placeholder instead of being hotlinked to
		// Surfer - otherwise the post looks fine and nobody notices that the media
		// library is empty. The reason is logged in download_image_sync().
		if ( ! $image_upload ) {
			if ( $url_only ) {
				return surfer_get_failed_image_url();
			}
			return array(
				'url' => surfer_get_failed_image_url(),
				'id'  => 0,
			);
		}

		surfer_update_image_alt( $image_upload['id'], $image_alt );

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

		$lock_acquired = surfer_acquire_lock( 'image_queue' );

		$queue = get_option( 'surfer_image_download_queue', array() );

		if ( ! $this->is_image_queued( $queue, $image_url ) ) {
			$queue[] = array(
				'url'       => $image_url,
				'alt'       => $image_alt,
				'timestamp' => time(),
			);
			update_option( 'surfer_image_download_queue', $queue, false );
		}

		if ( $lock_acquired ) {
			surfer_release_lock( 'image_queue' );
		}

		if ( ! wp_next_scheduled( 'surfer_process_image_queue' ) ) {
			wp_schedule_single_event( time() + 5, 'surfer_process_image_queue' );
		}
	}

	/**
	 * Checks if image is already waiting in the queue.
	 *
	 * @param array  $queue - current queue.
	 * @param string $image_url - URL of the image.
	 * @return bool
	 */
	private function is_image_queued( $queue, $image_url ) {

		if ( ! is_array( $queue ) ) {
			return false;
		}

		foreach ( $queue as $item ) {
			if ( isset( $item['url'] ) && $item['url'] === $image_url ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Upload image from Surfer to WordPress and replace src to local one.
	 *
	 * @param string $image_url - URL to the image.
	 * @return array|int - array with attachment URL and ID, 0 on failure.
	 */
	private function download_image_sync( $image_url ) {

		if ( empty( $image_url ) || ! wp_http_validate_url( $image_url ) ) {
			surfer_log_image_import_error( $image_url, 'invalid image URL' );
			return 0;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp_file = download_url( $image_url );

		if ( is_wp_error( $tmp_file ) ) {
			surfer_log_image_import_error( $image_url, $tmp_file->get_error_message() );
			return 0;
		}

		$file_name = surfer_resolve_image_file_name( $image_url, $tmp_file );

		// Mime type is intentionally not provided - WordPress detects it from the
		// downloaded file, so images from URLs without an extension are not rejected.
		$attachment_id = media_handle_sideload(
			array(
				'tmp_name' => $tmp_file,
				'name'     => $file_name,
			),
			0
		);

		if ( file_exists( $tmp_file ) ) {
			@unlink( $tmp_file ); // phpcs:ignore
		}

		if ( is_wp_error( $attachment_id ) || empty( $attachment_id ) ) {
			$error_message = is_wp_error( $attachment_id ) ? $attachment_id->get_error_message() : 'attachment was not created';
			surfer_log_image_import_error( $image_url, $error_message );
			return 0;
		}

		update_post_meta( $attachment_id, 'surfer_file_name', $file_name );
		update_post_meta( $attachment_id, 'surfer_file_original_url', $image_url );
		surfer_forget_attachment_by_original_url( $image_url );

		return array(
			'url' => wp_get_attachment_url( $attachment_id ),
			'id'  => $attachment_id,
		);
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
			// Values come decoded from DOMDocument, so they have to be escaped again -
			// otherwise a quote in alt or title breaks the whole attribute.
			$attributes .= $key . '="' . esc_attr( $value ) . '" ';
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
					$link->setAttribute( 'rel', is_array( $internal_links_rel ) ? join( ' ', $internal_links_rel ) : $internal_links_rel );
				}
			}

			if ( false === strpos( $link_url, rtrim( get_home_url(), '/' ) ) ) {
				$link->setAttribute( 'target', $external_links_target );

				if ( $external_links_rel ) {
					$link->removeAttribute( 'rel' );
					$link->setAttribute( 'rel', is_array( $external_links_rel ) ? join( ' ', $external_links_rel ) : $external_links_rel );
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

	/**
	 * Clear lists from content.
	 *
	 * @param string $content - content to clear.
	 * @return string
	 */
	protected function clear_lists( $content ) {
		$content = preg_replace_callback(
			'/(<li[^>]*>)(.*?)(<\/li>)/is',
			function ( $matches ) {
				$li_open    = $matches[1];
				$li_content = $matches[2];
				$li_close   = $matches[3];

				$li_content = preg_replace( '/<\/?p[^>]*>/i', '', $li_content );

				return $li_open . $li_content . $li_close;
			},
			$content
		);

		return $content;
	}
}
