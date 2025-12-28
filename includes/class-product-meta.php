<?php
/**
 * Product Meta Fields Handler
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ReactWoo_Product_Meta {

    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'woocommerce_product_options_general_product_data', array( $this, 'add_license_type_field' ) );
        add_action( 'woocommerce_process_product_meta', array( $this, 'save_license_type_field' ), 10, 1 );
        add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_license_tab' ) );
        add_action( 'woocommerce_product_data_panels', array( $this, 'add_license_tab_content' ) );
    }

    /**
     * Add license type field to subscription products
     */
    public function add_license_type_field() {
        global $post;

        $product = wc_get_product( $post->ID );
        
        // Only show for subscription products
        if ( ! $product || ! $product->is_type( 'subscription' ) ) {
            return;
        }

        $api = new ReactWoo_License_Server_API();
        $packages = $api->get_packages();

        if ( is_wp_error( $packages ) ) {
            echo '<div class="options_group">';
            echo '<p class="form-field"><strong>' . __( 'License Type', 'reactwoo-api-manager' ) . ':</strong></p>';
            echo '<p class="form-field">' . sprintf( 
                __( 'Error loading license types: %s', 'reactwoo-api-manager' ), 
                $packages->get_error_message() 
            ) . '</p>';
            echo '</div>';
            return;
        }

        $selected_package_id = get_post_meta( $post->ID, '_reactwoo_license_package_id', true );

        echo '<div class="options_group">';
        woocommerce_wp_select( array(
            'id' => '_reactwoo_license_package_id',
            'label' => __( 'License Package Type', 'reactwoo-api-manager' ),
            'description' => __( 'Select the license package type from the license server that will be associated with this subscription product.', 'reactwoo-api-manager' ),
            'options' => $this->format_packages_for_select( $packages, $selected_package_id ),
            'value' => $selected_package_id,
        ) );
        echo '</div>';
    }

    /**
     * Format packages array for select field
     *
     * @param array $packages Packages array
     * @param mixed $selected Selected value
     * @return array
     */
    private function format_packages_for_select( $packages, $selected = '' ) {
        $options = array( '' => __( '-- Select License Type --', 'reactwoo-api-manager' ) );

        foreach ( $packages as $package ) {
            $package_id = isset( $package['id'] ) ? $package['id'] : '';
            $package_name = isset( $package['name'] ) ? $package['name'] : '';
            $package_price = isset( $package['price'] ) ? floatval( $package['price'] ) : 0;
            
            $label = $package_name;
            if ( $package_price > 0 ) {
                $label .= ' (' . wc_price( $package_price ) . ')';
            }

            $options[ $package_id ] = $label;
        }

        return $options;
    }

    /**
     * Save license type field
     *
     * @param int $post_id Post ID
     */
    public function save_license_type_field( $post_id ) {
        if ( isset( $_POST['_reactwoo_license_package_id'] ) ) {
            update_post_meta( $post_id, '_reactwoo_license_package_id', intval( $_POST['_reactwoo_license_package_id'] ) );
        } else {
            delete_post_meta( $post_id, '_reactwoo_license_package_id' );
        }
    }

    /**
     * Add license tab to product data tabs
     *
     * @param array $tabs Existing tabs
     * @return array
     */
    public function add_license_tab( $tabs ) {
        $tabs['reactwoo_license'] = array(
            'label' => __( 'License Settings', 'reactwoo-api-manager' ),
            'target' => 'reactwoo_license_product_data',
            'class' => array( 'show_if_subscription' ),
            'priority' => 25,
        );
        return $tabs;
    }

    /**
     * Add license tab content
     */
    public function add_license_tab_content() {
        global $post;

        $product = wc_get_product( $post->ID );
        
        // Only show for subscription products
        if ( ! $product || ! $product->is_type( 'subscription' ) ) {
            return;
        }

        $api = new ReactWoo_License_Server_API();
        $packages = $api->get_packages();
        $selected_package_id = get_post_meta( $post->ID, '_reactwoo_license_package_id', true );

        ?>
        <div id="reactwoo_license_product_data" class="panel woocommerce_options_panel">
            <div class="options_group">
                <?php
                if ( is_wp_error( $packages ) ) {
                    echo '<p class="form-field">';
                    echo '<strong>' . esc_html__( 'Error', 'reactwoo-api-manager' ) . ':</strong> ';
                    echo esc_html( $packages->get_error_message() );
                    echo '</p>';
                } else {
                    woocommerce_wp_select( array(
                        'id' => '_reactwoo_license_package_id',
                        'label' => __( 'License Package Type', 'reactwoo-api-manager' ),
                        'description' => __( 'Select the license package type from the license server that will be associated with this subscription product. When a subscription is created, a license key will be automatically generated for the selected package type.', 'reactwoo-api-manager' ),
                        'options' => $this->format_packages_for_select( $packages, $selected_package_id ),
                        'value' => $selected_package_id,
                    ) );

                    if ( $selected_package_id ) {
                        $selected_package = $this->find_package_by_id( $packages, $selected_package_id );
                        if ( $selected_package ) {
                            echo '<div class="form-field">';
                            echo '<p><strong>' . esc_html__( 'Selected Package Details', 'reactwoo-api-manager' ) . ':</strong></p>';
                            echo '<ul style="margin-left: 20px;">';
                            if ( isset( $selected_package['description'] ) && $selected_package['description'] ) {
                                echo '<li><strong>' . esc_html__( 'Description', 'reactwoo-api-manager' ) . ':</strong> ' . esc_html( $selected_package['description'] ) . '</li>';
                            }
                            if ( isset( $selected_package['slug'] ) && $selected_package['slug'] ) {
                                echo '<li><strong>' . esc_html__( 'Slug', 'reactwoo-api-manager' ) . ':</strong> ' . esc_html( $selected_package['slug'] ) . '</li>';
                            }
                            echo '</ul>';
                            echo '</div>';
                        }
                    }
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Find package by ID
     *
     * @param array $packages Packages array
     * @param int   $package_id Package ID
     * @return array|null
     */
    private function find_package_by_id( $packages, $package_id ) {
        foreach ( $packages as $package ) {
            if ( isset( $package['id'] ) && intval( $package['id'] ) === intval( $package_id ) ) {
                return $package;
            }
        }
        return null;
    }
}

