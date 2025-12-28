<?php
/**
 * License Sync Class
 * Handles matching licenses from server with WooCommerce subscriptions
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ReactWoo_License_Sync {

    /**
     * Match licenses from server with WooCommerce subscriptions
     *
     * @param array $licenses Licenses from server
     * @return array Results with matched, unmatched, and errors
     */
    public static function match_licenses_to_subscriptions( $licenses ) {
        $results = array(
            'matched' => 0,
            'unmatched' => 0,
            'errors' => array(),
            'matched_details' => array(),
            'unmatched_details' => array(),
        );

        if ( empty( $licenses ) || ! is_array( $licenses ) ) {
            return $results;
        }

        // Get all subscriptions
        $subscriptions = wcs_get_subscriptions( array(
            'limit' => -1,
            'status' => 'any',
        ) );

        // Create lookup arrays for faster matching
        $subscriptions_by_license_key = array();
        $subscriptions_by_domain = array();
        $subscriptions_by_email = array();

        foreach ( $subscriptions as $subscription ) {
            // Index by existing license key
            $license_key = $subscription->get_meta( '_reactwoo_license_key' );
            if ( $license_key ) {
                $subscriptions_by_license_key[ $license_key ] = $subscription;
            }

            // Index by domain
            $order = $subscription->get_parent();
            if ( $order ) {
                $domain = $order->get_meta( '_reactwoo_domain' );
                if ( $domain ) {
                    if ( ! isset( $subscriptions_by_domain[ $domain ] ) ) {
                        $subscriptions_by_domain[ $domain ] = array();
                    }
                    $subscriptions_by_domain[ $domain ][] = $subscription;
                }

                // Index by customer email
                $email = $order->get_billing_email();
                if ( $email ) {
                    if ( ! isset( $subscriptions_by_email[ $email ] ) ) {
                        $subscriptions_by_email[ $email ] = array();
                    }
                    $subscriptions_by_email[ $email ][] = $subscription;
                }
            }
        }

        // Match licenses to subscriptions
        foreach ( $licenses as $license ) {
            $license_key = isset( $license['license_key'] ) ? $license['license_key'] : '';
            $license_domain = isset( $license['domain'] ) ? $license['domain'] : '';
            $license_id = isset( $license['id'] ) ? $license['id'] : null;
            $package_id = isset( $license['package_id'] ) ? $license['package_id'] : null;

            $matched = false;
            $match_method = '';
            $subscription = null;

            // Method 1: Match by license key (most reliable)
            if ( $license_key && isset( $subscriptions_by_license_key[ $license_key ] ) ) {
                $subscription = $subscriptions_by_license_key[ $license_key ];
                $match_method = 'license_key';
                $matched = true;
            }
            // Method 2: Match by domain (if license key doesn't exist on subscription)
            elseif ( $license_domain && isset( $subscriptions_by_domain[ $license_domain ] ) ) {
                // Find subscription without a license key for this domain
                foreach ( $subscriptions_by_domain[ $license_domain ] as $sub ) {
                    if ( ! $sub->get_meta( '_reactwoo_license_key' ) ) {
                        $subscription = $sub;
                        $match_method = 'domain';
                        $matched = true;
                        break;
                    }
                }
            }
            // Method 3: Try to match by customer email domain (less reliable, but helpful)
            elseif ( $license_domain ) {
                // Extract domain from license domain
                $license_domain_parts = explode( '.', $license_domain );
                $license_base_domain = count( $license_domain_parts ) >= 2 
                    ? $license_domain_parts[ count( $license_domain_parts ) - 2 ] . '.' . $license_domain_parts[ count( $license_domain_parts ) - 1 ]
                    : $license_domain;

                foreach ( $subscriptions_by_email as $email => $subs ) {
                    $email_parts = explode( '@', $email );
                    if ( isset( $email_parts[1] ) && $email_parts[1] === $license_domain ) {
                        // Found matching domain in email, get first subscription without license
                        foreach ( $subs as $sub ) {
                            if ( ! $sub->get_meta( '_reactwoo_license_key' ) ) {
                                $subscription = $sub;
                                $match_method = 'email_domain';
                                $matched = true;
                                break 2;
                            }
                        }
                    }
                }
            }

            if ( $matched && $subscription ) {
                // Update subscription with license information
                try {
                    $subscription->update_meta_data( '_reactwoo_license_key', $license_key );
                    
                    if ( $license_id ) {
                        $subscription->update_meta_data( '_reactwoo_license_id', $license_id );
                    }
                    
                    if ( $license_domain ) {
                        $subscription->update_meta_data( '_reactwoo_license_domain', $license_domain );
                    }
                    
                    if ( $package_id && ! $subscription->get_meta( '_reactwoo_license_package_id' ) ) {
                        $subscription->update_meta_data( '_reactwoo_license_package_id', $package_id );
                    }
                    
                    $subscription->save();

                    $results['matched']++;
                    $results['matched_details'][] = array(
                        'license_key' => $license_key,
                        'license_id' => $license_id,
                        'domain' => $license_domain,
                        'subscription_id' => $subscription->get_id(),
                        'match_method' => $match_method,
                    );
                } catch ( Exception $e ) {
                    $results['errors'][] = sprintf(
                        __( 'Error updating subscription #%d for license %s: %s', 'reactwoo-api-manager' ),
                        $subscription->get_id(),
                        $license_key,
                        $e->getMessage()
                    );
                }
            } else {
                // No match found
                $results['unmatched']++;
                $results['unmatched_details'][] = array(
                    'license_key' => $license_key,
                    'license_id' => $license_id,
                    'domain' => $license_domain,
                    'package_id' => $package_id,
                );
            }
        }

        return $results;
    }

    /**
     * Get summary of match results for display
     *
     * @param array $results Results from match_licenses_to_subscriptions
     * @return string HTML summary
     */
    public static function format_match_results( $results ) {
        $output = '';

        if ( $results['matched'] > 0 ) {
            $output .= '<div class="notice notice-success"><p>';
            $output .= sprintf(
                esc_html( _n( 
                    '%d license successfully matched and linked to a subscription.',
                    '%d licenses successfully matched and linked to subscriptions.',
                    $results['matched'],
                    'reactwoo-api-manager'
                ) ),
                $results['matched']
            );
            $output .= '</p></div>';
        }

        if ( $results['unmatched'] > 0 ) {
            $output .= '<div class="notice notice-warning"><p>';
            $output .= sprintf(
                esc_html( _n(
                    '%d license could not be matched to a subscription.',
                    '%d licenses could not be matched to subscriptions.',
                    $results['unmatched'],
                    'reactwoo-api-manager'
                ) ),
                $results['unmatched']
            );
            $output .= '</p>';
            
            // Show details of unmatched licenses (first 10)
            if ( ! empty( $results['unmatched_details'] ) ) {
                $output .= '<details style="margin-top: 10px;"><summary style="cursor: pointer; font-weight: 600;">' . esc_html__( 'View unmatched licenses', 'reactwoo-api-manager' ) . '</summary>';
                $output .= '<ul style="margin-left: 20px; margin-top: 10px;">';
                $show_count = min( 10, count( $results['unmatched_details'] ) );
                for ( $i = 0; $i < $show_count; $i++ ) {
                    $detail = $results['unmatched_details'][ $i ];
                    $output .= '<li>';
                    $output .= '<strong>' . esc_html__( 'License Key', 'reactwoo-api-manager' ) . ':</strong> ' . esc_html( $detail['license_key'] );
                    if ( $detail['domain'] ) {
                        $output .= ' | <strong>' . esc_html__( 'Domain', 'reactwoo-api-manager' ) . ':</strong> ' . esc_html( $detail['domain'] );
                    }
                    $output .= '</li>';
                }
                if ( count( $results['unmatched_details'] ) > 10 ) {
                    $output .= '<li><em>' . sprintf( esc_html__( '... and %d more', 'reactwoo-api-manager' ), count( $results['unmatched_details'] ) - 10 ) . '</em></li>';
                }
                $output .= '</ul></details>';
            }
            $output .= '</div>';
        }

        if ( ! empty( $results['errors'] ) ) {
            $output .= '<div class="notice notice-error"><p><strong>' . esc_html__( 'Errors:', 'reactwoo-api-manager' ) . '</strong></p><ul>';
            foreach ( $results['errors'] as $error ) {
                $output .= '<li>' . esc_html( $error ) . '</li>';
            }
            $output .= '</ul></div>';
        }

        return $output;
    }
}

