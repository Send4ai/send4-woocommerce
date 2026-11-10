<?php
/**
 * WooCommerce → Send4: the one screen.
 *
 * @package Send4WC
 *
 * @var Send4WC_Admin         $admin
 * @var array               $connection
 * @var array|WP_Error|null $handshake
 * @var array               $data       From Send4WC_Admin_Dashboard::build().
 */

defined( 'ABSPATH' ) || exit;

$send4wc_notice    = $admin->current_notice();
$send4wc_state     = $data['state'];
$send4wc_connected = 'connected' === $send4wc_state;
$send4wc_failing   = 'unreachable' === $send4wc_state || 'rejected' === $send4wc_state;
$send4wc_counts    = $data['counts'];
$send4wc_dead      = (int) $send4wc_counts[ Send4WC_Queue::STATUS_FAILED ] + (int) $send4wc_counts[ Send4WC_Queue::STATUS_PERMANENT ];

$send4wc_bar_title   = __( 'WooCommerce', 'send4-for-woocommerce' );
$send4wc_bar_state   = $send4wc_state;
$send4wc_bar_account = $data['account'];
?>
<div class="send4wc-app">
	<?php require SEND4WC_PLUGIN_DIR . 'admin/views/partials/app-bar.php'; ?>

	<div class="send4wc-body">
		<?php if ( $send4wc_notice ) : ?>
			<div class="send4wc-notice send4wc-notice--<?php echo esc_attr( $send4wc_notice['type'] ); ?>" role="<?php echo 'error' === $send4wc_notice['type'] ? 'alert' : 'status'; ?>">
				<p><?php echo esc_html( $send4wc_notice['message'] ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( ! Send4WC_Settings::base_url_is_allowed() ) : ?>
			<div class="send4wc-notice send4wc-notice--error">
				<p>
					<?php
					printf(
						/* translators: %s: the configured URL. */
						esc_html__( 'Send4 is configured to send to %s, which is not a Send4 address. Nothing will be sent until that is corrected.', 'send4-for-woocommerce' ),
						'<code>' . esc_html( Send4WC_Settings::base_url() ) . '</code>'
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<div class="send4wc-pagehead">
			<div>
				<h1><?php esc_html_e( 'Send4 for WooCommerce', 'send4-for-woocommerce' ); ?></h1>
				<p class="send4wc-sub"><?php esc_html_e( 'Texts each buyer when their order reaches a status you choose, and books the delivery or installation slot over SMS.', 'send4-for-woocommerce' ); ?></p>
			</div>
		</div>

		<div class="send4wc-grid send4wc-grid--3 send4wc-grid--gap-lg">
			<div class="send4wc-col-2 send4wc-stack">
				<div class="send4wc-card send4wc-card--pad">

					<?php // ── Step 1: the connection ───────────────────────────── ?>
					<div class="send4wc-step">
						<div class="send4wc-step__rail">
							<span class="send4wc-step__num <?php echo $send4wc_connected ? 'is-done' : 'is-active'; ?>">
								<?php if ( $send4wc_connected ) : ?>
									<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m20 6-11 11-5-5"></path></svg>
									<span class="screen-reader-text"><?php esc_html_e( 'Step 1, done', 'send4-for-woocommerce' ); ?></span>
								<?php else : ?>
									1
								<?php endif; ?>
							</span>
							<span class="send4wc-step__line" aria-hidden="true"></span>
						</div>
						<div class="send4wc-step__body">
							<h2><?php esc_html_e( 'Connect your Send4 account', 'send4-for-woocommerce' ); ?></h2>

							<?php if ( $send4wc_connected ) : ?>
								<p class="send4wc-sub">
									<?php
									printf(
										/* translators: %s: Send4 account name. */
										esc_html__( 'Connected to %s.', 'send4-for-woocommerce' ),
										'<strong>' . esc_html( $data['account'] ) . '</strong>'
									);
									?>
								</p>
								<?php if ( '' !== $admin->format_utc( $connection['connected_at'] ) ) : ?>
									<p class="send4wc-xs send4wc-muted">
										<?php
										printf(
											/* translators: %s: date and time the store was connected, in the site's own format. */
											esc_html__( 'Since %s', 'send4-for-woocommerce' ),
											esc_html( $admin->format_utc( $connection['connected_at'] ) )
										);
										?>
									</p>
								<?php endif; ?>
							<?php elseif ( $send4wc_failing ) : ?>
								<div class="send4wc-notice <?php echo 'rejected' === $send4wc_state ? 'send4wc-notice--error' : 'send4wc-notice--warn'; ?> send4wc-notice--inline">
									<p><strong><?php echo esc_html( Send4WC_Admin::outage_message( $send4wc_state ) ); ?></strong></p>
									<p><?php echo esc_html( is_wp_error( $handshake ) ? $handshake->get_error_message() : '' ); ?></p>
								</div>
							<?php endif; ?>

							<?php if ( $send4wc_connected || $send4wc_failing ) : ?>
								<div class="send4wc-actions">
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="send4wc-inline-form">
										<?php $admin->nonce_field( 'send4wc_test' ); ?>
										<input type="hidden" name="action" value="send4wc_test" />
										<button type="submit" class="send4wc-btn"><?php esc_html_e( 'Test connection', 'send4-for-woocommerce' ); ?></button>
									</form>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="send4wc-inline-form">
										<?php $admin->nonce_field( 'send4wc_disconnect' ); ?>
										<input type="hidden" name="action" value="send4wc_disconnect" />
										<button type="submit" class="send4wc-linkbtn send4wc-linkbtn--danger"><?php esc_html_e( 'Disconnect', 'send4-for-woocommerce' ); ?></button>
									</form>
								</div>
							<?php endif; ?>

							<?php if ( ! $send4wc_connected && 'unreachable' !== $send4wc_state ) : ?>
								<?php if ( 'rejected' === $send4wc_state ) : ?>
									<p class="send4wc-sub"><?php esc_html_e( 'If this store\'s key was revoked or replaced in Send4, connect again. The current credentials are kept until new ones are proven to work.', 'send4-for-woocommerce' ); ?></p>
								<?php else : ?>
									<p class="send4wc-sub"><?php esc_html_e( 'You will approve this on Send4 and come straight back. Nothing to copy, and it works for a store on your own machine or behind a password too. If you use the Send4 forms plugin as well, it keeps its own connection.', 'send4-for-woocommerce' ); ?></p>
								<?php endif; ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="send4wc-step__form">
									<?php $admin->nonce_field( 'send4wc_start_connect' ); ?>
									<input type="hidden" name="action" value="send4wc_start_connect" />
									<button type="submit" class="send4wc-btn send4wc-btn--primary"><?php esc_html_e( 'Connect with Send4', 'send4-for-woocommerce' ); ?></button>
								</form>

							<?php endif; ?>
						</div>
					</div>

					<?php // ── Step 2: what Send4 wants from the store ─────────────── ?>
					<div class="send4wc-step send4wc-step--last">
						<div class="send4wc-step__rail">
							<span class="send4wc-step__num <?php echo $send4wc_connected ? 'is-active' : ''; ?>">2</span>
						</div>
						<div class="send4wc-step__body">
							<h2><?php esc_html_e( 'Choose the order statuses in Send4', 'send4-for-woocommerce' ); ?></h2>
							<p class="send4wc-sub"><?php esc_html_e( 'A Send4 flow with a "WooCommerce order" trigger names the statuses that start it, such as Processing or your own "Ready for delivery". Only those leave this store, plus Cancelled and Refunded, so a flow stops texting about an order that is off.', 'send4-for-woocommerce' ); ?></p>

							<?php if ( ! Send4WC_Settings::has_credentials() ) : ?>
								<div class="send4wc-locked">
									<span class="send4wc-muted"><?php esc_html_e( 'Available once your account is connected', 'send4-for-woocommerce' ); ?></span>
								</div>
							<?php else : ?>
								<?php if ( $data['phone_optional'] ) : ?>
									<div class="send4wc-notice send4wc-notice--warn send4wc-notice--inline">
										<p><?php esc_html_e( 'Your checkout lets buyers leave the phone number empty, and an order without one can never be texted. Make the phone field required in the checkout settings.', 'send4-for-woocommerce' ); ?></p>
									</div>
								<?php endif; ?>

								<?php if ( empty( $data['start_topics'] ) ) : ?>
									<div class="send4wc-notice send4wc-notice--warn send4wc-notice--inline">
										<p><?php esc_html_e( 'No Send4 flow starts on an order status yet, so no order is sent. Create a flow with a "WooCommerce order" trigger in Send4, then sync.', 'send4-for-woocommerce' ); ?></p>
									</div>
								<?php else : ?>
									<div class="send4wc-table-wrap">
										<table class="send4wc-table">
											<thead>
												<tr>
													<th scope="col"><?php esc_html_e( 'Order status', 'send4-for-woocommerce' ); ?></th>
													<th scope="col"><?php esc_html_e( 'Sent to Send4', 'send4-for-woocommerce' ); ?></th>
												</tr>
											</thead>
											<tbody>
											<?php foreach ( $data['start_topics'] as $send4wc_topic ) : ?>
												<tr>
													<td class="send4wc-strong"><?php echo esc_html( $send4wc_topic['label'] ); ?></td>
													<td><span class="send4wc-badge send4wc-badge--ok"><?php esc_html_e( 'Starts a flow, with the order details', 'send4-for-woocommerce' ); ?></span></td>
												</tr>
											<?php endforeach; ?>
											<?php foreach ( $data['ending_topics'] as $send4wc_topic ) : ?>
												<tr>
													<td class="send4wc-strong"><?php echo esc_html( $send4wc_topic['label'] ); ?></td>
													<td><span class="send4wc-badge send4wc-badge--neutral"><?php esc_html_e( 'Ends the order\'s conversation, number only', 'send4-for-woocommerce' ); ?></span></td>
												</tr>
											<?php endforeach; ?>
											</tbody>
										</table>
									</div>
								<?php endif; ?>

								<div class="send4wc-actions">
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="send4wc-inline-form">
										<?php $admin->nonce_field( 'send4wc_sync_store' ); ?>
										<input type="hidden" name="action" value="send4wc_sync_store" />
										<button type="submit" class="send4wc-btn"><?php esc_html_e( 'Sync now', 'send4-for-woocommerce' ); ?></button>
									</form>
								</div>
								<p class="send4wc-xs send4wc-muted">
									<?php
									if ( '' !== $admin->format_utc( $data['store_sync']['at'] ) ) {
										printf(
											/* translators: %s: date and time of the last sync. */
											esc_html__( 'Send4 last received this store\'s statuses and categories on %s. The store asks again every hour, and whenever this screen is opened.', 'send4-for-woocommerce' ),
											esc_html( $admin->format_utc( $data['store_sync']['at'] ) )
										);
									} else {
										esc_html_e( 'The store tells Send4 its statuses and categories every hour, and whenever this screen is opened.', 'send4-for-woocommerce' );
									}
									?>
								</p>
								<?php if ( '' !== $data['store_sync']['error'] ) : ?>
									<p class="send4wc-xs send4wc-warning"><?php echo esc_html( $data['store_sync']['error'] ); ?></p>
								<?php endif; ?>
							<?php endif; ?>
						</div>
					</div>
				</div>

				<?php // ── Order events ──────────────────────────────────────── ?>
				<div class="send4wc-card send4wc-card--pad">
					<div class="send4wc-card__head">
						<h2 class="send4wc-card__title"><?php esc_html_e( 'Order events', 'send4-for-woocommerce' ); ?></h2>
					</div>

					<?php if ( ! $data['has_queue'] ) : ?>
						<div class="send4wc-notice send4wc-notice--error send4wc-notice--inline">
							<p><?php esc_html_e( 'The plugin\'s queue table is missing, so order events cannot be kept for a retry. Deactivate and activate the plugin to create it.', 'send4-for-woocommerce' ); ?></p>
						</div>
					<?php endif; ?>

					<div class="send4wc-grid send4wc-grid--3">
						<div class="send4wc-stat">
							<span class="send4wc-stat__label"><?php esc_html_e( 'Waiting to send', 'send4-for-woocommerce' ); ?></span>
							<span class="send4wc-stat__value"><?php echo esc_html( number_format_i18n( (int) $send4wc_counts[ Send4WC_Queue::STATUS_PENDING ] ) ); ?></span>
						</div>
						<div class="send4wc-stat">
							<span class="send4wc-stat__label"><?php esc_html_e( 'Failed', 'send4-for-woocommerce' ); ?></span>
							<span class="send4wc-stat__value"><?php echo esc_html( number_format_i18n( $send4wc_dead ) ); ?></span>
						</div>
						<div class="send4wc-stat">
							<span class="send4wc-stat__label"><?php esc_html_e( 'Last answer', 'send4-for-woocommerce' ); ?></span>
							<span class="send4wc-stat__value send4wc-stat__value--sm">
								<span class="send4wc-badge send4wc-badge--<?php echo esc_attr( $admin->status_tone( $data['delivery']['status'] ) ); ?>"><?php echo esc_html( $admin->status_label( $data['delivery']['status'] ) ); ?></span>
							</span>
							<?php if ( '' !== $admin->format_utc( $data['delivery']['at'] ) ) : ?>
								<span class="send4wc-stat__note"><?php echo esc_html( $admin->format_utc( $data['delivery']['at'] ) ); ?></span>
							<?php endif; ?>
						</div>
					</div>

					<?php if ( '' !== $data['delivery']['error'] && in_array( $data['delivery']['status'], array( 'queued', 'failed' ), true ) ) : ?>
						<p class="send4wc-xs send4wc-warning"><?php echo esc_html( $data['delivery']['error'] ); ?></p>
					<?php endif; ?>

					<?php if ( Send4WC_Settings::has_credentials() ) : ?>
						<div class="send4wc-actions">
							<?php if ( (int) $send4wc_counts[ Send4WC_Queue::STATUS_PENDING ] > 0 ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="send4wc-inline-form">
									<?php $admin->nonce_field( 'send4wc_run_queue' ); ?>
									<input type="hidden" name="action" value="send4wc_run_queue" />
									<button type="submit" class="send4wc-btn"><?php esc_html_e( 'Send waiting events now', 'send4-for-woocommerce' ); ?></button>
								</form>
							<?php endif; ?>
							<?php if ( $send4wc_dead > 0 ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="send4wc-inline-form">
									<?php $admin->nonce_field( 'send4wc_requeue' ); ?>
									<input type="hidden" name="action" value="send4wc_requeue" />
									<button type="submit" class="send4wc-btn"><?php esc_html_e( 'Resend failed events', 'send4-for-woocommerce' ); ?></button>
								</form>
							<?php endif; ?>
						</div>
					<?php endif; ?>

					<?php if ( ! empty( $data['rows'] ) ) : ?>
						<div class="send4wc-table-wrap">
							<table class="send4wc-table">
								<thead>
									<tr>
										<th scope="col"><?php esc_html_e( 'Order', 'send4-for-woocommerce' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Status', 'send4-for-woocommerce' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Queue', 'send4-for-woocommerce' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Attempts', 'send4-for-woocommerce' ); ?></th>
									</tr>
								</thead>
								<tbody>
								<?php foreach ( $data['rows'] as $send4wc_row ) : ?>
									<?php $send4wc_reference = Send4WC_Queue::envelope( $send4wc_row ); ?>
									<tr>
										<td>#<?php echo esc_html( isset( $send4wc_reference['order']['id'] ) ? (string) $send4wc_reference['order']['id'] : '' ); ?></td>
										<td><code><?php echo esc_html( substr( (string) $send4wc_row->topic, strlen( Send4WC_Order_Payload::TOPIC_PREFIX ) ) ); ?></code></td>
										<td>
											<?php echo esc_html( Send4WC_Queue::STATUS_PENDING === $send4wc_row->status ? __( 'Waiting', 'send4-for-woocommerce' ) : __( 'Failed', 'send4-for-woocommerce' ) ); ?>
											<?php if ( '' !== (string) $send4wc_row->last_error ) : ?>
												<br /><span class="send4wc-xs send4wc-muted"><?php echo esc_html( (string) $send4wc_row->last_error ); ?></span>
											<?php endif; ?>
										</td>
										<td class="send4wc-table__num"><?php echo esc_html( number_format_i18n( (int) $send4wc_row->attempts ) ); ?></td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</div>

				<?php // ── Activity log ──────────────────────────────────────── ?>
				<div class="send4wc-card send4wc-card--pad">
					<div class="send4wc-card__head">
						<h2 class="send4wc-card__title"><?php esc_html_e( 'Activity', 'send4-for-woocommerce' ); ?></h2>
						<?php if ( ! empty( $data['log'] ) ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="send4wc-inline-form">
								<?php $admin->nonce_field( 'send4wc_clear_log' ); ?>
								<input type="hidden" name="action" value="send4wc_clear_log" />
								<button type="submit" class="send4wc-linkbtn"><?php esc_html_e( 'Clear', 'send4-for-woocommerce' ); ?></button>
							</form>
						<?php endif; ?>
					</div>
					<?php if ( empty( $data['log'] ) ) : ?>
						<p class="send4wc-muted"><?php esc_html_e( 'Nothing yet. Each order event the store queues or sends is listed here, without the buyer\'s details.', 'send4-for-woocommerce' ); ?></p>
					<?php else : ?>
						<div class="send4wc-table-wrap">
							<table class="send4wc-table">
								<tbody>
								<?php foreach ( $data['log'] as $send4wc_entry ) : ?>
									<tr>
										<td class="send4wc-xs send4wc-muted"><?php echo esc_html( $admin->format_utc( isset( $send4wc_entry['at'] ) ? (string) $send4wc_entry['at'] : '' ) ); ?></td>
										<td>
											<?php echo esc_html( isset( $send4wc_entry['message'] ) ? (string) $send4wc_entry['message'] : '' ); ?>
											<?php if ( ! empty( $send4wc_entry['context']['event_id'] ) ) : ?>
												<br /><code class="send4wc-xs"><?php echo esc_html( (string) $send4wc_entry['context']['event_id'] ); ?></code>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</div>

				<p class="send4wc-xs send4wc-muted send4wc-footnote">
					<?php esc_html_e( 'Sending to', 'send4-for-woocommerce' ); ?>
					<code><?php echo esc_html( Send4WC_Settings::base_url() ); ?></code> —
					<?php esc_html_e( 'order details and credentials only ever go to Send4.', 'send4-for-woocommerce' ); ?>
				</p>
			</div>

			<div class="send4wc-col-1 send4wc-stack">
				<div class="send4wc-card send4wc-card--pad">
					<h2 class="send4wc-eyebrow"><?php esc_html_e( 'What it does', 'send4-for-woocommerce' ); ?></h2>
					<ul class="send4wc-ticks">
						<li><?php esc_html_e( 'Texts the buyer the moment an order reaches the status you choose', 'send4-for-woocommerce' ); ?></li>
						<li><?php esc_html_e( 'Books the delivery or installation slot in the conversation', 'send4-for-woocommerce' ); ?></li>
						<li><?php esc_html_e( 'Stops texting when an order is cancelled or refunded', 'send4-for-woocommerce' ); ?></li>
						<li><?php esc_html_e( 'Never slows the checkout, and loses nothing when Send4 is briefly unreachable', 'send4-for-woocommerce' ); ?></li>
					</ul>
				</div>

				<div class="send4wc-card send4wc-card--pad">
					<div class="send4wc-promo">
						<span class="send4wc-tile" aria-hidden="true">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"></path></svg>
						</span>
						<div>
							<p class="send4wc-strong"><?php esc_html_e( 'New to Send4?', 'send4-for-woocommerce' ); ?></p>
							<a class="send4wc-link" href="https://send4.ai" target="_blank" rel="noopener noreferrer">
								<?php esc_html_e( 'See how it works →', 'send4-for-woocommerce' ); ?>
							</a>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
