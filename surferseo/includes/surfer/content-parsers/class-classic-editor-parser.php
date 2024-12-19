<?php
/**
 *  Parser that prepare data for Classic Editor.
 *
 * @package SurferSEO
 * @link https://surferseo.com
 */

namespace SurferSEO\Surfer\Content_Parsers;

use DOMDocument;

/**
 * Object that imports data from different sources into WordPress.
 */
class Classic_Editor_Parser extends Content_Parser {


	/**
	 * Parse content from Surfer to Classic Editor.
	 *
	 * @param string $content - Content from Surfer.
	 * @return string
	 */
	public function parse_content( $content ) {

		parent::parse_content( $content );

		$content = wp_unslash( $content );

		$content = $this->parse_img_for_classic_editor( $content );
		return $content;
	}

	/**
	 * Parse images for classic editor.
	 *
	 * @param string $content - whole content.
	 * @return string content where <img> URLs are corrected to media library.
	 */
	private function parse_img_for_classic_editor( $content ) {

		$doc = new DOMDocument();

		$utf8_fix_prefix = '<html><head><meta http-equiv="content-type" content="text/html; charset=utf-8" /></head><body>';
		$utf8_fix_suffix = '</body></html>';

		$doc->loadHTML( $utf8_fix_prefix . $content . $utf8_fix_suffix, LIBXML_HTML_NODEFDTD | LIBXML_SCHEMA_CREATE );

		$images = $doc->getElementsByTagName( 'img' );

		foreach ( $images as $image ) {
			$image_url = $image->getAttribute( 'src' );
			$image_alt = $image->getAttribute( 'alt' );

			$media_library_image_url = $this->download_img_to_media_library( $image_url, $image_alt );
			$image->setAttribute( 'src', $media_library_image_url );
		}

		$doc = $this->handle_links_target_attribute( $doc );

		$h1s = $doc->getElementsByTagName( 'h1' );

		foreach ( $h1s as $h1 ) {
			$h1 = $h1s->item( 0 );
			$h1->parentNode->removeChild( $h1 );
		}

		$parsed_content = $doc->saveHTML();

		return $parsed_content;
	}
}
