<?php
/**
 * Contract every functional module (Content Sync, SEO, Search Console,
 * Analytics, Clarity, Link Intelligence, AI Writing, Backup, Reporting)
 * must implement.
 *
 * This is what makes the system "a set of internal modules with shared data
 * models... not a loose collection of features" (spec, Scope). Plugin::boot()
 * iterates a fixed registry of these and calls register() only for modules
 * whose feature flag is enabled — nothing a module does can run unless
 * FeatureFlags::is_enabled() is true for it.
 *
 * @package MCPSuite\Modules
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface ModuleInterface {

	/**
	 * One of the FeatureFlags::MODULE_* constants.
	 */
	public function key(): string;

	/**
	 * Human-readable name shown in Settings and the admin menu.
	 */
	public function label(): string;

	/**
	 * The Capabilities::* constant required to view this module's admin page.
	 */
	public function required_capability(): string;

	/**
	 * Registers hooks, cron schedules and REST routes. Called once, on
	 * plugins_loaded, ONLY if the module's feature flag is enabled. Must be
	 * idempotent and must not perform any I/O itself (no HTTP calls, no DB
	 * writes) — it registers callbacks, it does not execute business logic.
	 */
	public function register(): void;

	/**
	 * Renders the module's admin page. Implementations must escape all
	 * output and must not assume the current user's capability has already
	 * been checked by the caller — AdminMenu checks it, but defence in
	 * depth costs nothing here.
	 */
	public function render_admin_page(): void;
}
