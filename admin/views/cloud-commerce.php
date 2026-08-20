<?php
/**
 * Settings UI — Decision Cloud commerce bridge.
 * Secrets are never printed back into the form value attributes.
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$values = isset( $values ) && is_array( $values ) ? $values : array();
$notice = isset( $notice ) ? $notice : '';
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Decision Cloud commerce', 'reactwoo-api-manager' ); ?></h1>
	<p><?php esc_html_e( 'Maps WooCommerce subscriptions on ReactWoo.com to Decision Cloud plans. Checkout, invoices and payment methods stay on this store. Decision Cloud never receives WooCommerce REST credentials.', 'reactwoo-api-manager' ); ?></p>

	<?php if ( $notice ) : ?>
		<div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>

	<form method="post">
		<?php wp_nonce_field( 'rwcc_settings' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="cloud_origin"><?php esc_html_e( 'Decision Cloud origin', 'reactwoo-api-manager' ); ?></label></th>
				<td>
					<input type="url" class="regular-text" id="cloud_origin" name="cloud_origin" value="<?php echo esc_attr( $values['cloud_origin'] ?? '' ); ?>" />
					<p class="description"><?php esc_html_e( 'Example: https://decision.reactwoo.com — used for activation URLs and the default webhook target.', 'reactwoo-api-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="webhook_url"><?php esc_html_e( 'Webhook URL', 'reactwoo-api-manager' ); ?></label></th>
				<td>
					<input type="url" class="regular-text" id="webhook_url" name="webhook_url" value="<?php echo esc_attr( $values['webhook_url'] ?? '' ); ?>" placeholder="https://decision.reactwoo.com/api/v1/billing/webhooks/woocommerce" />
					<p class="description"><?php esc_html_e( 'Leave blank to use {origin}/api/v1/billing/webhooks/woocommerce.', 'reactwoo-api-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="activation_path"><?php esc_html_e( 'Activation path', 'reactwoo-api-manager' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="activation_path" name="activation_path" value="<?php echo esc_attr( $values['activation_path'] ?? '/activate' ); ?>" />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="claim_ttl_sec"><?php esc_html_e( 'Activation claim TTL (seconds)', 'reactwoo-api-manager' ); ?></label></th>
				<td>
					<input type="number" min="60" id="claim_ttl_sec" name="claim_ttl_sec" value="<?php echo esc_attr( (string) ( $values['claim_ttl_sec'] ?? 1800 ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Default 1800 (30 minutes), matching the activation screen copy.', 'reactwoo-api-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="replay_window_sec"><?php esc_html_e( 'Replay window (seconds)', 'reactwoo-api-manager' ); ?></label></th>
				<td>
					<input type="number" min="30" id="replay_window_sec" name="replay_window_sec" value="<?php echo esc_attr( (string) ( $values['replay_window_sec'] ?? 300 ) ); ?>" />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="return_origins"><?php esc_html_e( 'Allowed return URL origins', 'reactwoo-api-manager' ); ?></label></th>
				<td>
					<textarea class="large-text" rows="4" id="return_origins" name="return_origins"><?php echo esc_textarea( $values['return_origins'] ?? '' ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One origin per line (https://decision.reactwoo.com). Arbitrary return URLs are rejected.', 'reactwoo-api-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Decision Cloud catalogue', 'reactwoo-api-manager' ); ?></th>
				<td>
					<p>
						<label><?php esc_html_e( 'Parent product ID', 'reactwoo-api-manager' ); ?>
							<input type="text" name="product_decision_cloud" value="<?php echo esc_attr( $values['product_decision_cloud'] ?? '' ); ?>" />
						</label>
					</p>
					<p>
						<label><?php esc_html_e( 'Starter variation IDs', 'reactwoo-api-manager' ); ?>
							<input type="text" class="regular-text" name="product_starter" value="<?php echo esc_attr( $values['product_starter'] ?? '' ); ?>" placeholder="3172,3173" />
						</label>
					</p>
					<p>
						<label><?php esc_html_e( 'Growth variation IDs', 'reactwoo-api-manager' ); ?>
							<input type="text" class="regular-text" name="product_growth" value="<?php echo esc_attr( $values['product_growth'] ?? '' ); ?>" placeholder="3174,3175" />
						</label>
					</p>
					<p>
						<label><?php esc_html_e( 'Scale variation IDs', 'reactwoo-api-manager' ); ?>
							<input type="text" class="regular-text" name="product_scale" value="<?php echo esc_attr( $values['product_scale'] ?? '' ); ?>" placeholder="3176,3177" />
						</label>
					</p>
					<p class="description"><?php esc_html_e( 'One variable subscription: ReactWoo Decision Cloud. Bind variation IDs, not the parent, as a comma-separated list (monthly then annual). Prefer _rw_cloud_plan and _rw_cloud_billing_cycle on each variation. Do not attach satellite plugin ZIPs to variations.', 'reactwoo-api-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Individual plugin product IDs', 'reactwoo-api-manager' ); ?></th>
				<td>
					<p>
						<label><?php esc_html_e( 'Geo Core Pro', 'reactwoo-api-manager' ); ?>
							<input type="text" name="product_geocore_pro" value="<?php echo esc_attr( $values['product_geocore_pro'] ?? '' ); ?>" />
						</label>
						<label><?php esc_html_e( 'Geo Commerce', 'reactwoo-api-manager' ); ?>
							<input type="text" name="product_geo_commerce" value="<?php echo esc_attr( $values['product_geo_commerce'] ?? '' ); ?>" />
						</label>
						<label><?php esc_html_e( 'Geo Optimise', 'reactwoo-api-manager' ); ?>
							<input type="text" name="product_geo_optimise" value="<?php echo esc_attr( $values['product_geo_optimise'] ?? '' ); ?>" />
						</label>
					</p>
					<p class="description"><?php esc_html_e( 'Used to detect covered individual subscriptions during Cloud upgrade. Leave blank until the live WooCommerce IDs are bound. Do not enable production Cloud commerce until these are set for every sold individual SKU.', 'reactwoo-api-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="webhook_secret"><?php esc_html_e( 'Webhook secret', 'reactwoo-api-manager' ); ?></label></th>
				<td>
					<input type="password" class="regular-text" id="webhook_secret" name="webhook_secret" value="" autocomplete="new-password" placeholder="<?php echo empty( $values['webhook_secret'] ) ? '' : esc_attr__( '•••••••• (unchanged)', 'reactwoo-api-manager' ); ?>" />
					<p class="description"><?php esc_html_e( 'Must match WOOCOMMERCE_WEBHOOK_SECRET on Decision Cloud. Prefer RWCC_WEBHOOK_SECRET in wp-config.php.', 'reactwoo-api-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="handoff_secret"><?php esc_html_e( 'Handoff secret', 'reactwoo-api-manager' ); ?></label></th>
				<td>
					<input type="password" class="regular-text" id="handoff_secret" name="handoff_secret" value="" autocomplete="new-password" placeholder="<?php echo empty( $values['handoff_secret'] ) ? '' : esc_attr__( '•••••••• (unchanged)', 'reactwoo-api-manager' ); ?>" />
					<p class="description"><?php esc_html_e( 'Must match REACTWOO_HANDOFF_SECRET on Decision Cloud. Prefer RWCC_HANDOFF_SECRET in wp-config.php.', 'reactwoo-api-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="reconcile_token"><?php esc_html_e( 'Reconciliation token', 'reactwoo-api-manager' ); ?></label></th>
				<td>
					<input type="password" class="regular-text" id="reconcile_token" name="reconcile_token" value="" autocomplete="new-password" placeholder="<?php echo empty( $values['reconcile_token'] ) ? '' : esc_attr__( '•••••••• (unchanged)', 'reactwoo-api-manager' ); ?>" />
					<p class="description"><?php esc_html_e( 'Bearer token for GET /wp-json/reactwoo-cloud/v1/reconcile. Prefer RWCC_RECONCILE_TOKEN in wp-config.php. Never a WooCommerce consumer key.', 'reactwoo-api-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Local HTTP returns', 'reactwoo-api-manager' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="allow_http_local" value="1" <?php checked( ! empty( $values['allow_http_local'] ) ); ?> />
						<?php esc_html_e( 'Allow http:// return URLs (Local / staging only).', 'reactwoo-api-manager' ); ?>
					</label>
				</td>
			</tr>
		</table>
		<?php submit_button( __( 'Save Cloud commerce settings', 'reactwoo-api-manager' ), 'primary', 'rwcc_save' ); ?>
	</form>
</div>
