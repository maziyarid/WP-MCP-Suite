<?php
/**
 * Central feature-flag registry.
 *
 * Every module in the suite must be individually enable-able so the plugin
 * can be rolled out gradually (spec: "Each layer should be independently
 * testable and feature-flagged"). Flags are stored as a single serialized
 * option to avoid an autoloaded-options table full of one-off rows.
 *
 * @package MCPSuite\Core
 */

declare( strict_types = 1 );

namespace MCPSuite\Core;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class FeatureFlags {

	public const OPTION_KEY = 'mcp_suite_feature_flags';

	public const MODULE_CONTENT_SYNC = 'content_sync';
	public const MODULE_SEO          = 'seo';
	public const MODULE_SEARCH_CONSOLE = 'search_console';
	public const MODULE_ANALYTICS    = 'analytics';
	public const MODULE_CLARITY      = 'clarity';
	public const MODULE_LINK_INTEL   = 'link_intelligence';
	public const MODULE_AI_WRITING   = 'ai_writing';
	public const MODULE_BACKUP       = 'backup';
	public const MODULE_REPORTING    = 'reporting';

	/**
	 * All known modules and their default enabled state.
	 * Phase 1 ships with every module disabled by default: an administrator
	 * must consciously turn each one on after configuring its credentials.
	 * This is a deliberate "secure by default" / fail-closed choice.
	 *
	 * @var array<string,bool>
	 */
	private const DEFAULTS = array(
		self::MODULE_CONTENT_SYNC   => false,
		self::MODULE_SEO            => false,
		self::MODULE_SEARCH_CONSOLE => false,
		self::MODULE_ANALYTICS      => false,
		self::MODULE_CLARITY        => false,
		self::MODULE_LINK_INTEL     => false,
		self::MODULE_AI_WRITING     => false,
		self::MODULE_BACKUP         => false,
		self::MODULE_REPORTING      => false,
	);

	/**
	 * @return array<string,bool>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		// Merge over defaults so newly-added modules always appear, and
		// unknown/stale keys from an old version never leak through.
		$result = self::DEFAULTS;
		foreach ( $result as $module => $default ) {
			if ( array_key_exists( $module, $stored ) ) {
				$result[ $module ] = (bool) $stored[ $module ];
			}
		}

		return $result;
	}

	public static function is_enabled( string $module ): bool {
		$all = self::all();
		return $all[ $module ] ?? false;
	}

	public static function set( string $module, bool $enabled ): bool {
		if ( ! array_key_exists( $module, self::DEFAULTS ) ) {
			return false;
		}

		$all             = self::all();
		$all[ $module ]  = $enabled;

		return update_option( self::OPTION_KEY, $all );
	}

	/**
	 * @return string[]
	 */
	public static function module_keys(): array {
		return array_keys( self::DEFAULTS );
	}

	public static function seed_defaults(): void {
		if ( false === get_option( self::OPTION_KEY, false ) ) {
			add_option( self::OPTION_KEY, self::DEFAULTS );
		}
	}
}
