<?php

namespace Vikingbad;

defined( 'ABSPATH' ) || exit;

class Settings {

	private $encryption;

	public function __construct() {
		$this->encryption = new Encryption();
	}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu_pages' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function add_menu_pages(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Vikingbad Innstillinger', 'vikingbad' ),
			__( 'Vikingbad Innstillinger', 'vikingbad' ),
			'manage_woocommerce',
			'vikingbad-settings',
			[ $this, 'render_settings_page' ]
		);

		add_submenu_page(
			'woocommerce',
			__( 'Vikingbad Import', 'vikingbad' ),
			__( 'Vikingbad Import', 'vikingbad' ),
			'manage_woocommerce',
			'vikingbad-import',
			[ $this, 'render_import_page' ]
		);
	}

	public function register_settings(): void {
		register_setting( 'vikingbad_settings', 'vikingbad_api_token', [
			'type'              => 'string',
			'sanitize_callback' => [ $this, 'sanitize_token' ],
		] );
	}

	public function sanitize_token( $value ) {
		if ( empty( $value ) ) {
			// Keep the existing token when the field is left blank.
			return get_option( 'vikingbad_api_token', '' );
		}
		return $this->encryption->encrypt( sanitize_text_field( $value ) );
	}

	public function get_token(): string {
		$encrypted = get_option( 'vikingbad_api_token', '' );
		if ( empty( $encrypted ) ) {
			return '';
		}
		return $this->encryption->decrypt( $encrypted );
	}

	public function enqueue_assets( string $hook ): void {
		// Fix narrow column headers on WC products list (e.g. "Fortjeneste").
		if ( 'edit.php' === $hook && ( $_GET['post_type'] ?? '' ) === 'product' ) {
			wp_add_inline_style( 'woocommerce_admin_styles', '.wp-list-table.posts th { white-space: nowrap; }' );
			return;
		}

		if ( ! in_array( $hook, [ 'woocommerce_page_vikingbad-settings', 'woocommerce_page_vikingbad-import' ], true ) ) {
			return;
		}

		wp_enqueue_style(
			'vikingbad-admin',
			VIKINGBAD_PLUGIN_URL . 'admin/css/admin.css',
			[],
			VIKINGBAD_VERSION
		);

		if ( 'woocommerce_page_vikingbad-import' === $hook ) {
			wp_enqueue_script(
				'vikingbad-import',
				VIKINGBAD_PLUGIN_URL . 'admin/js/import.js',
				[ 'jquery' ],
				VIKINGBAD_VERSION,
				true
			);

			wp_localize_script( 'vikingbad-import', 'vikingbadImport', [
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'vikingbad_import' ),
				'i18n'    => [
					'importing'  => __( 'Importerer side %1$d av %2$d...', 'vikingbad' ),
					'complete'   => __( 'Import fullfort!', 'vikingbad' ),
					'error'      => __( 'Import feilet: %s', 'vikingbad' ),
					'created'    => __( 'Opprettet', 'vikingbad' ),
					'updated'    => __( 'Oppdatert', 'vikingbad' ),
					'failed'     => __( 'Feilet', 'vikingbad' ),
				],
			] );
		}

		if ( 'woocommerce_page_vikingbad-settings' === $hook ) {
			wp_enqueue_script(
				'vikingbad-settings',
				VIKINGBAD_PLUGIN_URL . 'admin/js/import.js',
				[ 'jquery' ],
				VIKINGBAD_VERSION,
				true
			);

			wp_localize_script( 'vikingbad-settings', 'vikingbadImport', [
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'vikingbad_import' ),
			] );
		}
	}

	public function render_settings_page(): void {
		include VIKINGBAD_PLUGIN_DIR . 'admin/views/settings-page.php';
	}

	public function render_import_page(): void {
		include VIKINGBAD_PLUGIN_DIR . 'admin/views/import-page.php';
	}
}
