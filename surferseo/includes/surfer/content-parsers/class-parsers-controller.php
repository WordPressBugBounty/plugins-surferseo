<?php
/**
 *  Parser that prepare data for Elementor
 *
 * @package SurferSEO
 * @link https://surferseo.com
 */

namespace SurferSEO\Surfer\Content_Parsers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SURFER_ASYNC_THRESHOLD' ) ) {
	define( 'SURFER_ASYNC_THRESHOLD', 8 );
}

/**
 * Object that imports data from different sources into WordPress.
 */
class Parsers_Controller {

	/**
	 * Parser that prepare data for Classic Editor.
	 *
	 * @var Content_Parser
	 */
	protected $chosen_parser = null;

	/**
	 * Image processing mode.
	 *
	 * @var string
	 */
	protected $image_processing_mode = 'sync';

	/**
	 * Images of the current import left for the background download, URL => alt text.
	 *
	 * Kept here and not in the parser - a new parser is created for every parsed content,
	 * and Elementor finds its images only after the post is saved.
	 *
	 * @var array<string, string>
	 */
	protected $pending_images = array();

	/**
	 * Images of the current import that were downloaded or queued, URL => true.
	 *
	 * Counted here for the same reason as pending images - one import can use more than
	 * one parser instance (Elementor parses its images after the post is saved).
	 *
	 * @var array<string, bool>
	 */
	protected $limited_images = array();

	/**
	 * Whether the image limit of the current import was already logged.
	 *
	 * @var bool
	 */
	protected $image_limit_logged = false;

	/**
	 * Available parsers.
	 *
	 * @var string
	 */
	public const AUTOMATIC      = '';
	public const CLASSIC_EDITOR = 'classic';
	public const GUTENBERG      = 'gutenberg';
	public const ELEMENTOR      = 'elementor';


	/**
	 * Parse content from Surfer into one of used editors.
	 *
	 * @param string $content - Content from Surfer.
	 * @return string
	 */
	public function parse_content( $content ) {
		$content        = apply_filters( 'surfer_pre_import_content_parsing', $content );
		$parsed_content = '';

		if ( apply_filters( 'surfer_skip_default_content_parsing', false ) ) {
			$parsed_content = $content;
		} else {
			$this->choose_parser();
			$parsed_content = $this->chosen_parser->parse_content( $content );
		}

		return apply_filters( 'surfer_import_content_parsing', $parsed_content, $content );
	}

	/**
	 * Choose which parser we should use.
	 *
	 * @return void
	 */
	private function choose_parser() {

		$config_parser = Surfer()->get_surfer_settings()->get_option( 'content-importer', 'default_content_editor', self::GUTENBERG );

		if ( false === $config_parser || ! in_array( $config_parser, $this->available_parsers(), true ) ) {
			$config_parser = $this->resolve_automatic_parser_selection();
		}

		$this->load_parser( $config_parser );
		$this->chosen_parser->set_image_processing_mode( $this->image_processing_mode );
		$this->chosen_parser->set_pending_image_handler( array( $this, 'add_pending_image' ) );
		$this->chosen_parser->set_image_limit_handler( array( $this, 'reserve_image' ) );
	}

	/**
	 * Returns available parsers.
	 *
	 * @return array
	 */
	private function available_parsers() {
		return array(
			self::CLASSIC_EDITOR,
			self::GUTENBERG,
			self::ELEMENTOR,
		);
	}

	/**
	 * Loads chosen parser.
	 *
	 * @param string $parser - key for parser to load.
	 * @return void
	 */
	private function load_parser( $parser ) {

		if ( self::CLASSIC_EDITOR === $parser ) {
			$this->chosen_parser = new Classic_Editor_Parser();
		}

		if ( self::GUTENBERG === $parser ) {
			$this->chosen_parser = new Gutenberg_Parser();
		}

		if ( self::ELEMENTOR === $parser ) {
			$this->chosen_parser = new Elementor_Parser();
		}
	}

	/**
	 * Automatically select parser based on WP version and active plugins.
	 *
	 * @return string
	 */
	private function resolve_automatic_parser_selection() {

		if ( $this->if_user_is_using_classic_editor() ) {
			return self::CLASSIC_EDITOR;
		}

		if ( $this->if_user_is_using_elementor() ) {
			return self::ELEMENTOR;
		}

		// To Gutenberg (default).
		return self::GUTENBERG;
	}

	/**
	 * Checks if user is using Classic editor:
	 *
	 * There are two cases:
	 * - WordPress version < 5.0 (before Gutenberg), without Gutenberg plugin.
	 * - WordPress version >= 5.0 (builtin Gutenberg), with Disable Gutenberg or Classing Editor plugin.
	 *
	 * @return bool
	 */
	private function if_user_is_using_classic_editor() {

		// ( WP < 5.0 && ! Gutenberg Plugin ) || ( WP > 5.0 && Gutenberg Plugin )
		$wp_version                     = get_bloginfo( 'version' );
		$if_gutenberg_is_active         = surfer_check_if_plugins_is_active( 'gutenberg/gutenberg.php' );
		$if_disable_gutenberg_is_active = surfer_check_if_plugins_is_active( 'disable-gutenberg/disable-gutenberg.php' );
		$if_classic_editor_is_active    = surfer_check_if_plugins_is_active( 'classic-editor/classic-editor.php' );

		if ( version_compare( $wp_version, '5.0', '>=' ) && ( $if_disable_gutenberg_is_active || $if_classic_editor_is_active ) ) {
			return true;
		}

		if ( version_compare( $wp_version, '5.0', '<' ) && ! $if_gutenberg_is_active ) {
			return true;
		}

		return false;
	}

	/**
	 * Checks if user is using Elementor.
	 *
	 * @return bool
	 */
	private function if_user_is_using_elementor() {

		$if_elementor_is_active = surfer_check_if_plugins_is_active( 'elementor/elementor.php' );

		if ( $if_elementor_is_active ) {
			return true;
		}

		return false;
	}

	/**
	 * Returns title after parsing.
	 *
	 * @return string
	 */
	public function return_title() {
		return $this->chosen_parser->return_title();
	}

	/**
	 * Gets title from content without parsing.
	 *
	 * @param string $content - Content from Surfer.
	 * @return string
	 */
	public function parse_only_title( $content ) {
		$this->choose_parser();
		return $this->chosen_parser->parse_title( $content );
	}

	/**
	 * Runs additional actions that parser need to do, when post is already in database.
	 *
	 * @param int $post_id - ID of the post.
	 * @return void
	 */
	public function run_after_post_insert_actions( $post_id ) {

		// No parser was used yet (`surfer_skip_default_content_parsing`).
		if ( null === $this->chosen_parser ) {
			return;
		}

		$this->chosen_parser->run_after_post_insert_actions( $post_id );
	}

	/**
	 * Remembers an image left for the background download.
	 *
	 * The first alt text of the image is kept, the same as in the queue.
	 *
	 * @param string $image_url - URL of the image.
	 * @param string $image_alt - alt text of the image.
	 * @return void
	 */
	public function add_pending_image( $image_url, $image_alt ) {

		$image_url = (string) $image_url;

		if ( '' === $image_url || array_key_exists( $image_url, $this->pending_images ) ) {
			return;
		}

		$this->pending_images[ $image_url ] = (string) $image_alt;
	}

	/**
	 * Returns images of the current import left for the background download.
	 *
	 * @return array<int, array{url: string, alt: string}>
	 */
	public function get_pending_images() {

		$images = array();

		foreach ( $this->pending_images as $image_url => $image_alt ) {
			$images[] = array(
				'url' => (string) $image_url,
				'alt' => $image_alt,
			);
		}

		return $images;
	}

	/**
	 * Forgets images of the previous import.
	 *
	 * @return void
	 */
	public function clear_pending_images() {
		$this->pending_images = array();
	}

	/**
	 * Checks if one more image of the current import may be downloaded or queued.
	 *
	 * Only images that need a download are counted (images already in the media library
	 * never reach this check), and every URL is counted once. Images over the limit keep
	 * their Surfer URLs - the limit is logged once per import.
	 *
	 * @param string $image_url - URL of the image.
	 * @return bool
	 */
	public function reserve_image( $image_url ) {

		$image_url = (string) $image_url;

		// Invalid images are not downloaded at all, there is nothing to count.
		if ( '' === $image_url || isset( $this->limited_images[ $image_url ] ) ) {
			return true;
		}

		$limits = surfer_get_import_limits();

		if ( count( $this->limited_images ) >= $limits['images_per_import'] ) {
			if ( ! $this->image_limit_logged ) {
				$this->image_limit_logged = true;
				surfer_log_image_import_error(
					$image_url,
					'limit of ' . $limits['images_per_import'] . ' images per import reached, this and further images keep their remote URLs'
				);
			}

			return false;
		}

		$this->limited_images[ $image_url ] = true;

		return true;
	}

	/**
	 * Starts counting images of a new import.
	 *
	 * @return void
	 */
	public function reset_image_limit() {
		$this->limited_images     = array();
		$this->image_limit_logged = false;
	}


	/**
	 * Get image processing mode.
	 *
	 * In both modes images end up in the media library. `sync` downloads them during the
	 * import request, `async` queues them and downloads in batches in the background, so
	 * an image heavy post does not time out. `auto` picks background processing above
	 * SURFER_ASYNC_THRESHOLD images.
	 *
	 * @param int $image_count - number of images in content.
	 * @return void
	 */
	public function set_image_processing_mode( $image_count ) {
		$image_processing_mode = Surfer()->get_surfer_settings()->get_option( 'content-importer', 'image_processing_mode', 'auto' );

		/**
		 * Filters image processing mode for the import.
		 *
		 * @param string $image_processing_mode - `sync`, `async` or `auto`.
		 * @param int    $image_count - number of images in imported content.
		 */
		$image_processing_mode = apply_filters( 'surfer_image_processing_mode', $image_processing_mode, $image_count );

		if ( 'async' === $image_processing_mode || ( 'auto' === $image_processing_mode && $image_count > SURFER_ASYNC_THRESHOLD ) ) {
			$this->image_processing_mode = 'async';
			return;
		}

		$this->image_processing_mode = 'sync';
	}
}
