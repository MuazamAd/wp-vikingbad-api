<?php

namespace Vikingbad;

defined( 'ABSPATH' ) || exit;

class Ajax_Handler {

	public function register(): void {
		add_action( 'wp_ajax_vikingbad_start_import', [ $this, 'start_import' ] );
		add_action( 'wp_ajax_vikingbad_import_page', [ $this, 'import_page' ] );
		add_action( 'wp_ajax_vikingbad_test_connection', [ $this, 'test_connection' ] );
		add_action( 'wp_ajax_vikingbad_start_scan', [ $this, 'start_scan' ] );
		add_action( 'wp_ajax_vikingbad_scan_page', [ $this, 'scan_page' ] );
		add_action( 'wp_ajax_vikingbad_save_category_map', [ $this, 'save_category_map' ] );
	}

	public function test_connection(): void {
		check_ajax_referer( 'vikingbad_import', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Ingen tilgang.' ] );
		}

		$settings = new Settings();
		$token    = $settings->get_token();

		if ( empty( $token ) ) {
			wp_send_json_error( [ 'message' => 'Ingen API-nokkel konfigurert. Lagre nokkelen din forst.' ] );
		}

		$logger = new Logger();
		$client = new API_Client( $token, $logger );
		$result = $client->test_connection();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success( [ 'message' => 'Tilkobling vellykket!' ] );
	}

	public function start_import(): void {
		check_ajax_referer( 'vikingbad_import', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Ingen tilgang.' ] );
		}

		@set_time_limit( 300 );

		$settings = new Settings();
		$token    = $settings->get_token();

		if ( empty( $token ) ) {
			wp_send_json_error( [ 'message' => 'Ingen API-nokkel konfigurert.' ] );
		}

		$logger = new Logger();
		$client = new API_Client( $token, $logger );
		$result = $client->get_products( 1, 10 );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		$logger->info( sprintf( 'Import started. Total pages: %d', $result['total_pages'] ) );

		// Import the first page — fetches full details per product by EAN.
		$importer = new Product_Importer( $logger, $client );
		$stats    = $importer->import( $result['products'] );

		wp_send_json_success( [
			'total_pages'  => $result['total_pages'],
			'current_page' => 1,
			'stats'        => $stats,
		] );
	}

	public function import_page(): void {
		check_ajax_referer( 'vikingbad_import', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Ingen tilgang.' ] );
		}

		@set_time_limit( 300 );

		$page = (int) ( $_POST['page'] ?? 0 );

		if ( $page < 1 ) {
			wp_send_json_error( [ 'message' => 'Ugyldig sidenummer.' ] );
		}

		$settings = new Settings();
		$token    = $settings->get_token();

		if ( empty( $token ) ) {
			wp_send_json_error( [ 'message' => 'Ingen API-nokkel konfigurert.' ] );
		}

		$logger = new Logger();
		$client = new API_Client( $token, $logger );
		$result = $client->get_products( $page, 10 );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		$importer = new Product_Importer( $logger, $client );
		$stats    = $importer->import( $result['products'] );

		wp_send_json_success( [
			'current_page' => $page,
			'stats'        => $stats,
		] );
	}

	/**
	 * Start category scan — reset progress or check for resumable scan.
	 */
	public function start_scan(): void {
		check_ajax_referer( 'vikingbad_import', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Ingen tilgang.' ] );
		}

		$fresh = (bool) ( $_POST['fresh'] ?? false );

		// Check for existing progress.
		$progress = get_option( 'vikingbad_scan_progress', [] );

		if ( ! $fresh && ! empty( $progress['last_page'] ) && ! empty( $progress['total_pages'] )
			&& $progress['last_page'] < $progress['total_pages'] ) {
			// Resumable scan exists — return progress so JS can continue.
			wp_send_json_success( [
				'total_pages'   => $progress['total_pages'],
				'resume_page'   => $progress['last_page'] + 1,
				'scanned'       => $progress['scanned'] ?? 0,
				'found'         => count( get_option( 'vikingbad_api_categories', [] ) ),
				'resuming'      => true,
			] );
			return; // wp_send_json_success calls die(), but be explicit.
		}

		// Fresh scan — reset categories and progress.
		update_option( 'vikingbad_api_categories', [] );
		delete_option( 'vikingbad_scan_progress' );

		@set_time_limit( 600 );

		$settings = new Settings();
		$token    = $settings->get_token();

		if ( empty( $token ) ) {
			wp_send_json_error( [ 'message' => 'Ingen API-nokkel konfigurert.' ] );
		}

		$logger = new Logger();
		$client = new API_Client( $token, $logger );
		$result = $client->get_products( 1, 10 );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		$stats = $this->scan_products_for_categories( $client, $result['products'] );

		// Save progress.
		update_option( 'vikingbad_scan_progress', [
			'last_page'   => 1,
			'total_pages' => $result['total_pages'],
			'scanned'     => $stats['scanned'],
		] );

		wp_send_json_success( [
			'total_pages'  => $result['total_pages'],
			'current_page' => 1,
			'scanned'      => $stats['scanned'],
			'found'        => $stats['found'],
			'resuming'     => false,
		] );
	}

	/**
	 * Scan a single page of products for categories.
	 */
	public function scan_page(): void {
		check_ajax_referer( 'vikingbad_import', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Ingen tilgang.' ] );
		}

		@set_time_limit( 600 );

		$page = (int) ( $_POST['page'] ?? 0 );
		if ( $page < 1 ) {
			wp_send_json_error( [ 'message' => 'Ugyldig sidenummer.' ] );
		}

		$settings = new Settings();
		$token    = $settings->get_token();

		if ( empty( $token ) ) {
			wp_send_json_error( [ 'message' => 'Ingen API-nokkel konfigurert.' ] );
		}

		$logger = new Logger();
		$client = new API_Client( $token, $logger );
		$result = $client->get_products( $page, 10 );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		$stats = $this->scan_products_for_categories( $client, $result['products'] );

		// Update progress.
		$progress = get_option( 'vikingbad_scan_progress', [] );
		$total_scanned = ( $progress['scanned'] ?? 0 ) + $stats['scanned'];

		if ( $page >= $result['total_pages'] ) {
			// Scan complete — clean up progress.
			delete_option( 'vikingbad_scan_progress' );
		} else {
			update_option( 'vikingbad_scan_progress', [
				'last_page'   => $page,
				'total_pages' => $result['total_pages'],
				'scanned'     => $total_scanned,
			] );
		}

		wp_send_json_success( [
			'current_page' => $page,
			'scanned'      => $stats['scanned'],
			'found'        => $stats['found'],
		] );
	}

	/**
	 * Fetch full details for every product and collect their categories.
	 * Categories are accumulated in the vikingbad_api_categories option.
	 */
	private function scan_products_for_categories( API_Client $client, array $products ): array {
		$existing = get_option( 'vikingbad_api_categories', [] );
		$by_name  = [];
		$scanned  = 0;

		foreach ( $existing as $cat ) {
			$by_name[ $cat['name'] ] = $cat;
		}

		foreach ( $products as $product_summary ) {
			$ean = $product_summary['ean'] ?? '';
			if ( empty( $ean ) ) {
				continue;
			}

			$full = $client->get_product( $ean );
			if ( is_wp_error( $full ) ) {
				continue;
			}

			$scanned++;

			foreach ( $full['categories'] ?? [] as $cat ) {
				$name = $cat['name'] ?? '';
				if ( ! empty( $name ) && ! isset( $by_name[ $name ] ) ) {
					$by_name[ $name ] = [
						'name'  => $name,
						'slug'  => $cat['slug'] ?? '',
						'level' => $cat['level'] ?? 1,
					];
				}
			}
		}

		// Sort and save.
		$all = array_values( $by_name );
		usort( $all, function ( $a, $b ) {
			if ( $a['level'] !== $b['level'] ) {
				return $a['level'] - $b['level'];
			}
			return strcmp( $a['name'], $b['name'] );
		} );

		update_option( 'vikingbad_api_categories', $all );

		return [ 'scanned' => $scanned, 'found' => count( $all ) ];
	}

	/**
	 * Save the category mapping (API category name → WC term ID).
	 */
	public function save_category_map(): void {
		check_ajax_referer( 'vikingbad_import', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Ingen tilgang.' ] );
		}

		$map = $_POST['category_map'] ?? [];

		if ( ! is_array( $map ) ) {
			wp_send_json_error( [ 'message' => 'Ugyldig data.' ] );
		}

		// Sanitize: API category name (string) → WC term ID (int).
		$clean = [];
		foreach ( $map as $api_name => $term_id ) {
			$api_name = sanitize_text_field( $api_name );
			$term_id  = (int) $term_id;
			if ( ! empty( $api_name ) && $term_id > 0 ) {
				$clean[ $api_name ] = $term_id;
			}
		}

		update_option( 'vikingbad_category_map', $clean );

		wp_send_json_success( [ 'message' => 'Kategorikartlegging lagret.' ] );
	}
}
