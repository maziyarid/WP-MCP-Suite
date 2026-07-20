<?php
/**
 * Shared boilerplate for concrete modules. Concrete modules extend this and
 * implement key(), label(), required_capability() and register(); this
 * class supplies a consistent, escaped placeholder admin page renderer so
 * every module looks and behaves the same until its real UI lands.
 *
 * @package MCPSuite\Modules
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules;

use MCPSuite\Core\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class AbstractModule implements ModuleInterface {

	/**
	 * Short, plain-language description of what this module will do once
	 * its phase is implemented. Shown on the placeholder admin page so a
	 * non-technical site owner always understands current system state —
	 * this is part of the "good, user-friendly UI" requirement: a module
	 * that quietly does nothing is worse than one that says so clearly.
	 */
	abstract protected function roadmap_description(): string;

	public function required_capability(): string {
		return Capabilities::VIEW_DASHBOARD;
	}

	public function render_admin_page(): void {
		if ( ! current_user_can( $this->required_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'wp-mcp-suite' ) );
		}

		echo '<div class="wrap mcp-suite-module-page">';
		echo '<h1>' . esc_html( $this->label() ) . '</h1>';
		echo '<div class="notice notice-info"><p>' .
			esc_html__( 'This module is not enabled yet.', 'wp-mcp-suite' ) .
			' ' . esc_html( $this->roadmap_description() ) .
			'</p></div>';
		echo '</div>';
	}
}
