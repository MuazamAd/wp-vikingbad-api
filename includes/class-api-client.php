<?php

namespace Vikingbad;

defined( 'ABSPATH' ) || exit;

class API_Client {

	private $token;
	private $logger;
	private $last_request_time = 0.0;
	private $min_interval      = 1.1; // Minimum seconds between requests (60/min limit).
	private $max_retries       = 3;

	public function __construct( string $token, Logger $logger ) {
		$this->token  = $token;
		$this->logger = $logger;
	}

	/**
	 * Throttle requests to avoid hitting the API rate limit (429).
	 */
	private function throttle(): void {
		$elapsed = microtime( true ) - $this->last_request_time;
		if ( $elapsed < $this->min_interval ) {
			usleep( (int) ( ( $this->min_interval - $elapsed ) * 1_000_000 ) );
		}
		$this->last_request_time = microtime( true );
	}

	/**
	 * Fetch a page of products from the API.
	 *
	 * @return array{products: array, total_pages: int, current_page: int}|\WP_Error
	 */
	public function get_products( int $page = 1, int $per_page = 500 ) {
		$url = add_query_arg(
			[
				'page'     => $page,
				'per_page' => $per_page,
			],
			VIKINGBAD_API_BASE . '/products'
		);

		for ( $attempt = 1; $attempt <= $this->max_retries; $attempt++ ) {
			$this->throttle();

			$response = wp_remote_get( $url, [
				'headers' => [
					'Authorization' => 'Bearer ' . $this->token,
					'Accept'        => 'application/json',
				],
				'timeout' => 60,
			] );

			if ( is_wp_error( $response ) ) {
				$this->logger->error( 'API request failed: ' . $response->get_error_message() );
				return $response;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			if ( $code === 429 ) {
				$wait = $this->get_retry_after( $response, $attempt * 30 );
				$this->logger->warning( "Rate limited on product list page {$page}, waiting {$wait}s (attempt {$attempt}/{$this->max_retries})" );
				sleep( $wait );
				continue;
			}

			if ( $code !== 200 ) {
				$message = sprintf( 'API returned HTTP %d: %s', $code, wp_trim_words( $body, 20 ) );
				$this->logger->error( $message );
				return new \WP_Error( 'api_error', $message );
			}

			$data = json_decode( $body, true );

			if ( json_last_error() !== JSON_ERROR_NONE ) {
				$this->logger->error( 'Failed to parse API JSON response.' );
				return new \WP_Error( 'json_error', 'Invalid JSON response from API.' );
			}

			return [
				'products'     => $data['data'] ?? $data['products'] ?? $data,
				'total_pages'  => (int) ( $data['meta']['last_page'] ?? $data['total_pages'] ?? 1 ),
				'current_page' => (int) ( $data['meta']['current_page'] ?? $data['current_page'] ?? $page ),
			];
		}

		return new \WP_Error( 'rate_limited', "Rate limited after {$this->max_retries} retries on page {$page}" );
	}

	/**
	 * Fetch a single product by EAN.
	 *
	 * @return array|\WP_Error Product data array or error.
	 */
	public function get_product( string $ean ) {
		$url = VIKINGBAD_API_BASE . '/products/' . urlencode( $ean );

		for ( $attempt = 1; $attempt <= $this->max_retries; $attempt++ ) {
			$this->throttle();

			$response = wp_remote_get( $url, [
				'headers' => [
					'Authorization' => 'Bearer ' . $this->token,
					'Accept'        => 'application/json',
				],
				'timeout' => 30,
			] );

			if ( is_wp_error( $response ) ) {
				$this->logger->error( "Single product request failed for EAN {$ean}: " . $response->get_error_message() );
				return $response;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			// Rate limited — wait and retry.
			if ( $code === 429 ) {
				$wait = $this->get_retry_after( $response, $attempt * 30 );
				$this->logger->warning( "Rate limited on EAN {$ean}, waiting {$wait}s (attempt {$attempt}/{$this->max_retries})" );
				sleep( $wait );
				continue;
			}

			if ( $code !== 200 ) {
				$message = sprintf( 'API returned HTTP %d for EAN %s', $code, $ean );
				$this->logger->error( $message );
				return new \WP_Error( 'api_error', $message );
			}

			$data = json_decode( $body, true );

			if ( json_last_error() !== JSON_ERROR_NONE ) {
				return new \WP_Error( 'json_error', "Invalid JSON for EAN {$ean}" );
			}

			return $data['data'] ?? $data;
		}

		$this->logger->error( "Failed after {$this->max_retries} retries for EAN {$ean} (rate limited)" );
		return new \WP_Error( 'rate_limited', "Rate limited after {$this->max_retries} retries for EAN {$ean}" );
	}

	/**
	 * Parse the Retry-After header from a 429 response.
	 *
	 * @param array|WP_Error $response  The HTTP response.
	 * @param int            $fallback  Fallback seconds if header is missing.
	 * @return int Seconds to wait.
	 */
	private function get_retry_after( $response, int $fallback ): int {
		$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );

		if ( ! empty( $retry_after ) ) {
			// Retry-After can be seconds or an HTTP date.
			if ( is_numeric( $retry_after ) ) {
				return max( 1, (int) $retry_after );
			}

			$timestamp = strtotime( $retry_after );
			if ( $timestamp ) {
				return max( 1, $timestamp - time() );
			}
		}

		return $fallback;
	}

	/**
	 * Test the API connection.
	 *
	 * @return true|\WP_Error
	 */
	public function test_connection() {
		$result = $this->get_products( 1, 1 );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}
}
