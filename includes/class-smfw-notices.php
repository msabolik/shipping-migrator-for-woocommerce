<?php
/**
 * One-shot admin notice storage.
 *
 * @package Shipping_Migrator_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SMFW_Notices
 *
 * Import and export both run on `admin_init`, which fires before any output.
 * The import in particular finishes with a long log that we want to show on
 * the plugin screen — but by the time the screen renders, the request that
 * produced the log has already ended (the form POSTs and then the page
 * reloads).
 *
 * A short-lived transient carries the message across that boundary. It is
 * deliberately one-shot: read once, then deleted, so a refresh does not
 * redisplay a stale import log.
 */
class SMFW_Notices {

	/**
	 * Transient key holding the pending notice.
	 *
	 * @var string
	 */
	const TRANSIENT_KEY = 'smfw_admin_notice';

	/**
	 * How long a queued notice survives if never displayed, in seconds.
	 *
	 * Sixty seconds is enough to cover the redirect that follows a POST.
	 * Anything longer risks showing a message from a previous session.
	 *
	 * @var int
	 */
	const TTL = 60;

	/**
	 * Queue a notice for display on the next admin page load.
	 *
	 * @param string $message Notice body. May contain limited HTML; it is run
	 *                        through wp_kses_post() at render time.
	 * @param string $type    One of: success, error, warning, info. Maps onto
	 *                        the core `notice-{type}` CSS classes.
	 * @return void
	 */
	public static function add( $message, $type = 'success' ) {
		set_transient(
			self::TRANSIENT_KEY,
			array(
				'message' => $message,
				'type'    => $type,
			),
			self::TTL
		);
	}

	/**
	 * Retrieve and consume the pending notice.
	 *
	 * @return array|false Array with 'message' and 'type' keys, or false when
	 *                     no notice is queued.
	 */
	public static function pull() {
		$notice = get_transient( self::TRANSIENT_KEY );

		if ( $notice ) {
			// Consume immediately so a page refresh does not repeat it.
			delete_transient( self::TRANSIENT_KEY );
		}

		return $notice;
	}
}
