<?php
/**
 * Compact licence list for shortcodes.
 *
 * @package ReactWoo_API_Manager
 * @var array $reactwoo_records
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$records = is_array( $reactwoo_records ) ? $reactwoo_records : array();
?>
<div class="rw-account rw-account--compact">
	<?php if ( empty( $records ) ) : ?>
		<p><?php esc_html_e( 'No licences found. Complete a subscription to generate one.', 'reactwoo-api-manager' ); ?></p>
	<?php else : ?>
		<?php foreach ( $records as $record ) : ?>
			<div class="rw-account__compact-row" data-subscription-id="<?php echo esc_attr( (string) $record['subscription_id'] ); ?>">
				<strong><?php echo esc_html( $record['product_name'] ); ?></strong>
				<span class="rw-account__pill rw-account__pill--<?php echo esc_attr( $record['status'] ); ?>"><?php echo esc_html( $record['status'] ); ?></span>
				<?php
				$key_rows = ( ! empty( $record['license_keys'] ) && is_array( $record['license_keys'] ) && count( $record['license_keys'] ) > 1 )
					? $record['license_keys']
					: array();
				?>
				<?php if ( $key_rows ) : ?>
					<?php foreach ( $key_rows as $key_row ) : ?>
						<span class="rw-account__key-row">
							<strong><?php echo esc_html( $key_row['label'] ); ?></strong>
							<code class="rw-account__masked-key"><?php echo esc_html( $key_row['masked_key'] ); ?></code>
							<?php if ( ! empty( $key_row['key_available'] ) ) : ?>
								<button type="button" class="rw-account__copy" data-rw-copy-key="<?php echo esc_attr( (string) $record['subscription_id'] ); ?>" data-rw-copy-package="<?php echo esc_attr( (string) $key_row['slug'] ); ?>">
									<?php esc_html_e( 'Copy key', 'reactwoo-api-manager' ); ?>
								</button>
							<?php endif; ?>
						</span>
					<?php endforeach; ?>
				<?php elseif ( ! empty( $record['masked_key'] ) ) : ?>
					<code class="rw-account__masked-key"><?php echo esc_html( $record['masked_key'] ); ?></code>
					<?php if ( ! empty( $record['can_reveal_key'] ) ) : ?>
						<button type="button" class="rw-account__copy" data-rw-copy-key="<?php echo esc_attr( (string) $record['subscription_id'] ); ?>">
							<?php esc_html_e( 'Copy key', 'reactwoo-api-manager' ); ?>
						</button>
					<?php endif; ?>
				<?php endif; ?>
				<?php if ( ! empty( $record['registered_domain'] ) ) : ?>
					<span><?php echo esc_html( $record['registered_domain'] ); ?></span>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	<?php endif; ?>
</div>
