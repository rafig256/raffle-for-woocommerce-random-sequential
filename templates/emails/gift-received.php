<?php
/**
 * Gift Received Email (HTML).
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 *
 * @var WC_Order           $order              Order object.
 * @var array              $tickets            Array of RFWC_Ticket objects.
 * @var RFWC_Product_Raffle  $raffle             Raffle product.
 * @var string             $gift_message       Gift message from purchaser.
 * @var string             $purchaser_name     Purchaser name.
 * @var string             $email_heading      Email heading.
 * @var string             $additional_content Additional content.
 * @var bool               $sent_to_admin      Is sent to admin.
 * @var bool               $plain_text         Is plain text.
 * @var RFWC_Email_Gift_Received $email         Email object.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables from wc_get_template().
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Using WooCommerce core hooks.

// Handle preview mode with sample data.
$is_preview     = empty( $order ) || empty( $tickets ) || empty( $raffle );
$recipient_name = __( 'Jane', 'raffle-for-woocommerce' );

if ( ! $is_preview && ! empty( $tickets ) ) {
	$first_ticket   = reset( $tickets );
	$recipient_name = $first_ticket->get_recipient_name();
}

$raffle_name    = $is_preview ? __( 'Sample Raffle', 'raffle-for-woocommerce' ) : $raffle->get_name();
$raffle_link    = $is_preview ? '#' : get_permalink( $raffle->get_id() );
$ticket_count   = $is_preview ? 2 : count( $tickets );
$sender_name    = $is_preview ? __( 'John Doe', 'raffle-for-woocommerce' ) : $purchaser_name;
$sample_message = $is_preview ? __( 'Good luck! Hope you win!', 'raffle-for-woocommerce' ) : $gift_message;

/*
 * @hooked WC_Emails::email_header() Output the email header.
 */
do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core hook.
?>

<p>
	<?php
	printf(
		/* translators: %s: Recipient first name */
		esc_html__( 'Hi %s,', 'raffle-for-woocommerce' ),
		esc_html( explode( ' ', $recipient_name )[0] )
	);
	?>
</p>

<p>
	<?php
	printf(
		esc_html(
			/* translators: %1$s: purchaser name, %2$d: number of tickets, %3$s: raffle name */
			_n(
				'Great news! %1$s has gifted you %2$d raffle ticket for %3$s!',
				'Great news! %1$s has gifted you %2$d raffle tickets for %3$s!',
				$ticket_count,
				'raffle-for-woocommerce'
			)
		),
		'<strong>' . esc_html( $sender_name ) . '</strong>',
		(int) $ticket_count,
		'<strong>' . esc_html( $raffle_name ) . '</strong>'
	);
	?>
</p>

<?php if ( ! empty( $sample_message ) ) : ?>
	<div style="background-color: #f9f5ff; border-left: 4px solid #7f54b3; padding: 15px 20px; margin: 20px 0;">
		<p style="margin: 0 0 10px; font-weight: bold; color: #333;">
			<?php esc_html_e( 'Message from the sender:', 'raffle-for-woocommerce' ); ?>
		</p>
		<p style="margin: 0; font-style: italic; color: #555;">
			"<?php echo esc_html( $sample_message ); ?>"
		</p>
	</div>
<?php endif; ?>

<h2 style="margin-top: 30px;"><?php esc_html_e( 'Your Ticket Numbers', 'raffle-for-woocommerce' ); ?></h2>

<table cellspacing="0" cellpadding="6" border="1" style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
	<thead>
		<tr>
			<th scope="col" style="text-align: left; border: 1px solid #e5e5e5; padding: 12px; background-color: #f8f8f8;">
				<?php esc_html_e( 'Ticket Number', 'raffle-for-woocommerce' ); ?>
			</th>
			<th scope="col" style="text-align: left; border: 1px solid #e5e5e5; padding: 12px; background-color: #f8f8f8;">
				<?php esc_html_e( 'Status', 'raffle-for-woocommerce' ); ?>
			</th>
		</tr>
	</thead>
	<tbody>
		<?php if ( $is_preview ) : ?>
			<tr>
				<td style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;">
					<strong style="font-family: monospace; font-size: 16px; color: #7f54b3;">0001</strong>
				</td>
				<td style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;">
					<span style="color: #5b841b;"><?php esc_html_e( 'Active', 'raffle-for-woocommerce' ); ?></span>
				</td>
			</tr>
			<tr>
				<td style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;">
					<strong style="font-family: monospace; font-size: 16px; color: #7f54b3;">0002</strong>
				</td>
				<td style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;">
					<span style="color: #5b841b;"><?php esc_html_e( 'Active', 'raffle-for-woocommerce' ); ?></span>
				</td>
			</tr>
		<?php else : ?>
			<?php foreach ( $tickets as $ticket ) : ?>
				<tr>
					<td style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;">
						<strong style="font-family: monospace; font-size: 16px; color: #7f54b3;">
							<?php echo esc_html( $ticket->get_formatted_ticket_number() ); ?>
						</strong>
					</td>
					<td style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;">
						<span style="color: #5b841b;">
							<?php esc_html_e( 'Active', 'raffle-for-woocommerce' ); ?>
						</span>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
	</tbody>
</table>

<h2><?php esc_html_e( 'Raffle Details', 'raffle-for-woocommerce' ); ?></h2>

<table cellspacing="0" cellpadding="6" border="1" style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
	<tbody>
		<tr>
			<th scope="row" style="text-align: left; border: 1px solid #e5e5e5; padding: 12px; background-color: #f8f8f8; width: 30%;">
				<?php esc_html_e( 'Raffle', 'raffle-for-woocommerce' ); ?>
			</th>
			<td style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;">
				<a href="<?php echo esc_url( $raffle_link ); ?>">
					<?php echo esc_html( $raffle_name ); ?>
				</a>
			</td>
		</tr>
		<?php if ( ! $is_preview && method_exists( $raffle, 'get_end_date' ) && $raffle->get_end_date() ) : ?>
			<tr>
				<th scope="row" style="text-align: left; border: 1px solid #e5e5e5; padding: 12px; background-color: #f8f8f8;">
					<?php esc_html_e( 'Draw Date', 'raffle-for-woocommerce' ); ?>
				</th>
				<td style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;">
					<?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $raffle->get_end_date() ) ) ); ?>
				</td>
			</tr>
		<?php endif; ?>
		<?php if ( ! $is_preview && method_exists( $raffle, 'get_prize_name' ) && $raffle->get_prize_name() ) : ?>
			<tr>
				<th scope="row" style="text-align: left; border: 1px solid #e5e5e5; padding: 12px; background-color: #f8f8f8;">
					<?php esc_html_e( 'Prize', 'raffle-for-woocommerce' ); ?>
				</th>
				<td style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;">
					<?php echo esc_html( $raffle->get_prize_name() ); ?>
				</td>
			</tr>
		<?php endif; ?>
	</tbody>
</table>

<div style="background-color: #f8f8f8; padding: 15px 20px; margin: 20px 0; border-radius: 4px;">
	<p style="margin: 0;">
		<strong><?php esc_html_e( 'Want to track your tickets?', 'raffle-for-woocommerce' ); ?></strong><br>
		<?php
		printf(
			/* translators: %s: site URL */
			esc_html__( 'Create an account at %s using this email address to view and manage your raffle tickets.', 'raffle-for-woocommerce' ),
			'<a href="' . esc_url( wc_get_page_permalink( 'myaccount' ) ) . '">' . esc_html( get_bloginfo( 'name' ) ) . '</a>'
		);
		?>
	</p>
</div>

<?php
/**
 * Show additional content.
 */
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

/*
 * @hooked WC_Emails::email_footer() Output the email footer.
 */
do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core hook.
