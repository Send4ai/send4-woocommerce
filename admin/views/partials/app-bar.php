<?php
/**
 * The bar across the top of every Send4 screen.
 *
 * @package Send4WC
 *
 * @var Send4WC_Admin $admin
 * @var string      $send4wc_bar_title   Breadcrumb leaf, e.g. "Dashboard".
 * @var string      $send4wc_bar_state   A Send4WC_Admin::connection_state() value.
 * @var string      $send4wc_bar_account Account name for the pill.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="send4wc-appbar">
	<div class="send4wc-appbar__brand">
		<span class="send4wc-mark" aria-hidden="true">
			<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M22 2 11 13"></path><path d="M22 2 15 22l-4-9-9-4Z"></path></svg>
		</span>
		<span class="send4wc-appbar__name"><?php esc_html_e( 'Send4', 'send4-for-woocommerce' ); ?></span>
		<span class="send4wc-appbar__sep" aria-hidden="true">/</span>
		<span class="send4wc-appbar__leaf"><?php echo esc_html( $send4wc_bar_title ); ?></span>
	</div>

	<div class="send4wc-appbar__actions">
		<?php if ( 'connected' === $send4wc_bar_state ) : ?>
			<span class="send4wc-pill send4wc-pill--ok">
				<span class="send4wc-dot" aria-hidden="true"></span>
				<?php
				if ( '' === $send4wc_bar_account ) {
					esc_html_e( 'Connected', 'send4-for-woocommerce' );
				} else {
					printf(
						/* translators: %s: Send4 account name. */
						esc_html__( 'Connected · %s', 'send4-for-woocommerce' ),
						esc_html( $send4wc_bar_account )
					);
				}
				?>
			</span>
		<?php elseif ( 'unreachable' === $send4wc_bar_state ) : ?>
			<span class="send4wc-pill send4wc-pill--warn">
				<span class="send4wc-dot" aria-hidden="true"></span>
				<?php esc_html_e( 'Send4 unreachable', 'send4-for-woocommerce' ); ?>
			</span>
		<?php elseif ( 'rejected' === $send4wc_bar_state ) : ?>
			<span class="send4wc-pill send4wc-pill--bad">
				<span class="send4wc-dot" aria-hidden="true"></span>
				<?php esc_html_e( 'Connection refused', 'send4-for-woocommerce' ); ?>
			</span>
		<?php elseif ( 'unchecked' === $send4wc_bar_state ) : ?>
			<span class="send4wc-pill send4wc-pill--neutral">
				<span class="send4wc-dot" aria-hidden="true"></span>
				<?php esc_html_e( 'Connection not checked', 'send4-for-woocommerce' ); ?>
			</span>
		<?php else : ?>
			<span class="send4wc-pill send4wc-pill--warn">
				<span class="send4wc-dot" aria-hidden="true"></span>
				<?php esc_html_e( 'Not connected', 'send4-for-woocommerce' ); ?>
			</span>
		<?php endif; ?>

		<a class="send4wc-btn send4wc-btn--ghost" href="<?php echo esc_url( Send4WC_Settings::base_url() ); ?>" target="_blank" rel="noopener noreferrer">
			<?php esc_html_e( 'Open web app', 'send4-for-woocommerce' ); ?>
			<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M7 17 17 7"></path><path d="M7 7h10v10"></path></svg>
		</a>
	</div>
</div>
