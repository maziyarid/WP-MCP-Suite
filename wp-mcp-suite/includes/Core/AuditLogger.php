<?php
/**
 * Append-only audit logger.
 *
 * Spec requirement: "Audit log for every view, edit, export, sync, backup,
 * and deletion" and "No PHI in logs, filenames, or Excel summaries unless
 * explicitly approved." This class is the single write path to
 * wp_mcp_audit_log — no other module may INSERT into that table directly,
 * so this redaction/shape rule is enforced in exactly one place.
 *
 * @package MCPSuite\Core
 */

declare( strict_types = 1 );

namespace MCPSuite\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AuditLogger {

	public const ACTION_VIEW    = 'view';
	public const ACTION_EDIT    = 'edit';
	public const ACTION_EXPORT  = 'export';
	public const ACTION_SYNC    = 'sync';
	public const ACTION_BACKUP  = 'backup';
	public const ACTION_DELETE  = 'delete';
	public const ACTION_LOGIN_FAILED      = 'login_failed';
	public const ACTION_PERMISSION_DENIED = 'permission_denied';

	private const VALID_ACTIONS = array(
		self::ACTION_VIEW,
		self::ACTION_EDIT,
		self::ACTION_EXPORT,
		self::ACTION_SYNC,
		self::ACTION_BACKUP,
		self::ACTION_DELETE,
		self::ACTION_LOGIN_FAILED,
		self::ACTION_PERMISSION_DENIED,
	);

	/**
	 * Field-name substrings that must never appear as keys in $detail. This
	 * is a defence-in-depth backstop, not a substitute for callers being
	 * disciplined about what they pass in — see spec "No PHI in logs."
	 *
	 * @var string[]
	 */
	private const BLOCKED_DETAIL_KEYS = array(
		'patient', 'diagnosis', 'dob', 'date_of_birth', 'ssn', 'medical_record',
		'phone', 'address', 'password', 'secret', 'token', 'api_key',
	);

	/**
	 * @param string               $action     One of the ACTION_* constants.
	 * @param string               $module     Module key, e.g. FeatureFlags::MODULE_CONTENT_SYNC.
	 * @param string               $object_type Free-form object type, e.g. 'post', 'backup_job'.
	 * @param int|null             $object_id  Numeric ID of the affected object, if any.
	 * @param bool                 $success    Whether the action succeeded.
	 * @param array<string,mixed>  $detail     Structured, non-sensitive context. Values are
	 *                                         truncated; keys matching BLOCKED_DETAIL_KEYS are
	 *                                         stripped rather than logged.
	 */
	public static function log(
		string $action,
		string $module,
		string $object_type = '',
		?int $object_id = null,
		bool $success = true,
		array $detail = array()
	): bool {
		if ( ! in_array( $action, self::VALID_ACTIONS, true ) ) {
			$action = self::ACTION_EDIT; // fail safe to a logged, non-silent default
		}

		global $wpdb;

		$table = $wpdb->prefix . 'mcp_audit_log';

		$actor_id   = get_current_user_id();
		$actor_type = $actor_id > 0 ? 'user' : 'system';

		$row = array(
			'event_time' => current_time( 'mysql', true ),
			'actor_id'   => $actor_id > 0 ? $actor_id : null,
			'actor_type' => $actor_type,
			'actor_ip'   => self::truncated_ip(),
			'action'     => $action,
			'object_type' => $object_type,
			'object_id'  => $object_id,
			'module'     => $module,
			'result'     => $success ? 'success' : 'failure',
			'detail_json' => wp_json_encode( self::sanitize_detail( $detail ) ),
			'request_id' => self::current_request_id(),
		);

		$formats = array( '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' );

		// Audit rows are never updated or deleted through plugin code.
		$inserted = $wpdb->insert( $table, $row, $formats ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return false !== $inserted;
	}

	/**
	 * @param array<string,mixed> $detail
	 * @return array<string,mixed>
	 */
	private static function sanitize_detail( array $detail ): array {
		$clean = array();

		foreach ( $detail as $key => $value ) {
			$key_lower = strtolower( (string) $key );

			foreach ( self::BLOCKED_DETAIL_KEYS as $blocked ) {
				if ( str_contains( $key_lower, $blocked ) ) {
					continue 2; // skip this key entirely
				}
			}

			if ( is_scalar( $value ) || null === $value ) {
				$value = (string) $value;
				$clean[ $key ] = mb_substr( $value, 0, 500 );
			}
			// Non-scalar values are dropped rather than serialized blindly —
			// arbitrary object graphs are a common accidental-PHI vector.
		}

		return $clean;
	}

	/**
	 * IPv4 addresses are truncated to /24 and IPv6 to /64 before storage.
	 * This keeps enough signal for abuse investigation while avoiding
	 * storing a precise, re-identifying address indefinitely.
	 */
	private static function truncated_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( '' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '0.0.0.0';
		}

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$parts    = explode( '.', $ip );
			$parts[3] = '0';
			return implode( '.', $parts );
		}

		$parts = explode( ':', $ip );
		return implode( ':', array_slice( $parts, 0, 4 ) ) . '::';
	}

	private static function current_request_id(): string {
		static $request_id = null;

		if ( null === $request_id ) {
			$request_id = wp_generate_uuid4();
		}

		return $request_id;
	}
}
