<?php
/**
 * Message functions.
 *
 * Functions for displaying admin notices.
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Do not all direct access.
}

/**
 * Queue a message for display.
 *
 * @param string $content Message content.
 * @param string $type    Type of message. Can be 'error' or 'updated' (default: 'error').
 *
 * @since 5.0
 */
function sst_add_message( $content, $type = 'error' ) {
	$all_messages = get_option( 'sst_messages' );

	if ( ! is_array( $all_messages ) ) {
		$all_messages = array();
	}

	$all_messages[] = array(
		'content' => $content,
		'type'    => $type,
	);

	update_option( 'sst_messages', $all_messages );
}

/**
 * Print queued messages.
 *
 * @since 5.0
 */
function sst_print_messages() {
	$all_messages = get_option( 'sst_messages' );

	if ( ! is_array( $all_messages ) ) {
		return;
	}

	foreach ( $all_messages as $message ) {
		printf( "<div class='%s'><p>%s</p></div>", esc_attr( $message['type'] ), esc_html( $message['content'] ) );
	}

	update_option( 'sst_messages', array() );
}

add_action( 'admin_notices', 'sst_print_messages' );
