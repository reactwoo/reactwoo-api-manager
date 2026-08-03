<?php
/**
 * My Account — Products & licences
 *
 * Theme override: yourtheme/woocommerce/myaccount/license.php
 *
 * @package ReactWoo_API_Manager
 * @var array $reactwoo_records
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$records = is_array( $reactwoo_records ) ? $reactwoo_records : array();

$active_count = 0;
$domain_count = 0;
$download_count = 0;
foreach ( $records as $record ) {
	if ( isset( $record['status'] ) && 'active' === $record['status'] ) {
		++$active_count;
	}
	if ( ! empty( $record['registered_domain'] ) ) {
		++$domain_count;
	}
	if ( ! empty( $record['files'] ) && is_array( $record['files'] ) ) {
		$download_count += count( $record['files'] );
	}
}

$browse_url  = ! empty( $records[0]['browse_url'] ) ? $records[0]['browse_url'] : ( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' ) );
$support_url = ! empty( $records[0]['support_url'] ) ? $records[0]['support_url'] : 'https://reactwoo.com/support/';
$orders_url  = wc_get_account_endpoint_url( 'orders' );

$status_labels = array(
	'active'   => __( 'Active', 'reactwoo-api-manager' ),
	'expiring' => __( 'Expiring', 'reactwoo-api-manager' ),
	'inactive' => __( 'Inactive', 'reactwoo-api-manager' ),
	'expired'  => __( 'Expired', 'reactwoo-api-manager' ),
	'pending'  => __( 'Pending', 'reactwoo-api-manager' ),
);
?>
<div class="rw-account rw-account--licenses">
	<header class="rw-account__intro">
		<p class="rw-account__eyebrow"><?php esc_html_e( 'My Account', 'reactwoo-api-manager' ); ?></p>
		<div class="rw-account__intro-row">
			<div>
				<h1 class="rw-account__title"><?php esc_html_e( 'Your products and licences', 'reactwoo-api-manager' ); ?></h1>
				<p class="rw-account__subtitle"><?php esc_html_e( 'Download your plugins, manage your registered website and keep track of renewals in one place.', 'reactwoo-api-manager' ); ?></p>
			</div>
			<a class="rw-account__btn rw-account__btn--secondary" href="<?php echo esc_url( $browse_url ); ?>"><?php esc_html_e( 'Browse products', 'reactwoo-api-manager' ); ?></a>
		</div>
	</header>

	<?php if ( empty( $records ) ) : ?>
		<div class="rw-account__empty">
			<h2><?php esc_html_e( 'No products yet', 'reactwoo-api-manager' ); ?></h2>
			<p><?php esc_html_e( 'When you purchase a ReactWoo subscription, your licence and downloads will appear here.', 'reactwoo-api-manager' ); ?></p>
			<a class="rw-account__btn rw-account__btn--primary" href="<?php echo esc_url( $browse_url ); ?>"><?php esc_html_e( 'Browse products', 'reactwoo-api-manager' ); ?></a>
		</div>
	<?php else : ?>

		<section class="rw-account__metrics" aria-label="<?php esc_attr_e( 'Licence summary', 'reactwoo-api-manager' ); ?>">
			<div class="rw-account__metric">
				<p class="rw-account__metric-label"><?php esc_html_e( 'Active licences', 'reactwoo-api-manager' ); ?></p>
				<p class="rw-account__metric-value"><?php echo esc_html( (string) $active_count ); ?></p>
				<p class="rw-account__metric-help"><?php esc_html_e( 'Subscriptions currently covered by updates.', 'reactwoo-api-manager' ); ?></p>
			</div>
			<div class="rw-account__metric">
				<p class="rw-account__metric-label"><?php esc_html_e( 'Registered websites', 'reactwoo-api-manager' ); ?></p>
				<p class="rw-account__metric-value"><?php echo esc_html( (string) $domain_count ); ?></p>
				<p class="rw-account__metric-help"><?php esc_html_e( 'Single-domain licences linked to a website.', 'reactwoo-api-manager' ); ?></p>
			</div>
			<div class="rw-account__metric">
				<p class="rw-account__metric-label"><?php esc_html_e( 'Downloads ready', 'reactwoo-api-manager' ); ?></p>
				<p class="rw-account__metric-value"><?php echo esc_html( (string) $download_count ); ?></p>
				<p class="rw-account__metric-help"><?php esc_html_e( 'Protected plugin files available to download.', 'reactwoo-api-manager' ); ?></p>
			</div>
		</section>

		<section class="rw-account__products">
			<div class="rw-account__section-head">
				<h2><?php esc_html_e( 'Your products', 'reactwoo-api-manager' ); ?></h2>
				<p><?php esc_html_e( 'Latest downloads and licence status.', 'reactwoo-api-manager' ); ?></p>
			</div>

			<?php foreach ( $records as $record ) :
				$status       = isset( $record['status'] ) ? $record['status'] : 'pending';
				$status_label = isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : ucfirst( $status );
				$files        = ! empty( $record['files'] ) && is_array( $record['files'] ) ? $record['files'] : array();
				$primary_file = ! empty( $files[0] ) ? $files[0] : null;
				$version      = ! empty( $record['version'] ) ? $record['version'] : '';
				$download_label = $primary_file
					? ( $version ? sprintf( __( 'Download v%s', 'reactwoo-api-manager' ), $version ) : __( 'Download plugin', 'reactwoo-api-manager' ) )
					: '';
				?>
				<article class="rw-account__card" data-subscription-id="<?php echo esc_attr( (string) $record['subscription_id'] ); ?>">
					<div class="rw-account__card-top">
						<div class="rw-account__product-mark" aria-hidden="true">
							<span><?php echo esc_html( strtoupper( substr( (string) $record['product_name'], 0, 3 ) ) ); ?></span>
						</div>
						<div class="rw-account__card-copy">
							<div class="rw-account__card-title-row">
								<h3><?php echo esc_html( $record['product_name'] ); ?></h3>
								<span class="rw-account__pill rw-account__pill--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $status_label ); ?></span>
							</div>
							<?php if ( ! empty( $record['product_description'] ) ) : ?>
								<p class="rw-account__card-desc"><?php echo esc_html( $record['product_description'] ); ?></p>
							<?php elseif ( ! empty( $record['plan_label'] ) ) : ?>
								<p class="rw-account__card-desc"><?php echo esc_html( $record['plan_label'] ); ?></p>
							<?php endif; ?>
						</div>
						<div class="rw-account__card-actions">
							<?php if ( $primary_file ) : ?>
								<a class="rw-account__btn rw-account__btn--primary" href="<?php echo esc_url( $primary_file['url'] ); ?>"><?php echo esc_html( $download_label ); ?></a>
							<?php else : ?>
								<span class="rw-account__btn rw-account__btn--disabled" title="<?php esc_attr_e( 'No download permission yet', 'reactwoo-api-manager' ); ?>"><?php esc_html_e( 'Download unavailable', 'reactwoo-api-manager' ); ?></span>
							<?php endif; ?>
						</div>
					</div>

					<div class="rw-account__panels">
						<div class="rw-account__panel">
							<div class="rw-account__panel-head">
								<span><?php esc_html_e( 'Licence key', 'reactwoo-api-manager' ); ?></span>
								<?php if ( ! empty( $record['can_reveal_key'] ) ) : ?>
									<button type="button" class="rw-account__copy" data-rw-copy-key="<?php echo esc_attr( (string) $record['subscription_id'] ); ?>">
										<?php esc_html_e( 'Copy key', 'reactwoo-api-manager' ); ?>
									</button>
								<?php elseif ( 'pending' === $status ) : ?>
									<span class="rw-account__muted"><?php esc_html_e( 'Provisioning…', 'reactwoo-api-manager' ); ?></span>
								<?php endif; ?>
							</div>
							<p class="rw-account__masked-key">
								<?php
								if ( ! empty( $record['masked_key'] ) ) {
									echo esc_html( $record['masked_key'] );
								} else {
									esc_html_e( 'Key not available yet', 'reactwoo-api-manager' );
								}
								?>
							</p>
							<?php if ( ! empty( $record['renewal_date'] ) ) : ?>
								<span class="rw-account__pill rw-account__pill--renew"><?php echo esc_html( sprintf( __( 'Renews %s', 'reactwoo-api-manager' ), $record['renewal_date'] ) ); ?></span>
							<?php endif; ?>
						</div>

						<div class="rw-account__panel">
							<div class="rw-account__panel-head">
								<span><?php esc_html_e( 'Registered website', 'reactwoo-api-manager' ); ?></span>
							</div>
							<?php if ( ! empty( $record['registered_domain'] ) ) : ?>
								<p class="rw-account__domain"><?php echo esc_html( $record['registered_domain'] ); ?></p>
								<p class="rw-account__muted"><?php esc_html_e( 'Single-domain subscription licence. Contact support to change the registered website.', 'reactwoo-api-manager' ); ?></p>
							<?php else : ?>
								<p class="rw-account__domain rw-account__muted"><?php esc_html_e( 'No website registered yet', 'reactwoo-api-manager' ); ?></p>
							<?php endif; ?>
						</div>
					</div>

					<?php if ( count( $files ) > 1 ) : ?>
						<ul class="rw-account__files">
							<?php foreach ( $files as $file ) : ?>
								<li>
									<a href="<?php echo esc_url( $file['url'] ); ?>"><?php echo esc_html( $file['name'] ); ?></a>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>

					<div class="rw-account__meta-row">
						<?php if ( ! empty( $record['documentation_url'] ) ) : ?>
							<a href="<?php echo esc_url( $record['documentation_url'] ); ?>"><?php esc_html_e( 'Read setup guide →', 'reactwoo-api-manager' ); ?></a>
						<?php endif; ?>
						<?php if ( ! empty( $record['order_url'] ) ) : ?>
							<a href="<?php echo esc_url( $record['order_url'] ); ?>"><?php esc_html_e( 'View order →', 'reactwoo-api-manager' ); ?></a>
						<?php endif; ?>
					</div>
				</article>
			<?php endforeach; ?>
		</section>

		<section class="rw-account__footer-grid">
			<div class="rw-account__help-card">
				<p class="rw-account__eyebrow"><?php esc_html_e( 'Need help?', 'reactwoo-api-manager' ); ?></p>
				<h2><?php esc_html_e( 'Product support', 'reactwoo-api-manager' ); ?></h2>
				<p><?php esc_html_e( 'Questions about activation, updates or installation?', 'reactwoo-api-manager' ); ?></p>
				<a class="rw-account__btn rw-account__btn--secondary" href="<?php echo esc_url( $support_url ); ?>"><?php esc_html_e( 'Contact support', 'reactwoo-api-manager' ); ?></a>
			</div>
			<div class="rw-account__orders-card">
				<div class="rw-account__section-head rw-account__section-head--row">
					<h2><?php esc_html_e( 'Orders', 'reactwoo-api-manager' ); ?></h2>
					<a href="<?php echo esc_url( $orders_url ); ?>"><?php esc_html_e( 'View all →', 'reactwoo-api-manager' ); ?></a>
				</div>
				<p class="rw-account__muted"><?php esc_html_e( 'Full order history remains available under Orders.', 'reactwoo-api-manager' ); ?></p>
			</div>
		</section>

	<?php endif; ?>
</div>
