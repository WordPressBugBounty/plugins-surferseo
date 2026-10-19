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
	 * Receives images left for the background download - see Parsers_Controller.
	 *
	 * Without it images are always downloaded during the import, because nothing would
	 * queue them.
	 *
	 * @var callable|null
	 */
	protected $pending_image_handler = null;

	/**
	 * Decides if another image of the import may be downloaded or queued - see Parsers_Controller.
	 *
	 * Called with the image URL, returns false when the import reached its image limit.
	 * Without it the number of images is not limited.
	 *
	 * @var callable|null
	 */
	protected $image_limit_handler = null;


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
	 * @return string|array
	 */
	protected function download_img_to_media_library( $image_url, $image_alt, $url_only = true ) {

		$existing_attachment = surfer_find_attachment_by_original_url( $image_url );
		if ( $existing_attachment ) {
			surfer_update_image_alt( $existing_attachment, $image_alt );
			return $this->image_result( wp_get_attachment_url( $existing_attachment ), $existing_attachment, $url_only );
		}

		// One import downloads or queues a limited number of images - the rest keep their
		// Surfer URLs. The limit is logged once per import, by the handler.
		if ( null !== $this->image_limit_handler && ! call_user_func( $this->image_limit_handler, $image_url ) ) {
			return $this->image_result( $image_url, 0, $url_only );
		}

		// The Surfer URL stays in the content only until the background job downloads the
		// image and replaces it - see Surfer_Image_Processor. The image is only remembered
		// here: it is queued by the importer once the post is saved and has an ID, so the
		// worker changes only that post.
		if ( 'async' === $this->image_processing_mode && null !== $this->pending_image_handler ) {
			call_user_func( $this->pending_image_handler, $image_url, $image_alt );
			return $this->image_result( $image_url, 0, $url_only );
		}

		$result = $this->download_image_sync( $image_url, $image_alt );

		if ( 'ok' === $result['status'] && ! empty( $result['attachment_id'] ) ) {
			return $this->image_result( wp_get_attachment_url( $result['attachment_id'] ), $result['attachment_id'], $url_only );
		}

		// A file that is not an image is not imported at all - the content keeps its URL.
		if ( 'not_image' === $result['status'] ) {
			return $this->image_result( $image_url, 0, $url_only );
		}

		// A failed image is replaced with a placeholder instead of being hotlinked to
		// Surfer - otherwise the post looks fine and nobody notices that the media
		// library is empty. The reason is logged by surfer_download_image().
		return $this->image_result( surfer_get_failed_image_url(), 0, $url_only );
	}

	/**
	 * Returns image data in the form the parser asked for.
	 *
	 * @param string|false $url - URL of the image.
	 * @param int          $attachment_id - ID of the attachment, 0 when there is none.
	 * @param bool         $url_only - return only URL.
	 * @return string|array
	 */
	private function image_result( $url, $attachment_id, $url_only ) {

		if ( $url_only ) {
			return $url;
		}

		return array(
			'url' => $url,
			'id'  => (int) $attachment_id,
		);
	}

	/**
	 * Upload image from Surfer to WordPress media library.
	 *
	 * @param string $image_url - URL to the image.
	 * @param string $image_alt - Alt text for the image.
	 * @return array{status: string, attachment_id?: int} - see surfer_download_image().
	 */
	private function download_image_sync( $image_url, $image_alt ) {
		return surfer_download_image( (string) $image_url, (string) $image_alt );
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
	 * @param \DOMElement $node - node element to parse.
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
	 * @param \DOMDocument $doc - DOMDocument object.
	 * @return \DOMDocument
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
	 * Sets the callback that receives images left for the background download.
	 *
	 * @param callable $handler - called with image URL and alt text.
	 * @return void
	 */
	public function set_pending_image_handler( callable $handler ) {
		$this->pending_image_handler = $handler;
	}

	/**
	 * Sets the callback that decides if another image of the import may be downloaded.
	 *
	 * @param callable $handler - called with image URL, returns bool.
	 * @return void
	 */
	public function set_image_limit_handler( callable $handler ) {
		$this->image_limit_handler = $handler;
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
