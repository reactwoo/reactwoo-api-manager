<?php
/**
 * Operator correction for PLAN.md state 6 (Cloud + covered individuals still renewing).
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$notice   = isset( $notice ) ? $notice : '';
$detected = isset( $detected ) && is_array( $detected ) ? $detected : null;
$cloud    = isset( $cloud ) ? $cloud : null;
$subscription_id = 0;
if ( is_object( $cloud ) && method_exists( $cloud, 'get_id' ) ) {
	$subscription_id = (int) $cloud->get_id();
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Decision Cloud overlap', 'reactwoo-api-manager' ); ?></h1>
	<p><?php esc_html_e( 'State 6 is a defect: Cloud is active and a covered individual subscription is still renewing. Correction stops the overlapping renewal and keeps history. It does not issue a refund automatically.', 'reactwoo-api-manager' ); ?></p>
	<?php if ( $notice ) : ?>
		<div class="notice notice-info"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>
	<form method="post">
		<?php wp_nonce_field( 'rwcc_overlap' ); ?>
		<p>
			<label><?php esc_html_e( 'Cloud subscription ID', 'reactwoo-api-manager' ); ?>
				<input type="number" name="rwcc_overlap_id" value="<?php echo esc_attr( (string) $subscription_id ); ?>" min="1" />
			</label>
			<?php submit_button( __( 'Inspect', 'reactwoo-api-manager' ), 'secondary', 'rwcc_overlap_inspect', false ); ?>
		</p>
		<?php if ( $detected ) : ?>
			<p><strong><?php echo esc_html( isset( $detected['state'] ) ? (string) $detected['state'] : '' ); ?></strong></p>
			<?php if ( ! empty( $detected['offenders'] ) ) : ?>
				<ul>
					<?php foreach ( $detected['offenders'] as $offender ) : ?>
						<li><?php echo esc_html( (string) ( $offender['id'] ?? '' ) . ' ' . ( $offender['slug'] ?? '' ) ); ?></li>
					<?php endforeach; ?>
				</ul>
				<p>
					<label>
						<input type="checkbox" name="rwcc_overlap_confirm" value="1" />
						<?php esc_html_e( 'I confirm these covered individual renewals should stop. Do not delete history.', 'reactwoo-api-manager' ); ?>
					</label>
				</p>
				<?php submit_button( __( 'Stop overlapping renewals', 'reactwoo-api-manager' ), 'primary', 'rwcc_overlap_correct' ); ?>
			<?php else : ?>
				<p><?php esc_html_e( 'No overlapping covered individual renewals on this Cloud subscription.', 'reactwoo-api-manager' ); ?></p>
			<?php endif; ?>
		<?php endif; ?>
	</form>
</div>
