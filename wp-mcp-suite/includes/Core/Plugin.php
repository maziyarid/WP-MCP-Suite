<?php
/**
 * Central orchestrator. Boots the database migrator, the admin menu, and
 * every module whose feature flag is enabled. This is the one place that
 * knows about every module — individual modules never reference each other
 * directly, only through the shared data model (custom tables) and shared
 * services in MCPSuite\Core. That decoupling is what keeps this "a set of
 * internal modules with shared data models," per spec Scope, rather than a
 * pile of interdependent features.
 *
 * @package MCPSuite\Core
 */

declare( strict_types = 1 );

namespace MCPSuite\Core;

use MCPSuite\Admin\AdminMenu;
use MCPSuite\Admin\AiProviderSettingsController;
use MCPSuite\Admin\BackupSettingsController;
use MCPSuite\Admin\ClaritySettingsController;
use MCPSuite\Admin\FeatureFlagsController;
use MCPSuite\Admin\GoogleSettingsController;
use MCPSuite\Admin\GraphSettingsController;
use MCPSuite\Database\Migrator;
use MCPSuite\Modules\AiWritingModule;
use MCPSuite\Modules\AnalyticsModule;
use MCPSuite\Modules\BackupModule;
use MCPSuite\Modules\ClarityModule;
use MCPSuite\Modules\ContentSyncModule;
use MCPSuite\Modules\LinkIntelligenceModule;
use MCPSuite\Modules\ModuleInterface;
use MCPSuite\Modules\ReportingModule;
use MCPSuite\Modules\SearchConsoleModule;
use MCPSuite\Modules\SeoModule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	private static ?Plugin $instance = null;

	/** @var ModuleInterface[] */
	private array $modules = array();

	private bool $booted = false;

	private function __construct() {}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function boot(): void {
		if ( $this->booted ) {
			return; // boot() must be idempotent — plugins_loaded can theoretically fire once per request only, but guard anyway.
		}
		$this->booted = true;

		// Keep schema current on every load. dbDelta is a no-op when the
		// installed schema already matches, so this is cheap.
		if ( version_compare( Migrator::current_version(), Migrator::latest_available_version(), '<' ) ) {
			Migrator::migrate_to_latest();
		}

		load_plugin_textdomain( 'wp-mcp-suite', false, dirname( plugin_basename( MCP_SUITE_FILE ) ) . '/languages' );

		$this->modules = array(
			new ContentSyncModule(),
			new SeoModule(),
			new SearchConsoleModule(),
			new AnalyticsModule(),
			new ClarityModule(),
			new LinkIntelligenceModule(),
			new AiWritingModule(),
			new BackupModule(),
			new ReportingModule(),
		);

		foreach ( $this->modules as $module ) {
			if ( FeatureFlags::is_enabled( $module->key() ) ) {
				$module->register();
			}
		}

		if ( is_admin() ) {
			$admin_menu = new AdminMenu( $this->modules );
			add_action( 'admin_menu', array( $admin_menu, 'register' ) );

			( new GraphSettingsController() )->register();
			( new GoogleSettingsController() )->register();
			( new ClaritySettingsController() )->register();
			( new AiProviderSettingsController() )->register();
			( new BackupSettingsController() )->register();
			( new FeatureFlagsController( $this->modules ) )->register();
		}
	}

	/**
	 * @return ModuleInterface[]
	 */
	public function modules(): array {
		return $this->modules;
	}

	// Cloning and unserialising a singleton would create a second instance
	// with hooks registered twice — both are explicitly forbidden.
	public function __clone() {
		throw new \LogicException( 'Plugin is a singleton and must not be cloned.' );
	}

	public function __wakeup() {
		throw new \LogicException( 'Plugin is a singleton and must not be unserialized.' );
	}
}
