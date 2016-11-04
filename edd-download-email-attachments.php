<?php
/*
Plugin Name: Easy Digital Downloads - Download Email Attachments
Plugin URI: http://easydigitaldownloads.com/extensions/download-email-attachments
Description: Send download files as email attachments when purchased.
Version: 1.1
Author: Andrew Munro, Sumobi
Author URI: http://sumobi.com/
*/

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'EDD_Download_Email_Attachments' ) ) {

	class EDD_Download_Email_Attachments {

		private static $instance;

		/**
		 * Main Instance
		 *
		 * Ensures that only one instance exists in memory at any one
		 * time. Also prevents needing to define globals all over the place.
		 *
		 * @since 1.0
		 *
		 */
		public static function instance() {
			if ( ! isset ( self::$instance ) ) {
				self::$instance = new self;
			}

			return self::$instance;
		}

		/**
		 * Start your engines
		 *
		 * @since 1.0
		 *
		 * @return void
		 */
		public function __construct() {
			$this->setup_globals();
			$this->setup_actions();
		}

		/**
		 * Globals
		 *
		 * @since 1.0
		 *
		 * @return void
		 */
		private function setup_globals() {

			$this->version = '1.1';
			$this->title   = __( 'Download Email Attachments', 'edd-dea' );

			// paths
			$this->file         = __FILE__;
			$this->basename     = apply_filters( 'edd_dea_plugin_basenname', plugin_basename( $this->file ) );
			$this->plugin_dir   = apply_filters( 'edd_dea_plugin_dir_path',  plugin_dir_path( $this->file ) );
			$this->plugin_url   = apply_filters( 'edd_dea_plugin_dir_url',   plugin_dir_url ( $this->file ) );

			if( ! class_exists( 'EDD_License' ) )
				include( dirname( __FILE__ ) . '/includes/EDD_License_Handler.php' );

			$license = new EDD_License( __FILE__, 'Download Email Attachments', $this->version, 'Andrew Munro' );

		}

		/**
		 * Setup the default hooks and actions
		 *
		 * @since 1.0
		 *
		 * @return void
		 */
		private function setup_actions() {
			global $edd_options;

			// text domain
			add_action( 'after_setup_theme', array( $this, 'load_textdomain' ) );

			// metabox
			if ( isset( $edd_options['edd_dea_per_download_attachments'] ) ) {
				add_action( 'edd_meta_box_fields', array( $this, 'add_metabox' ), 18 );
				add_action( 'edd_metabox_fields_save', array( $this, 'save_metabox' ) );
			}

			// settings
			add_filter( 'edd_settings_extensions', array( $this, 'settings' ) );

			// filter attachments
			add_filter( 'edd_receipt_attachments', array( $this, 'attachments' ), 10, 3 );

			// action links
			add_filter( 'plugin_action_links', array( $this, 'action_links'), 10, 2 );

			do_action( 'edd_dea_setup_actions' );
		}

		/**
		 * Loads the plugin language files
		 *
		 * @access public
		 * @since 1.0
		 * @return void
		 */
		public function load_textdomain() {
			// Set filter for plugin's languages directory
			$lang_dir = dirname( plugin_basename( $this->file ) ) . '/languages/';
			$lang_dir = apply_filters( 'edd_dea_languages_directory', $lang_dir );

			// Traditional WordPress plugin locale filter
			$locale        = apply_filters( 'plugin_locale',  get_locale(), 'edd-dea' );
			$mofile        = sprintf( '%1$s-%2$s.mo', 'edd-dea', $locale );

			// Setup paths to current locale file
			$mofile_local  = $lang_dir . $mofile;
			$mofile_global = WP_LANG_DIR . '/edd-dea/' . $mofile;

			if ( file_exists( $mofile_global ) ) {
				// Look in global /wp-content/languages/edd-download-attachments folder
				load_textdomain( 'edd-dea', $mofile_global );
			} elseif ( file_exists( $mofile_local ) ) {
				// Look in local /wp-content/plugins/edd-download-attachments/languages/ folder
				load_textdomain( 'edd-dea', $mofile_local );
			} else {
				// Load the default language files
				load_plugin_textdomain( 'edd-dea', false, $lang_dir );
			}
		}

		/**
		 * Retrieves the attachment ID from the file URL
		 * Credit: Pippin Williamson
		 * @link http://pippinsplugins.com/retrieve-attachment-id-from-image-url/
		 * @since 1.0
		*/
		private function get_file_id( $file_url ) {
			global $wpdb;

			$prefix = $wpdb->prefix;
			$attachment = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM " . $prefix . "posts" . " WHERE guid='%s';", $file_url ) );

			return $attachment[0];
		}

		/**
		 * Filter attachments
		 *
		 * @since 1.0
		*/
		public function attachments( $attachments, $payment_id, $payment_data ) {
			global $edd_options;

			// get array of download IDs
			$downloads = edd_get_payment_meta_downloads( $payment_id );

			if ( $downloads ) {

				$files = array();
				$attachments = array();

				foreach ( $downloads as $download ) {

					// if per download email attachments is enabled, only get downloads with checkbox enabled
					if ( isset( $edd_options['edd_dea_per_download_attachments'] ) )
						if ( ! get_post_meta( $download['id'], '_edd_dea_enabled', true ) )
							continue;

					// is bundled product
					if ( edd_is_bundled_product( $download['id'] ) ) {
						$bundled_ids = get_post_meta( $download['id'], '_edd_bundled_products', true );

						if ( $bundled_ids ) {
							foreach ( $bundled_ids as $id ) {
								$files[] = get_post_meta( $id, 'edd_download_files', true );
							}
						}
					}
					// normal download
					else {
						$price_id = isset( $download['options']['price_id'] ) && is_numeric( $download['options']['price_id'] ) ? absint( $download['options']['price_id'] ) : null; 
						$files[] = edd_get_download_files( $download['id'], $price_id );
					}

				}

				if ( $files ) {
					$file_urls = array();

					foreach ( $files as $key => $file ) {
						// get the file URLs
						foreach ( $file as $value ) {
							$file_urls[] = $value['file'];
						}
					}

					if ( $file_urls ) {
						foreach ( $file_urls as $file_url ) {
							$attachments[] = get_attached_file( $this->get_file_id( $file_url ) );
						}
					}
				}

			}

			return $attachments;
		}

		/**
		 * Add Metabox if per download email attachments are enabled
		 *
		 * @since 1.0
		*/
		public function add_metabox( $post_id ) {
			$checked = (boolean) get_post_meta( $post_id, '_edd_dea_enabled', true );
		?>
			<p><strong><?php apply_filters( 'edd_dea_header', printf( __( '%s Attachments', 'edd-dea' ), edd_get_label_singular() ) ); ?></strong></p>
			<p>
				<label for="edd_download_attachments">
					<input type="checkbox" name="_edd_dea_enabled" id="edd_download_attachments" value="1" <?php checked( true, $checked ); ?> />
					<?php apply_filters( 'edd_dea_header_label', _e( 'Send file downloads as email attachments', 'edd-dea' ) ); ?>
				</label>
			</p>
		<?php
		}

		/**
		 * Add to save function
		 *
		 * @since 1.0
		*/
		public function save_metabox( $fields ) {
			$fields[] = '_edd_dea_enabled';

			return $fields;
		}

		/**
		 * Settings
		 *
		 * @since 1.0
		*/
		public function settings( $settings ) {

		  $new_settings = array(
				array(
					'id'   => 'edd_dea_header',
					'name' => '<strong>' . $this->title . '</strong>',
					'type' => 'header',
				),
				array(
					'id'   => 'edd_dea_per_download_attachments',
					'name' => __( 'Enable per download file attachments', 'edd-dea' ),
					'desc' => __( 'Enable this option to only specify the downloads which files will be sent as attachments', 'edd-dea' ),
					'type' => 'checkbox',
					'std'  => '',
				),
			);

			return array_merge( $settings, $new_settings );
		}

		/**
		 * Plugin action links
		 *
		 * @since 1.0
		*/
		public function action_links( $links, $pluginLink ) {
			if( $pluginLink != 'edd-download-email-attachments/edd-download-email-attachments.php' )
				return $links;

			$plugin_links = array(
				'<a href="' . admin_url( 'edit.php?post_type=download&page=edd-settings&tab=extensions' ) . '">' . __( 'Settings', 'edd-dea' ) . '</a>',
			);

			return array_merge( $plugin_links, $links );
		}
	}
}

/**
 * Get everything running
 *
 * @since 1.0
 *
 * @access private
 * @return void
 */
function edd_download_email_attachments() {
	$edd_download_email_attachments = new EDD_Download_Email_Attachments();
}
add_action( 'plugins_loaded', 'edd_download_email_attachments' );
