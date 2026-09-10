<?php
/**
 * Admin View: WordPress.org Review Rating Notice
 *
 * @package SimpleSalesTax
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$nonce      = wp_create_nonce( 'sst_review_notice_nonce' );
$review_url = 'https://wordpress.org/support/plugin/simple-sales-tax/reviews/?rate=5#new-post';
?>
<div id="sst-review-notice" class="notice notice-info sst-review-notice is-dismissible" data-nonce="<?php echo esc_attr( $nonce ); ?>">
	<div class="sst-review-notice-wrapper">
		<div class="sst-review-notice-icon-col">
			<img class="txc-notice-icon" srcset="https://ps.w.org/simple-sales-tax/assets/icon-128x128.png?rev=3326417, https://ps.w.org/simple-sales-tax/assets/icon-256x256.png?rev=3326417 2x" src="https://ps.w.org/simple-sales-tax/assets/icon-256x256.png?rev=3326417" alt="<?php esc_attr_e( 'TaxCloud for WooCommerce', 'simple-sales-tax' ); ?>">
		</div>
		<div class="sst-review-notice-content">
			<div class="sst-review-notice-header">
				<h3 class="sst-review-notice-title">
					<?php esc_html_e( 'Are you enjoying TaxCloud for WooCommerce?', 'simple-sales-tax' ); ?>
				</h3>
				<div class="sst-review-stars" aria-hidden="true">
					<span class="dashicons dashicons-star-filled"></span>
					<span class="dashicons dashicons-star-filled"></span>
					<span class="dashicons dashicons-star-filled"></span>
					<span class="dashicons dashicons-star-filled"></span>
					<span class="dashicons dashicons-star-filled"></span>
				</div>
			</div>
			<p class="sst-review-notice-message">
				<?php esc_html_e( 'We hope TaxCloud is saving you time with seamless, automated sales tax calculations! If you find our plugin helpful, please consider leaving us a 5-star rating on WordPress.org. It only takes a minute and helps us continue improving the plugin for you and the WooCommerce community.', 'simple-sales-tax' ); ?>
			</p>
			<div class="sst-review-notice-actions">
				<a href="<?php echo esc_url( $review_url ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary sst-review-action sst-review-action-rate" data-action="rate">
					<span class="dashicons dashicons-star-filled"></span>
					<?php esc_html_e( 'Leave a 5-Star Review', 'simple-sales-tax' ); ?>
				</a>
				<button type="button" class="button button-secondary sst-review-action sst-review-action-later" data-action="maybe_later">
					<span class="dashicons dashicons-clock"></span>
					<?php esc_html_e( 'Maybe Later', 'simple-sales-tax' ); ?>
				</button>
				<button type="button" class="button-link sst-review-action sst-review-action-done" data-action="already_did">
					<span class="dashicons dashicons-yes"></span>
					<?php esc_html_e( 'I Already Did', 'simple-sales-tax' ); ?>
				</button>
				<span class="sst-review-support">
					<?php
					printf(
						/* translators: %s: Contact Support link */
						esc_html__( 'Need help with something? %s', 'simple-sales-tax' ),
						'<a href="https://taxcloud.com/contact/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Contact Support', 'simple-sales-tax' ) . '</a>'
					);
					?>
				</span>
			</div>
		</div>
	</div>
	<script type="text/javascript">
		jQuery( document ).ready( function( $ ) {
			var $notice = $( '#sst-review-notice' );
			var nonce   = $notice.data( 'nonce' );

			function sendReviewAction( action, callback ) {
				$.post( ajaxurl, {
					action: 'sst_review_notice_action',
					notice_action: action,
					nonce: nonce
				} ).always( function() {
					if ( typeof callback === 'function' ) {
						callback();
					}
				} );
			}

			function dismissNoticeUI() {
				$notice.fadeTo( 150, 0, function() {
					$notice.slideUp( 200, function() {
						$notice.remove();
					} );
				} );
			}

			$notice.on( 'click', '.sst-review-action', function( e ) {
				var action = $( this ).data( 'action' );
				if ( 'rate' === action ) {
					sendReviewAction( 'rate' );
					dismissNoticeUI();
					return;
				}
				e.preventDefault();
				sendReviewAction( action );
				dismissNoticeUI();
			} );

			$notice.on( 'click', '.notice-dismiss', function() {
				sendReviewAction( 'maybe_later' );
			} );
		} );
	</script>
	<style>
		.sst-review-notice {
			padding: 14px 18px !important;
			border-left-color: #2271b1 !important;
			position: relative;
		}

		.sst-review-notice-wrapper {
			display: flex;
			align-items: flex-start;
			gap: 16px;
		}

		.sst-review-notice-icon-col {
			flex-shrink: 0;
		}

		.sst-review-notice-icon-col .txc-notice-icon {
			width: 48px;
			height: 48px;
			border-radius: 8px;
			display: block;
			margin: 0;
			box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
		}

		.sst-review-notice-content {
			flex: 1;
			min-width: 0;
		}

		.sst-review-notice-header {
			display: flex;
			align-items: center;
			flex-wrap: wrap;
			gap: 8px 12px;
			margin-bottom: 6px;
		}

		.sst-review-notice-title {
			margin: 0;
			font-size: 15px;
			font-weight: 600;
			color: #1d2327;
			line-height: 1.3;
		}

		.sst-review-stars {
			display: inline-flex;
			align-items: center;
			gap: 1px;
		}

		.sst-review-stars .dashicons {
			font-size: 18px;
			width: 18px;
			height: 18px;
			color: #f0b849;
		}

		.sst-review-notice-message {
			margin: 0 0 12px 0;
			font-size: 13.5px;
			line-height: 1.5;
			color: #3c434a;
			max-width: 900px;
		}

		.sst-review-notice-actions {
			display: flex;
			align-items: center;
			flex-wrap: wrap;
			gap: 10px 14px;
		}

		.sst-review-notice-actions .button {
			display: inline-flex;
			align-items: center;
			gap: 5px;
			font-size: 13px;
			line-height: 2;
			padding: 0 12px;
		}

		.sst-review-notice-actions .button .dashicons {
			font-size: 16px;
			width: 16px;
			height: 16px;
			line-height: 1.2;
		}

		.sst-review-action-rate {
			font-weight: 500;
		}

		.sst-review-action-rate .dashicons {
			color: #ffb900;
		}

		.sst-review-action-done {
			color: #646970 !important;
			font-size: 13px !important;
			text-decoration: none !important;
			padding: 0 4px !important;
			display: inline-flex !important;
			align-items: center !important;
			gap: 3px !important;
			cursor: pointer !important;
			border: none !important;
			background: none !important;
			box-shadow: none !important;
		}

		.sst-review-action-done .dashicons {
			font-size: 16px;
			width: 16px;
			height: 16px;
		}

		.sst-review-action-done:hover,
		.sst-review-action-done:focus {
			color: #135e96 !important;
			text-decoration: underline !important;
		}

		.sst-review-support {
			font-size: 12.5px;
			color: #646970;
			margin-left: auto;
		}

		.sst-review-support a {
			color: #2271b1;
			text-decoration: none;
		}

		.sst-review-support a:hover {
			text-decoration: underline;
		}

		@media (max-width: 782px) {
			.sst-review-notice-wrapper {
				flex-direction: column;
				gap: 10px;
			}

			.sst-review-support {
				margin-left: 0;
				width: 100%;
			}
		}
	</style>
</div>
