<?php
/**
 *  Form object to easily manage forms.
 *
 * @package SurferSEO
 * @link https://surferseo.com
 */

namespace SurferSEO\Forms;

use SurferSEO\Forms\Fields\Surfer_Form_Element_Checkbox;
use SurferSEO\Surferseo;
use SurferSEO\Forms\Fields\Surfer_Form_Element_Header;
use SurferSEO\Forms\Fields\Surfer_Form_Element_Text;
use SurferSEO\Forms\Fields\Surfer_Form_Element_Select;
use SurferSEO\Forms\Fields\Surfer_Form_Element_Hidden;
use SurferSEO\Forms\Validators\Validator_Is_Required;
use SurferSEO\Surfer\GSC\Surfer_GSC_Common;
use SurferSEO\Surfer\Content_Parsers\Parsers_Controller;

/**
 * Object to store form data to easily manage forms.
 */
class Surfer_Form_Config_Ci extends Surfer_Form {

	use Surfer_GSC_Common;

	/**
	 * Construct to initialize form structure.
	 *
	 * @return void
	 */
	public function __construct() {
		$connected = Surfer()->get_surfer()->is_surfer_connected();

		$this->repo = parent::REPO_OPTIONS;

		$field = new Surfer_Form_Element_Header( 'header_core' );
		$field->set_label( __( 'Optimize with Surfer', 'surferseo' ) );
		if ( $connected ) {
			$field->set_hint( __( 'Boost your SEO game with our seamless content transfer between Surfer Content Editor and WordPress ', 'surferseo' ) );
		}
		$field->set_row_classes( 'surfer-admin-config-form__single-header-row' );
		$this->add_field( $field );

		$field = new Surfer_Form_Element_Text( 'surfer_api_public_key' );
		$field->set_label( __( 'Surfer account', 'surferseo' ) );
		$field->set_renderer( array( $this, 'render_connection_button' ) );
		$this->add_field( $field );

		$field = new Surfer_Form_Element_Text( 'surfer_gsc_connection' );
		$field->set_label( __( 'Google Search Console integration', 'surferseo' ) );
		$field->set_renderer( array( $this, 'render_gsc_connection' ) );
		$this->add_field( $field );

		$field = new Surfer_Form_Element_Header( 'header_settings_section' );
		$field->set_label( __( 'Settings', 'surferseo' ) );
		$field->set_row_classes( 'surfer-connected surfer-admin-config-form__section-header' );
		$this->add_field( $field );

		$field = new Surfer_Form_Element_Header( 'header_content_importer' );
		$field->set_label( __( 'Export settings from Surfer\'s Content Editor', 'surferseo' ) );
		$field->set_hint( '' );
		$field->set_row_classes( 'surfer-connected' );
		$this->add_field( $field );

		$this->render_content_import_defaults_section();

		$this->render_tracking_section();

		$this->render_position_monitor_config_section();

		$this->render_developer_mode_section();

		$this->display_submit = $connected;
	}

	/**
	 * Render connection button.
	 *
	 * @return void
	 */
	private function render_content_import_defaults_section() {

		$field = new Surfer_Form_Element_Select( 'default_content_editor' );
		$field->set_label( __( 'Default Content Parser', 'surferseo' ) );
		$field->set_hint( __( 'Choose which editor should be used by default when importing content from Surfer.', 'surferseo' ) );
		$field->add_option( Parsers_Controller::AUTOMATIC, __( 'Auto Detection (Default)', 'surferseo' ) );
		$field->add_option( Parsers_Controller::CLASSIC_EDITOR, __( 'Classic Editor', 'surferseo' ) );
		$field->add_option( Parsers_Controller::GUTENBERG, __( 'Gutenberg', 'surferseo' ) );
		$field->add_option( Parsers_Controller::ELEMENTOR, __( 'Elementor', 'surferseo' ) );
		$field->set_row_classes( 'surfer-connected' );
		$this->add_field( $field );

		$custom_options = apply_filters( 'surfer_page_templates', array() );

		$field = new Surfer_Form_Element_Select( 'default_page_template' );
		$field->set_label( __( 'Default Post Template', 'surferseo' ) );
		$field->set_hint( __( 'Sets default post template for Elementor Parser', 'surferseo' ) );
		$field->add_option( 'default', __( 'Default', 'surferseo' ) );
		$field->add_option( 'blank', __( 'Blank', 'surferseo' ) );
		$field->add_option( 'single-no-separators', __( 'Single Post (No Separators)', 'surferseo' ) );
		foreach ( get_page_templates() as $label => $value ) {
			$field->add_option( $value, $label );
		}
		foreach ( $custom_options as $label => $value ) {
			$field->add_option( $value, $label );
		}
		$field->set_row_classes( 'surfer-connected' );
		$this->add_field( $field );

		$elementor_templates = get_posts( array( 'post_type' => 'elementor_library' ) );

		$field = new Surfer_Form_Element_Select( 'default_elementor_template' );
		$field->set_label( __( 'Elementor Template', 'surferseo' ) );
		$field->set_hint( __( 'Styles from this template will be used for post during import when Elementor parser is chosen.', 'surferseo' ) );
		$field->add_option( '', __( 'None', 'surferseo' ) );
		foreach ( $elementor_templates as $template ) {
			$field->add_option( $template->ID, $template->post_title );
		}
		$field->set_row_classes( 'surfer-connected' );
		$this->add_field( $field );

		$field = new Surfer_Form_Element_Select( 'default_seo_plugin' );
		$field->set_label( __( 'Default SEO Plugin', 'surferseo' ) );
		$field->set_hint( __( 'SEO plugin that you are using for meta tags.', 'surferseo' ) );
		$field->add_option( '', __( 'Auto Detection (Default)', 'surferseo' ) );
		$field->add_option( 'aioseo', __( 'All in One SEO', 'surferseo' ) );
		$field->add_option( 'rank_math', __( 'Rank Math', 'surferseo' ) );
		// $field->add_option( 'surfer', __( 'Surfer Plugin build-in', 'surferseo' ) );
		$field->add_option( 'yoast', __( 'Yoast SEO', 'surferseo' ) );
		$field->set_row_classes( 'surfer-connected' );
		$this->add_field( $field );

		$all_users = get_users(
			array(
				'number'   => 100,
				'role__in' => array( 'administrator', 'editor', 'author' ),
			)
		);

		$field = new Surfer_Form_Element_Select( 'default_post_author' );
		$field->set_label( __( 'Author', 'surferseo' ) );
		$field->add_option( '', __( '- Select an option -', 'surferseo' ) );
		$field->set_row_classes( 'surfer-connected' );
		foreach ( $all_users as $user ) {
			$field->add_option( $user->ID, $user->display_name );
		}
		$this->add_field( $field );

		$args = array(
			'hide_empty' => false,
		);

		$categories = get_categories( $args );

		$field = new Surfer_Form_Element_Select( 'default_category' );
		$field->set_label( __( 'Category', 'surferseo' ) );
		$field->add_option( '', __( '- Select an option -', 'surferseo' ) );
		$field->set_row_classes( 'surfer-connected' );
		foreach ( $categories as $category ) {
			$field->add_option( $category->term_id, $category->name );
		}
		$this->add_field( $field );

		$args = array(
			'hide_empty' => false,
		);

		$tags = get_tags( $args );

		$field = new Surfer_Form_Element_Select( 'default_tags' );
		$field->set_label( __( 'Tag', 'surferseo' ) );
		$field->add_option( '', __( '- Select an option -', 'surferseo' ) );
		$field->set_row_classes( 'surfer-connected' );
		foreach ( $tags as $tag ) {
			$field->add_option( $tag->term_id, $tag->name );
		}
		$this->add_field( $field );

		$field = new Surfer_Form_Element_Checkbox( 'disable_elementor' );
		$field->set_label( '' );
		$field->add_option( 1, __( 'Disable Surfer writing guidelines in Elementor editor', 'surferseo' ) );
		$field->set_renderer( array( $this, 'render_switch' ) );
		$field->set_row_classes( 'surfer-connected' );
		$this->add_field( $field );

		$field = new Surfer_Form_Element_Select( 'image_processing_mode' );
		$field->set_label( __( 'Image Processing Mode', 'surferseo' ) );
		$field->set_hint( __( 'Choose how to handle images during import. Async mode prevents timeouts but images are processed in background.', 'surferseo' ) );
		$field->add_option( 'sync', __( 'Synchronous (immediate)', 'surferseo' ) );
		$field->add_option( 'async', __( 'Asynchronous (background)', 'surferseo' ) );
		$field->add_option( 'auto', __( 'Auto (async for 8+ images)', 'surferseo' ) );
		$field->set_row_classes( 'surfer-connected' );
		$this->add_field( $field );

		$field = new Surfer_Form_Element_Checkbox( 'internal_links_rel' );
		$field->set_label( 'Internal Links Rel' );
		$field->set_hint( 'Rel arg for internal links after import from Surfer' );
		$field->add_option( 'noopener', __( 'noopener', 'surferseo' ) );
		$field->add_option( 'noreferrer', __( 'noreferrer', 'surferseo' ) );
		$field->add_option( 'nofollow', __( 'nofollow', 'surferseo' ) );
		$field->add_option( 'dofollow', __( 'dofollow', 'surferseo' ) );
		$field->set_row_classes( 'surfer-connected' );
		$this->add_field( $field );

		$field = new Surfer_Form_Element_Select( 'internal_links_target' );
		$field->set_label( 'Internal Links Target' );
		$field->set_hint( 'Target arg for internal links after import from Surfer' );
		$field->add_option( '_self', __( '_self', 'surferseo' ) );
		$field->add_option( '_blank', __( '_blank', 'surferseo' ) );
		$field->add_option( '_parent', __( '_parent', 'surferseo' ) );
		$field->add_option( '_top', __( '_top', 'surferseo' ) );
		$field->set_row_classes( 'surfer-connected' );
		$this->add_field( $field );

		$field = new Surfer_Form_Element_Checkbox( 'external_links_rel' );
		$field->set_label( 'External Links Rel' );
		$field->set_hint( 'Rel arg for external links after import from Surfer' );
		$field->add_option( 'noopener', __( 'noopener', 'surferseo' ) );
		$field->add_option( 'noreferrer', __( 'noreferrer', 'surferseo' ) );
		$field->add_option( 'nofollow', __( 'nofollow', 'surferseo' ) );
		$field->add_option( 'dofollow', __( 'dofollow', 'surferseo' ) );
		$field->set_row_classes( 'surfer-connected' );
		$this->add_field( $field );

		$field = new Surfer_Form_Element_Select( 'external_links_target' );
		$field->set_label( 'External Link Target' );
		$field->set_hint( 'Target arg for external links after import from Surfer' );
		$field->add_option( '_self', __( '_self', 'surferseo' ) );
		$field->add_option( '_blank', __( '_blank', 'surferseo' ) );
		$field->add_option( '_parent', __( '_parent', 'surferseo' ) );
		$field->add_option( '_top', __( '_top', 'surferseo' ) );
		$field->set_row_classes( 'surfer-connected' );
		$this->add_field( $field );

		$field->set_row_classes( 'surfer-connected' );
		$this->add_field( $field );
	}

	/**
	 * Render GSC section.
	 *
	 * @return void
	 */
	private function render_gsc_section() {
		$field = new Surfer_Form_Element_Header( 'header_gsc' );
		$field->set_label( __( 'Google Search Console', 'surferseo' ) );
		$this->add_field( $field );

		$field = new Surfer_Form_Element_Text( 'surfer_gsc_meta_script' );
		$field->set_label( __( 'GSC HTML Tag', 'surferseo' ) );
		$field->set_classes( 'large-text code' );
		$field->set_hint( __( 'Paste here script generated by Google Search Console. Example: <meta name="google-site-verification" content="abc#123" />', 'surferseo' ) );
		$this->add_field( $field );
	}

	/**
	 * Return section for tracking
	 *
	 * @return void
	 */
	private function render_tracking_section() {
		$field = new Surfer_Form_Element_Header( 'header_tracking' );
		$field->set_label( __( 'Improve the plugin', 'surferseo' ) );
		$field->set_row_classes( 'surfer-connected' );
		$this->add_field( $field );

		$field = new Surfer_Form_Element_Checkbox( 'surfer_tracking_enabled' );
		$field->set_label( '' );
		$field->add_option( 1, __( 'Help us improve and let us analyze usage data.', 'surferseo' ) );
		/* translators: %s - Privacy Policy URL */
		$field->set_hint( sprintf( __( 'Help us improve!<br/><br/>We\'d like to analyze how you use the tool to see which features are most helpful. Don\'t worry, it\'s completely anonymous (and no, we can\'t see your Amazon wishlist ;)). We\'re mostly interested in things like what version of PHP or WordPress you\'re using. This helps us make decisions for future plugin updates. <br/><br/>What do you say? <br/><br/>Don’t worry! You can turn off this feature at any time in Surfer’s WordPress plugin settings. If you want to learn more, check our <a href="%s" target="_blank">Privacy Policy</a>', 'surferseo' ), Surferseo::get_instance()->get_surfer()->get_privacy_policy_url() ) );
		$field->set_renderer( array( $this, 'render_switch' ) );
		$field->set_classes( 'surfer-tracking-switch' );
		$field->set_row_classes( 'surfer-connected' );
		$this->add_field( $field );
	}

	/**
	 * Displays config for the position monitor.
	 *
	 * @return void
	 */
	private function render_position_monitor_config_section() {

		$gsc_enabled = Surfer()->get_surfer()->get_gsc()->check_if_gsc_connected();

		$hint = __( 'Enable this option to get a weekly report with data from your Google Search Console that will tell you how well your posts performed.', 'surferseo' );
		if ( ! $gsc_enabled ) {
			$hint = __( 'You need to make GSC connection to be able to activate email notifications.', 'surferseo' );
		}

		$field = new Surfer_Form_Element_Header( 'header_position_monitor' );
		$field->set_label( __( 'E-mail notifications', 'surferseo' ) );
		$field->set_row_classes( 'surfer-connected' );
		$this->add_field( $field );

		$field = new Surfer_Form_Element_Checkbox( 'surfer_position_monitor_summary' );
		$field->set_label( '' );
		if ( $gsc_enabled ) {
			$field->add_option( 1, __( 'Send me a weekly report on my site’s performance.', 'surferseo' ) );
		}
		$field->set_hint( $hint );
		$field->set_renderer( array( $this, 'render_switch' ) );
		$field->set_classes( 'surfer-position-monitor-notification-switch' );
		$field->set_row_classes( 'surfer-connected' );
		$this->add_field( $field );
	}

	/**
	 * Render developer mode section.
	 *
	 * @return void
	 */
	private function render_developer_mode_section() {
		// phpcs:ignore
		if ( isset( $_GET['developer_mode'] ) && 1 === intval( $_GET['developer_mode'] ) ) {
			$field = new Surfer_Form_Element_Header( 'header_dev' );
			$field->set_label( __( 'Developer options', 'surferseo' ) );
			$this->add_field( $field );
		}

		// phpcs:ignore
		if ( isset( $_GET['developer_mode'] ) && 1 === intval( $_GET['developer_mode'] ) ) {
			$field = new Surfer_Form_Element_Text( 'surfer_url' );
		} else {
			$field = new Surfer_Form_Element_Hidden( 'surfer_url' );
		}
		$field->set_label( __( 'Surfer URL', 'surferseo' ) );
		$field->set_hint( __( '[DEVELOPER FIELD] URL to Surfer. Change only if you are sure what you are doing.', 'surferseo' ) );
		$field->set_classes( 'regular-text' );
		$field->add_validator( new Validator_Is_Required() );
		$this->add_field( $field );

		// phpcs:ignore
		if ( isset( $_GET['developer_mode'] ) && 1 === intval( $_GET['developer_mode'] ) ) {
			$field = new Surfer_Form_Element_Text( 'surfer_api_url' );
		} else {
			$field = new Surfer_Form_Element_Hidden( 'surfer_api_url' );
		}
		$field->set_label( __( 'Surfer API URL', 'surferseo' ) );
		$field->set_hint( __( '[DEVELOPER FIELD] URL to Surfer API. Change only if you are sure what you are doing.', 'surferseo' ) );
		$field->set_classes( 'regular-text' );
		$field->add_validator( new Validator_Is_Required() );
		$this->add_field( $field );

		// phpcs:ignore
		if ( isset( $_GET['developer_mode'] ) && 1 === intval( $_GET['developer_mode'] ) ) {
			$field = new Surfer_Form_Element_Text( 'wpsurfer_api_access_key' );
		} else {
			$field = new Surfer_Form_Element_Hidden( 'wpsurfer_api_access_key' );
		}
		$field->set_label( __( 'Surfer API Key', 'surferseo' ) );
		$field->set_hint( __( '[DEVELOPER FIELD] API key used to make requests to Surfer API.', 'surferseo' ) );
		$field->set_classes( 'regular-text' );
		$field->set_value( get_option( 'wpsurfer_api_access_key', false ) );
		$field->add_validator( new Validator_Is_Required() );
		$this->add_field( $field );

		// phpcs:ignore
		if ( isset( $_GET['developer_mode'] ) && 1 === intval( $_GET['developer_mode'] ) ) {
			$field = new Surfer_Form_Element_Text( 'surfer_test_gsc_export' );
			$field->set_label( __( 'Force GSC Data', 'surferseo' ) );
			$field->set_hint( __( 'On click data from GSC will be gathered.', 'surferseo' ) );
			$field->set_renderer( array( $this, 'render_test_gsc_export' ) );
			$this->add_field( $field );

			$field = new Surfer_Form_Element_Text( 'surfer_reconnect_posts_with_drafts' );
			$field->set_label( __( 'Reconnect all posts with Drafts in Surfer', 'surferseo' ) );
			$field->set_hint( __( 'On click all Surfer posts connected to any draft, will be reconnected (content from WordPress will be exported to Surfer).', 'surferseo' ) );
			$field->set_renderer( array( $this, 'render_reconnect_posts_with_drafts' ) );
			$this->add_field( $field );

			$field = new Surfer_Form_Element_Text( 'surfer_remove_old_surfer_backups' );
			$field->set_label( __( 'Remove old Surfer backups', 'surferseo' ) );
			$field->set_hint( __( 'On click old Surfer backups (created on post import) will be removed.', 'surferseo' ) );
			$field->set_renderer( array( $this, 'render_clear_backups' ) );
			$this->add_field( $field );
		}
	}

	/**
	 * Renders Surfer connection button.
	 *
	 * @param Surfer_Form_Element $field - field object.
	 * @return void
	 */
	public function render_connection_button( $field ) {
		$connection_details = Surferseo::get_instance()->get_surfer()->wp_connection_details();

		ob_start();
		?>
			<div class="surfer-connection-box">
				<div class="surfer-connected">
					<h3><?php echo esc_html( $field->get_label() ); ?></h3>
					<p>
					<?php
						esc_html_e(
							'Connect your Surfer account to easily optimize your posts with Content Editor',
							'surferseo'
						);
					?>
					</p>

					<div class="surfer-connection-box--connected">
						<p class="surfer-connection-box__connection-info">
							<svg xmlns="http://www.w3.org/2000/svg" width="16" height="17" viewBox="0 0 16 17" fill="currentColor">
								<path fill-rule="evenodd" clip-rule="evenodd" d="M9.74348 1.6319C9.94431 1.74387 10.0429 1.97835 9.98239 2.20018L8.65464 7.06862H13.5C13.6991 7.06862 13.8792 7.18674 13.9586 7.36935C14.0379 7.55195 14.0014 7.76423 13.8655 7.90978L6.86554 15.4098C6.70866 15.5779 6.45736 15.6173 6.25654 15.5053C6.05571 15.3934 5.95713 15.1589 6.01763 14.9371L7.34539 10.0686H2.50001C2.30091 10.0686 2.12079 9.9505 2.04144 9.76789C1.96209 9.58529 1.99863 9.37301 2.13448 9.22746L9.13448 1.72746C9.29137 1.55937 9.54266 1.51994 9.74348 1.6319Z" fill="#338F61"/>
							</svg>

							<?php esc_html_e( 'Connected', 'surferseo' ); ?>
						</p>

						<p class="surfer-connection-box__connection-details">
							<span id="surfer-organization-name">
								<?php if ( isset( $connection_details['organization_name'] ) ) : ?>
									<?php echo esc_html( $connection_details['organization_name'] ); ?>
								<?php endif; ?>
							</span>
							<?php esc_html_e( 'via', 'surferseo' ); ?>
							<span id="surfer-via-email">
								<?php if ( isset( $connection_details['via_email'] ) ) : ?>
									<?php echo esc_html( $connection_details['via_email'] ); ?>
								<?php endif; ?>
							</span>
						</p>

						<p class="surfer-connection-box__actions">
							<button class="surfer-button surfer-button--secondary surfer-button--xsmall" id="surfer_disconnect"><?php esc_html_e( 'Disconnect', 'surferseo' ); ?></button> 
							<button id="surfer_reconnect" class="surfer-button surfer-button--secondary surfer-button--xsmall"><?php esc_html_e( 'Replace with another Surfer account', 'surferseo' ); ?></button>
							<?php surfer_image_printer( esc_url( includes_url() ) . 'images/spinner.gif', 'spinner', 'display: none', 'surfer-reconnection-spinner' ); ?>
						</p>
					</div>
				</div>
				
				<div class="surfer-not-connected">
					<p class="surfer-text--secondary">
						<?php esc_html_e( 'Boost your SEO game with our seamless content transfer between Surfer’s Content Editor and WordPress. Refine and perfect your articles effortlessly, ensuring your SEO strategy is never left to luck. Create content that ranks with Surfer in WordPress today!', 'surferseo' ); ?>
					</p>

					<div class="surfer-connection-box--not-connected">
						<p class="surfer-connection-box__actions" style="margin-left: 0px;">
							<button class="surfer-button surfer-button--small surfer-button--primary surfer-button--icon-left surfer_make_connection">
								<svg width="20" height="20" viewBox="0 0 20 20" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
									<path fill-rule="evenodd" clip-rule="evenodd" d="M5.81787 2.47021C3.6605 2.47021 1.91161 4.2191 1.91161 6.37647V11.114H1.08594L2.6885 13.4377L4.29107 11.114H3.47411V6.37647C3.47411 5.08205 4.52345 4.03271 5.81787 4.03271H13.9141C15.0837 4.03271 16.0319 4.98089 16.0319 6.15053H17.5944C17.5944 4.11795 15.9467 2.47021 13.9141 2.47021H5.81787ZM11.102 13.9185H12.5042C12.7045 13.9185 12.8247 13.7582 12.8247 13.598V7.06752C12.8247 6.8672 12.6645 6.74701 12.5042 6.74701H11.102C10.9016 6.74701 10.7815 6.90727 10.7815 7.06752V13.598C10.7815 13.7582 10.9417 13.9185 11.102 13.9185ZM6.93533 13.9185H8.33757C8.5379 13.9185 8.65809 13.7582 8.65809 13.598V9.4313C8.65809 9.23098 8.49783 9.11079 8.33757 9.11079H6.93533C6.73501 9.11079 6.61482 9.27104 6.61482 9.4313V13.5579C6.61482 13.7582 6.77507 13.9185 6.93533 13.9185ZM15.2149 9.50644H16.0319V14.244C16.0319 15.5384 14.9826 16.5877 13.6881 16.5877H5.59192C4.42228 16.5877 3.4741 15.6395 3.4741 14.4699H1.9116C1.9116 16.5025 3.55934 18.1502 5.59192 18.1502H13.6881C15.8455 18.1502 17.5944 16.4013 17.5944 14.244V9.50644H18.42L16.8175 7.18272L15.2149 9.50644Z" fill="white"/>
								</svg>
								<?php esc_html_e( 'Log in and integrate with Surfer', 'surferseo' ); ?>
							</button>
							<?php surfer_image_printer( esc_url( includes_url() ) . 'images/spinner.gif', 'spinner', 'display: none', 'surfer-connection-spinner' ); ?>
						</p>
					</div>
				</div>
			</div>
		<?php
		$html = ob_get_clean();

		echo $html; // @codingStandardsIgnoreLine
	}

	/**
	 * Renders button to connect with GSC.
	 *
	 * @param Surfer_Form_Element $field - field object.
	 */
	public function render_gsc_connection( $field ) {
		$connected = Surfer()->get_surfer()->get_gsc()->check_if_gsc_connected( true );

		ob_start();
		?>

			<div class="surfer-connected">
				<div class="surfer-connection-box">
					<h3 id="surfer_gsc_connection"><?php echo esc_html( $field->get_label() ); ?></h3>
					<p><?php esc_html_e( 'Connect Google Search Console to track clicks and impressions on your posts', 'surferseo' ); ?></p>

					<?php if ( $connected ) : ?>
						<div class="surfer-connection-box--connected">
							<p class="surfer-connection-box__connection-info">
								<svg xmlns="http://www.w3.org/2000/svg" width="16" height="17" viewBox="0 0 16 17" fill="currentColor">
									<path fill-rule="evenodd" clip-rule="evenodd" d="M9.74348 1.6319C9.94431 1.74387 10.0429 1.97835 9.98239 2.20018L8.65464 7.06862H13.5C13.6991 7.06862 13.8792 7.18674 13.9586 7.36935C14.0379 7.55195 14.0014 7.76423 13.8655 7.90978L6.86554 15.4098C6.70866 15.5779 6.45736 15.6173 6.25654 15.5053C6.05571 15.3934 5.95713 15.1589 6.01763 14.9371L7.34539 10.0686H2.50001C2.30091 10.0686 2.12079 9.9505 2.04144 9.76789C1.96209 9.58529 1.99863 9.37301 2.13448 9.22746L9.13448 1.72746C9.29137 1.55937 9.54266 1.51994 9.74348 1.6319Z" fill="#338F61"/>
								</svg>

								<?php esc_html_e( 'Connected', 'surferseo' ); ?>
							</p>

							<p class="surfer-connection-box__actions">
								<a href="<?php echo esc_attr( Surfer()->get_surfer()->get_surfer_url() ); ?>/search_consoles/list" target="_blank" class="surfer-button surfer-button--secondary surfer-button--xsmall">
									<?php esc_html_e( 'Edit GSC integration inside Surfer', 'surferseo' ); ?>
								</a>
							</p>
						</div>
					<?php else : ?>
						<div class="surfer-connection-box--not-connected">
							<p class="surfer-connection-box__connection-info">
								<svg xmlns="http://www.w3.org/2000/svg" width="16" height="17" viewBox="0 0 16 17" fill="currentColor">
									<path d="M13.8655 7.90978L11.7399 10.1872L6.30827 4.75555L9.13448 1.72746C9.29137 1.55937 9.54266 1.51994 9.74348 1.6319C9.94431 1.74387 10.0429 1.97835 9.98239 2.20018L8.65464 7.06862H13.5C13.6991 7.06862 13.8792 7.18674 13.9586 7.36935C14.0379 7.55195 14.0014 7.76423 13.8655 7.90978Z" fill="#E53E3E"/>
									<path d="M2.13448 9.22746L4.2601 6.95002L9.69176 12.3817L6.86554 15.4098C6.70866 15.5779 6.45736 15.6173 6.25654 15.5053C6.05571 15.3934 5.95713 15.1589 6.01763 14.9371L7.34539 10.0686H2.50001C2.30091 10.0686 2.12079 9.9505 2.04144 9.76789C1.96209 9.58529 1.99863 9.37301 2.13448 9.22746Z" fill="#E53E3E"/>
									<path d="M2.35355 2.21505C2.15829 2.01979 1.84171 2.01979 1.64645 2.21505C1.45118 2.41031 1.45118 2.72689 1.64645 2.92216L13.6464 14.9222C13.8417 15.1174 14.1583 15.1174 14.3536 14.9222C14.5488 14.7269 14.5488 14.4103 14.3536 14.215L2.35355 2.21505Z" fill="#E53E3E"/>
								</svg>

								<?php esc_html_e( 'Not connected with Surfer', 'surferseo' ); ?>
							</p>

							<p class="surfer-connection-box__actions">
								<a href="<?php echo esc_attr( Surfer()->get_surfer()->get_surfer_url() ); ?>/search_consoles/list" class="surfer-button surfer-button--primary surfer-button--small" target="_blank">
									<?php esc_html_e( 'Add GSC account to Surfer', 'surferseo' ); ?>
								</a>
							</p>
						</div>
					<?php endif; ?>
				</div>
			</div>
		<?php
		$html = ob_get_clean();

		echo $html; // @codingStandardsIgnoreLine
	}

	/**
	 * Renders switch
	 *
	 * @param Surfer_Form_Element $field - field object.
	 */
	public function render_switch( $field ) {

		$hint = $field->get_hint();

		ob_start();
		?>

			<div class="surfer-switch-box <?php echo esc_html( $field->get_classes() ); ?>">
				<p>
				<?php
				if ( $hint ) {
					echo wp_kses_post( $hint );
				}
				?>
				</p>

				<?php foreach ( $field->get_options() as $option ) : ?>
					<?php echo esc_html( $field->get_label() ); ?>
					<label class="switch">
						<input type="checkbox" name="<?php echo esc_html( $field->get_name() ); ?>[]" value="<?php echo esc_html( $option['value'] ); ?>" <?php echo ( in_array( $option['value'], (array) $field->get_value() ) ) ? 'checked="checked"' : ''; ?>>
						<span class="slider round"></span>
					</label>
					<?php echo esc_html( $option['label'] ); ?>
				<?php endforeach; ?>
			</div>
		<?php
		$html = ob_get_clean();

		echo $html; // @codingStandardsIgnoreLine
	}

	/**
	 * Renders button to test GSC connection.
	 *
	 * @param Surfer_Form_Element $field - field object.
	 */
	public function render_test_gsc_export( $field ) {

		ob_start();
		?>

			<div class="surfer-test-gsc-connection-box <?php echo esc_html( $field->get_classes() ); ?>">
				<p><?php echo wp_kses_post( $field->get_hint() ); ?></p>

				<button class="surfer-perform-gsc-connection-test surfer-button surfer-button--secondary surfer-button--small">
					<?php esc_html_e( 'Test GSC connection', 'surferseo' ); ?>
				</button>

				<div class="surfer-test-gsc-connection-box__result"></div>
			</div>
		<?php
		$html = ob_get_clean();

		echo wp_kses_post( $html );
	}


	/**
	 * Renders button to reconnect posts with Drafts (make export again).
	 *
	 * @param Surfer_Form_Element $field - field object.
	 * @return void
	 */
	public function render_reconnect_posts_with_drafts( $field ) {

		ob_start();
		?>

			<div class="surfer-reconnect-posts-with-drafts-box <?php echo esc_html( $field->get_classes() ); ?>">
				<p><?php echo wp_kses_post( $field->get_hint() ); ?></p>

				<button class="surfer-reconnect-posts-with-drafts-box__button surfer-button surfer-button--secondary surfer-button--small">
					<?php esc_html_e( 'Reconnect Posts with Drafts', 'surferseo' ); ?>
				</button>

				<div class="surfer-reconnect-posts-with-drafts-box__result"></div>
			</div>
		<?php
		$html = ob_get_clean();

		echo wp_kses_post( $html );
	}

	/**
	 * Renders button to clear backups.
	 *
	 * @param Surfer_Form_Element $field - field object.
	 */
	public function render_clear_backups( $field ) {

		ob_start();
		?>

			<div class="surfer-remove-surfer-backups-box <?php echo esc_html( $field->get_classes() ); ?>">
				<p><?php echo wp_kses_post( $field->get_hint() ); ?></p>

				<button class="surfer-button surfer-button--secondary surfer-button--small surfer-remove-surfer-backups-box__button">
					<?php esc_html_e( 'Remove Surfer Backups', 'surferseo' ); ?>
				</button>

				<div class="surfer-remove-surfer-backups-box__result"></div>
			</div>
		<?php
		$html = ob_get_clean();

		echo wp_kses_post( $html );
	}

	/**
	 * Overrides parent save method to add tracking.
	 *
	 * @param bool | string $tab - tab name.
	 * @return bool
	 */
	public function save( $tab = false ) {

		$tracking_enabled = Surfer()->get_surfer_tracking()->is_tracking_allowed();

		if ( $tracking_enabled || ( isset( $_POST['surfer_tracking_enabled'] ) && 1 === intval( $_POST['surfer_tracking_enabled'] ) ) ) {
			$data = $this->check_if_tracking_or_emails_was_changed();
			Surfer()->get_surfer_tracking()->track_wp_event( 'config_saved', wp_json_encode( $data ) );
		}

		$saved = parent::save( $tab );

		$first_enabled = get_transient( 'surfer_tracking_first_enabled' );

		if ( false === $first_enabled && $tracking_enabled ) {
			Surfer()->get_surfer_tracking()->track_wp_environment();
			set_transient( 'surfer_tracking_first_enabled', true, 60 * 60 * 24 * 30 );
		}

		if ( isset( $_POST['wpsurfer_api_access_key'] ) ) {
			update_option( 'wpsurfer_api_access_key', sanitize_text_field( wp_unslash( $_POST['wpsurfer_api_access_key'] ) ) );
		}

		return $saved;
	}

	/**
	 * Stores data about tracking and emails changes.
	 *
	 * @return array
	 */
	private function check_if_tracking_or_emails_was_changed() {

		$tracking = isset( $_POST['surfer_tracking_enabled'] ) ? wp_unslash( $_POST['surfer_tracking_enabled'] ) : false;
		$emails   = isset( $_POST['surfer_position_monitor_summary'] ) ? wp_unslash( $_POST['surfer_position_monitor_summary'] ) : false;

		$tracking_current_state = Surfer()->get_surfer_tracking()->is_tracking_allowed();
		$emails_current_state   = $this->performance_report_email_notification_endabled();

		return array(
			'tracking' => array(
				'current_state' => $tracking_current_state,
				'new_state'     => $tracking,
			),
			'emails'   => array(
				'current_state' => $emails_current_state,
				'new_state'     => $emails,
			),
		);
	}
}
