<?php

namespace Vikingbad;

defined( 'ABSPATH' ) || exit;

class Encryption {

	private $key;
	private $cipher = 'aes-256-cbc';

	public function __construct() {
		$this->key = substr( hash( 'sha256', wp_salt( 'auth' ) ), 0, 32 );
	}

	public function encrypt( string $value ): string {
		$iv         = openssl_random_pseudo_bytes( openssl_cipher_iv_length( $this->cipher ) );
		$encrypted  = openssl_encrypt( $value, $this->cipher, $this->key, 0, $iv );

		return base64_encode( $iv . '::' . $encrypted );
	}

	public function decrypt( string $value ): string {
		$data = base64_decode( $value, true );
		if ( false === $data ) {
			return '';
		}

		$parts = explode( '::', $data, 2 );
		if ( count( $parts ) !== 2 ) {
			return '';
		}

		$decrypted = openssl_decrypt( $parts[1], $this->cipher, $this->key, 0, $parts[0] );

		return false === $decrypted ? '' : $decrypted;
	}
}
