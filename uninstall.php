<?php
/**
 * Uninstall Raffle for WooCommerce.
 *
 * Removes all plugin data when uninstalled.
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 */

// Exit if accessed directly or not uninstalling.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Only remove all data if the option is set.
$rfwc_remove_data = get_option( 'rfwc_remove_data_on_uninstall', 'no' );

if ( 'yes' !== $rfwc_remove_data ) {
	return;
}

// Delete options.
$rfwc_options = array(
	// Core.
	'rfwc_version',
	'rfwc_db_version',
	'rfwc_flush_rewrite_rules',
	'rfwc_welcome_notice_dismissed',
	'rfwc_installed_at',
	'rfwc_completed_raffles_count',
	'rfwc_remove_data_on_uninstall',

	// General.
	'rfwc_enable_gift_purchase',
	'rfwc_enable_gift_self_claim',
	'rfwc_ticket_number_format',
	'rfwc_ticket_prefix',
	'rfwc_ticket_start_number',
	'rfwc_ticket_number_padding',
	'rfwc_enable_recipient_email',
	'rfwc_auto_generate_on_payment',
	'rfwc_generate_tickets_on',
	'rfwc_auto_close_sold_out',
	'rfwc_default_selection_method',
	'rfwc_random_org_api_key',
	'rfwc_block_mixed_checkout',
	'rfwc_guest_access',
	'rfwc_allow_manual_selection',

	// Modules.
	'rfwc_enable_bulk_discounts',
	'rfwc_enable_instant_win',

	// Display.
	'rfwc_show_countdown',
	'rfwc_show_progress_bar',
	'rfwc_show_participants_count',
	'rfwc_show_tickets_sold',
	'rfwc_show_prize_value',
	'rfwc_show_shop_countdown',
	'rfwc_show_instant_win_badge',
	'rfwc_use_range_slider',
	'rfwc_show_winner_product_image',
	'rfwc_show_winner_prize_image',
	'rfwc_winner_location_display',
	'rfwc_instant_win_badge_align',
	'rfwc_show_instant_win_ribbon',
	'rfwc_instant_win_ribbon_position',

	// Accent color.
	'rfwc_frontend_accent_color',

	// Print ticket.
	'rfwc_ticket_primary_color',
	'rfwc_ticket_footer_text',
	'rfwc_ticket_badge_text',
	'rfwc_qr_code_size',

	// Compliance.
	'rfwc_enable_skill_question',
	'rfwc_skill_question_pool',
	'rfwc_skill_question_time_limit',
	'rfwc_skill_question_max_attempts',
	'rfwc_skill_question_mc_display',
	'rfwc_enable_free_entry',
	'rfwc_free_entry_text',

	// Email toggles.
	'rfwc_email_ticket_confirmation',
	'rfwc_email_gift_notification',
	'rfwc_email_winner_notification',
	'rfwc_email_admin_winner',
	'rfwc_email_admin_draw_failed',
	'rfwc_email_prize_claimed',
	'rfwc_email_ticket_refunded',
	'rfwc_email_admin_mass_refund',

	// WooCommerce email settings.
	'woocommerce_rfwc_ticket_confirmation_settings',
	'woocommerce_rfwc_gift_received_settings',
	'woocommerce_rfwc_winner_notification_settings',
	'woocommerce_rfwc_admin_winner_alert_settings',
	'woocommerce_rfwc_admin_draw_failed_settings',
	'woocommerce_rfwc_prize_claimed_settings',
	'woocommerce_rfwc_ticket_refunded_settings',
	'woocommerce_rfwc_admin_mass_refund_settings',
);

foreach ( $rfwc_options as $rfwc_option ) {
	delete_option( $rfwc_option );
}

// Review prompt state stored per user.
delete_metadata( 'user', 0, 'rfwc_review_prompt_dismissed', '', true );
delete_metadata( 'user', 0, 'rfwc_review_prompt_remind_at', '', true );

// Delete post meta (product meta).
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_rfwc_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// Delete order meta (HPOS-compatible: try wc_orders_meta table if it exists).
$rfwc_orders_meta_table = esc_sql( $wpdb->prefix . 'wc_orders_meta' );
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $rfwc_orders_meta_table ) ) === $rfwc_orders_meta_table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DELETE FROM {$rfwc_orders_meta_table} WHERE meta_key LIKE '_rfwc_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name escaped via esc_sql().
}

// Delete dynamic ticket meta options.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'rfwc\_ticket\_meta\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// Drop custom tables.
$rfwc_tables = array(
	esc_sql( $wpdb->prefix . 'rfwc_tickets' ),
	esc_sql( $wpdb->prefix . 'rfwc_winners' ),
	esc_sql( $wpdb->prefix . 'rfwc_log' ),
);

foreach ( $rfwc_tables as $rfwc_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$rfwc_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

// Delete transients.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_rfwc_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_rfwc_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// Clear any cached data.
wp_cache_flush();
