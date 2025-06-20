<?php
/**
 *  Parser that prepare data for Elementor
 *
 * @package SurferSEO
 * @link https://surferseo.com
 */

namespace SurferSEO\Surfer\Content_Parsers;

use DOMDocument;
use Elementor\Frontend;

/**
 * Object that imports data from different sources into WordPress.
 */
class Elementor_Parser extends Content_Parser {

	/**
	 * Parse content from Surfer to Gutenberg Editor.
	 *
	 * @param string $content  - Content from Surfer.
	 * @return string
	 */
	public function parse_content( $content ) {

		parent::parse_content( $content );
		$content = wp_unslash( $this->parse_default_content( $content ) );

		return $content;
	}


	/**
	 * Parse images for classic editor.
	 *
	 * @param string $content - whole content.
	 * @return string content where <img> URLs are corrected to media library.
	 */
	private function parse_default_content( $content ) {

		$doc = new DOMDocument();
		$doc->loadHTML( $content );

		$h1s = $doc->getElementsByTagName( 'h1' );

		foreach ( $h1s as $h1 ) {
			$h1_text = $this->get_inner_html( $h1 );
			if ( wp_strip_all_tags( $h1_text ) === $this->title ) {
				// @codingStandardsIgnoreLine
				$h1_string = $h1->ownerDocument->saveXML( $h1 );
				$content   = str_replace( $h1_string, '', $content );
			}
		}

		return $content;
	}

	/**
	 * Creates actual Elementor content.
	 *
	 * @param int $post_id - ID of the post.
	 * @return void
	 */
	public function run_after_post_insert_actions( $post_id ) {

		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			return;
		}

		$page_template      = Surfer()->get_surfer_settings()->get_option( 'content-importer', 'default_page_template', 'default' );
		$elementor_template = Surfer()->get_surfer_settings()->get_option( 'content-importer', 'default_elementor_template', 'default' );

		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post_id, '_elementor_template_type', ( $elementor_template ) ? 'page' : 'wp-post' );
		update_post_meta( $post_id, '_elementor_version', ELEMENTOR_VERSION );
		update_post_meta( $post_id, '_elementor_data', $this->get_elementor_data( $post_id ) );
		update_post_meta( $post_id, '_wp_page_template', $page_template );
	}

	/**
	 * Returns page assets for Elementor post.
	 *
	 * @param int $post_id - ID of the post.
	 * @return string
	 */
	private function get_elementor_data( $post_id ) {

		$content = wp_unslash( get_the_content( null, false, $post_id ) );

		$doc = new DOMDocument();

		$utf8_fix_prefix = '<html><head><meta http-equiv="content-type" content="text/html; charset=utf-8" /></head><body>';
		$utf8_fix_suffix = '</body></html>';

		$doc->loadHTML( $utf8_fix_prefix . $content . $utf8_fix_suffix, LIBXML_HTML_NODEFDTD | LIBXML_SCHEMA_CREATE );

		$doc = $this->remove_p_from_lists( $doc );

		$settings                 = new \stdClass();
		$settings->flex_direction = 'column';

		$elementor_styling = array();

		$elementor_template = Surfer()->get_surfer_settings()->get_option( 'content-importer', 'default_elementor_template', 'default' );
		if ( $elementor_template > 0 ) {
			$template_post = get_post( $elementor_template );
			if ( isset( $template_post ) && $template_post->ID > 0 ) {
				$template = json_decode( get_post_meta( $template_post->ID, '_elementor_data', true ) )[0];

				if ( isset( $template->elType ) && 'container' === $template->elType ) {  // @codingStandardsIgnoreLine
					$settings = $template->settings;
				}

				foreach ( $template->elements as $element ) {
					if ( isset( $element->elType ) && 'widget' === $element->elType ) {  // @codingStandardsIgnoreLine
						if ( 'heading' === $element->widgetType ) {  // @codingStandardsIgnoreLine
							$elementor_styling[ ( $element->settings->header_size ) ? $element->settings->header_size : 'h2' ] = $element->settings; // @codingStandardsIgnoreLine
						} else {
							$elementor_styling[ $element->widgetType ] = $element->settings; // @codingStandardsIgnoreLine
						}
					}
				}
			}
		}

		$document           = new \stdClass();
		$document->id       = substr( wp_generate_uuid4(), 0, 8 );
		$document->elType   = 'container'; // @codingStandardsIgnoreLine
		$document->settings = $settings;
		$document->elements = array();
		$document->isInner  = null; // @codingStandardsIgnoreLine

		$this->parse_dom_node( $doc, $document->elements, $elementor_styling );

		return wp_json_encode( array( $document ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}


	/**
	 * Function iterates by HTML tags in provided content.
	 *
	 * @param DOMDocument $parent_node - node to parse.
	 * @param array       $content     - reference to content variable, to store Gutenberg output.
	 * @param array       $elementor_styling - array with Elementor styling.
	 * @return void
	 */
	private function parse_dom_node( $parent_node, &$content, $elementor_styling = array() ) {
		// @codingStandardsIgnoreLine
		foreach ( $parent_node->childNodes as $node ) {

			// @codingStandardsIgnoreLine
			$execute_for_child = $this->check_if_execute_recurrence( $node->nodeName );

			$parsed_node = $this->parse_certain_node_type( $node, $elementor_styling );
			if ( '' !== $parsed_node && ! empty( $parsed_node ) ) {
				$content[] = $parsed_node;
			}

			if ( $execute_for_child && $node->hasChildNodes() ) {
				$this->parse_dom_node( $node, $content, $elementor_styling );
			}
		}
	}

	/**
	 * Function checks if we want to dig deep into content scheme.
	 *
	 * @param string $node_type - name of the node, example: ul, p, h1.
	 * @return bool
	 */
	private function check_if_execute_recurrence( $node_type ) {

		$execute_for_child = true;

		if ( in_array( $node_type, array( 'ul', 'ol', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'h7', 'img', 'blockquote' ), true ) ) {
			$execute_for_child = false;
		}

		return $execute_for_child;
	}

	/**
	 * Function prepares attributes and run correct parser function for certain node type.
	 *
	 * @param DOMElement $node - node to parse.
	 * @param array      $elementor_styling - array with Elementor styling.
	 * @return string
	 */
	private function parse_certain_node_type( $node, $elementor_styling ) {

		// @codingStandardsIgnoreLine
		$node_name = $node->nodeName;

		if ( 'p' === $node_name ) {
			return $this->parse_node_p( $node, $elementor_styling );
		}

		if ( 'ul' === $node_name ) {
			return $this->parse_node_ul( $node, $elementor_styling );
		}

		if ( 'ol' === $node_name ) {
			return $this->parse_node_ol( $node, $elementor_styling );
		}

		if ( 0 === strpos( $node_name, 'h' ) && 'html' !== $node_name && 'hr' !== $node_name && 'head' !== $node_name ) {
			return $this->parse_node_h( $node, $elementor_styling );
		}

		if ( 'img' === $node_name ) {
			return $this->parse_node_img( $node, $elementor_styling );
		}

		if ( 'blockquote' === $node_name ) {
			return $this->parse_node_blockquote( $node, $elementor_styling );
		}

		if ( 'table' === $node_name ) {
			return $this->parse_node_table( $node, $elementor_styling );
		}

		return '';
	}

	/**
	 * Parses <p> node.
	 *
	 * @param DOMElement $node - node to parse.
	 * @param array      $elementor_styling - array with Elementor styling.
	 * @return stdClass
	 */
	private function parse_node_p( $node, $elementor_styling ) {

		$attributes = $this->parse_node_attributes( $node );

		$element         = new \stdClass();
		$element->id     = substr( wp_generate_uuid4(), 0, 8 );
		$element->elType = 'widget'; // @codingStandardsIgnoreLine

		$settings = new \stdClass();
		if ( isset( $elementor_styling['text-editor'] ) ) {
			$settings = clone $elementor_styling['text-editor'];
		}
		$settings->editor = '<p>' . str_replace( "\'", "'", addslashes( $this->get_inner_html( $node ) ) ) . '</p>';

		if ( isset( $attributes['align'] ) ) {
			$settings->align = $attributes['align'];
		}

		$element->settings = $settings;

		$element->elements   = array();
		$element->widgetType = 'text-editor'; // @codingStandardsIgnoreLine

		return $element;
	}

	/**
	 * Parses <ul> node.
	 *
	 * @param DOMElement $node - node to parse.
	 * @param array      $elementor_styling - array with Elementor styling.
	 * @return stdClass
	 */
	private function parse_node_ul( $node, $elementor_styling ) {

		$attributes = $this->parse_node_attributes( $node );

		$element         = new \stdClass();
		$element->id     = substr( wp_generate_uuid4(), 0, 8 );
		$element->elType = 'widget'; // @codingStandardsIgnoreLine

		$settings = new \stdClass();
		if ( isset( $elementor_styling['text-editor'] ) ) {
			$settings = clone $elementor_styling['text-editor'];
		}
		$settings->editor = '<ul>' . str_replace( "\'", "'", addslashes( $this->get_inner_html( $node ) ) ) . '</ul>';

		if ( isset( $attributes['align'] ) ) {
			$settings->align = $attributes['align'];
		}

		$element->settings = $settings;

		$element->elements   = array();
		$element->widgetType = 'text-editor'; // @codingStandardsIgnoreLine

		return $element;
	}

	/**
	 * Parses <ol> node.
	 *
	 * @param DOMElement $node - node to parse.
	 * @param array      $elementor_styling - array with Elementor styling.
	 * @return stdClass
	 */
	private function parse_node_ol( $node, $elementor_styling ) {

		$attributes = $this->parse_node_attributes( $node );

		$element         = new \stdClass();
		$element->id     = substr( wp_generate_uuid4(), 0, 8 );
		$element->elType = 'widget'; // @codingStandardsIgnoreLine

		$settings = new \stdClass();
		if ( isset( $elementor_styling['text-editor'] ) ) {
			$settings = clone $elementor_styling['text-editor'];
		}
		$settings->editor = '<ol>' . str_replace( "\'", "'", addslashes( $this->get_inner_html( $node ) ) ) . '</ol>';

		if ( isset( $attributes['align'] ) ) {
			$settings->align = $attributes['align'];
		}

		$element->settings = $settings;

		$element->elements   = array();
		$element->widgetType = 'text-editor'; // @codingStandardsIgnoreLine

		return $element;
	}

	/**
	 * Parses <h1-6> nodes
	 *
	 * @param DOMElement $node - node to parse.
	 * @param array      $elementor_styling - array with Elementor styling.
	 * @return string
	 */
	private function parse_node_h( $node, $elementor_styling ) {

		$element         = new \stdClass();
		$element->id     = substr( wp_generate_uuid4(), 0, 8 );
		$element->elType = 'widget'; // @codingStandardsIgnoreLine

		// @codingStandardsIgnoreLine
		$node_name = $node->nodeName;

		$settings = new \stdClass();
		if ( isset( $elementor_styling[ $node_name ] ) ) {
			$settings = clone $elementor_styling[ $node_name ];
		}

		$settings->title       = str_replace( "\'", "'", addslashes( $this->get_inner_html( $node ) ) );
		$settings->header_size = $node_name;
		$element->settings     = $settings;

		$element->elements   = array();
		$element->widgetType = 'heading'; // @codingStandardsIgnoreLine

		return $element;
	}

	/**
	 * Parses <img> node.
	 *
	 * @param DOMElement $node - node to parse.
	 * @param array      $elementor_styling - array with Elementor styling.
	 * @return string
	 */
	private function parse_node_img( $node, $elementor_styling ) {

		$attributes = $this->parse_node_attributes( $node );

		$image_url = '';
		$image_alt = '';

		if ( isset( $attributes['src'] ) && ! empty( $attributes['src'] ) ) {
			$image_url = $attributes['src'];
		}

		if ( isset( $attributes['alt'] ) && ! empty( $attributes['alt'] ) ) {
			$image_alt = $attributes['alt'];
		}

		$image_data = $this->download_img_to_media_library( $image_url, $image_alt, false );
		$image_url  = $image_data['url'];
		$image_id   = $image_data['id'];

		$element         = new \stdClass();
		$element->id     = substr( wp_generate_uuid4(), 0, 8 );
		$element->elType = 'widget'; // @codingStandardsIgnoreLine

		$image         = new \stdClass();
		$image->url    = $image_url;
		$image->id     = $image_id;
		$image->size   = null;
		$image->alt    = $image_alt;
		$image->source = 'library';

		$settings = new \stdClass();
		if ( isset( $elementor_styling['image'] ) ) {
			$settings = clone $elementor_styling['image'];
		}
		$settings->title   = 'Header';
		$settings->image   = $image;
		$element->settings = $settings;

		$element->elements   = array();
		$element->widgetType = 'image'; // @codingStandardsIgnoreLine

		return $element;
	}

	/**
	 * Parses <blockquote> node.
	 *
	 * @param DOMElement $node - node to parse.
	 * @param array      $elementor_styling - array with Elementor styling.
	 * @return string
	 */
	private function parse_node_blockquote( $node, $elementor_styling ) {

		$node_content = $this->get_inner_html( $node );
		if ( empty( $node_content ) || '' === $node_content ) {
			return '';
		}

		$element         = new \stdClass();
		$element->id     = substr( wp_generate_uuid4(), 0, 8 );
		$element->elType = 'widget'; // @codingStandardsIgnoreLine

		$settings = new \stdClass();
		if ( isset( $elementor_styling['html'] ) ) {
			$settings = clone $elementor_styling['html'];
		}
		$settings->html    = $node_content;
		$element->settings = $settings;

		$element->elements   = array();
		$element->widgetType = 'html'; // @codingStandardsIgnoreLine

		return $element;
	}


	/**
	 * Parses <table> node.
	 *
	 * @param DOMNode|DOMDocument|DOMElement $node                - node to parse.
	 * @param array                          $elementor_styling - array with Elementor styling.
	 * @return string
	 */
	private function parse_node_table( $node, $elementor_styling ) {

		$node_content = $this->get_inner_html( $node );
		if ( empty( $node_content ) || '' === $node_content ) {
			return '';
		}

		$element         = new \stdClass();
		$element->id     = substr( wp_generate_uuid4(), 0, 8 );
		$element->elType = 'widget'; // @codingStandardsIgnoreLine

		$settings = new \stdClass();
		if ( isset( $elementor_styling['html'] ) ) {
			$settings = clone $elementor_styling['html'];
		}
		$escaped_html = str_replace(
			array( '"', "'" ),
			array( '&quot;', '&#39;' ),
			'<table>' . $node_content . '</table>'
		);

		$settings->html    = $escaped_html;
		$element->settings = $settings;

		$element->elements   = array();
		$element->widgetType = 'html'; // @codingStandardsIgnoreLine

		return $element;
	}

	/**
	 * Functions prepare attributes for HTML and Gutendber tags.
	 *
	 * @param DOMElement $node - node to parse.
	 * @return array
	 */
	private function parse_node_attributes( $node ) {

		$attributes_array = array();

		if ( $node->hasAttributes() ) {

			// @codingStandardsIgnoreLine
			$node_name  = $node->nodeName;

			foreach ( $node->attributes as $attr ) {

				// @codingStandardsIgnoreLine
				$attr_name  = $attr->nodeName;
				// @codingStandardsIgnoreLine
				$attr_value = $attr->nodeValue;

				if ( 'contenteditable' === $attr_name ) {
					continue;
				}

				$attributes_array[ $attr_name ] = $attr_value;

				if ( in_array( $node_name, array( 'h2', 'h3', 'h4', 'h5', 'h6', 'h7' ), true ) && 'style' === $attr_name ) {
					$special_h        = $this->parse_styles_special_attributes( $attr_value );
					$attributes_array = array_merge( $attributes_array, $special_h );
				}

				if ( 'p' === $node_name && 'style' === $attr_name ) {
					$special_p        = $this->parse_styles_special_attributes( $attr_value );
					$attributes_array = array_merge( $attributes_array, $special_p );
				}
			}
		}

		return $attributes_array;
	}

	/**
	 * Parses special styles attributes for h and p tags.
	 *
	 * @param string $styles_string - string with styles.
	 * @return array
	 */
	private function parse_styles_special_attributes( $styles_string ) {
		$styles       = explode( ';', $styles_string );
		$styles_assoc = array();
		foreach ( $styles as $style ) {
			$s                     = explode( ':', $style );
			$styles_assoc[ $s[0] ] = trim( $s[1] );
		}

		$attributes = array();
		if ( key_exists( 'text-align', $styles_assoc ) ) {
			$styles_assoc['text-align'] = str_replace( 'start', 'left', $styles_assoc['text-align'] );
			$styles_assoc['text-align'] = str_replace( 'end', 'right', $styles_assoc['text-align'] );

			$attributes['align'] = $styles_assoc['text-align'];
		}

		return $attributes;
	}

		/**
		 * Remove <p> tags from lists.
		 *
		 * @param DOMDocument $doc - DOM document.
		 * @return DOMDocument
		 */
	private function remove_p_from_lists( $doc ) {
		$list_items = $doc->getElementsByTagName( 'li' );

		$li_array = array();
		foreach ( $list_items as $li ) {
			$li_array[] = $li;
		}

		foreach ( $li_array as $li ) {
			$paragraphs = $li->getElementsByTagName( 'p' );

			$p_array = array();
			foreach ( $paragraphs as $p ) {
				$p_array[] = $p;
			}

			foreach ( $p_array as $p ) {
				while ( $p->firstChild ) { // phpcs:ignore
					$li->insertBefore( $p->firstChild, $p ); // phpcs:ignore
				}

				$li->removeChild( $p );
			}
		}

		return $doc;
	}
}
