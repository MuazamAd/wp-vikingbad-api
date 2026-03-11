<?php

namespace Vikingbad;

defined( 'ABSPATH' ) || exit;

class Logger {

	private $logger;
	private $context;

	public function __construct() {
		$this->logger  = wc_get_logger();
		$this->context = [ 'source' => 'vikingbad-import' ];
	}

	public function info( string $message ): void {
		$this->logger->info( $message, $this->context );
	}

	public function error( string $message ): void {
		$this->logger->error( $message, $this->context );
	}

	public function warning( string $message ): void {
		$this->logger->warning( $message, $this->context );
	}
}
