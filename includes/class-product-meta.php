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
     * Packages fetched once per request for the product editor.
     *
     * @var array|WP_Error|null
     */
    private $license_packages = null;

    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'woocommerce_product_options_general_product_data', array( $this, 'add_license_type_field' ) );
        add_action( 'woocommerce_process_product_meta', array( $this, 'save_license_type_field' ), 10, 1 );
        add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_license_tab' ) );
        add_action( 'woocommerce_product_data_panels', array( $this, 'add_license_tab_content' ) );
        
        // Handle variable subscription variations
        add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation_license_type_field' ), 10, 2 );
        add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'render_variation_plan_field' ), 10, 3 );
    }

    /**
     * Add license type field to subscription products
     */
    public function add_license_type_field() {
        global $post;

        $product = wc_get_product( $post->ID );
        
        // Only show for subscription products (including variable subscriptions)
        if ( ! $product || ! ( $product->is_type( 'subscription' ) || $product->is_type( 'variable-subscription' ) || $product->is_type( 'subscription_variation' ) ) ) {
            return;
        }

        $packages = $this->load_license_packages();

        if ( is_wp_error( $packages ) ) {
            echo '<div class="options_group">';
            echo '<p class="form-field"><strong>' . __( 'License Type', 'reactwoo-api-manager' ) . ':</strong></p>';
            echo '<p class="form-field">' . sprintf( 
                __( 'Error loading license types: %s', 'reactwoo-api-manager' ), 
                $packages->get_error_message() 
            ) . '</p>';
            $this->render_maintenance_plan_field( $post->ID );
            echo '</div>';
            return;
        }

        echo '<div class="options_group">';
        echo '<p class="form-field">' . esc_html__( 'Licence packages for this product are chosen on the License Settings tab. A variation can replace that list.', 'reactwoo-api-manager' ) . '</p>';
        $this->render_maintenance_plan_field( $post->ID );
        $this->render_plugin_slug_field( $post->ID );
        echo '</div>';
    }

    /**
     * Licence packages from the server, cached for this request.
     *
     * @return array|WP_Error
     */
    private function load_license_packages() {
        if ( null === $this->license_packages ) {
            $api = new ReactWoo_License_Server_API();
            $this->license_packages = $api->get_packages();
        }
        return $this->license_packages;
    }

    /**
     * Multi-select of licence packages. The first id is also stored in the
     * legacy single meta key so older readers keep working.
     *
     * @param int         $post_id Product or variation id.
     * @param string      $field_name Field name.
     * @param int|null    $loop Variation loop index.
     */
    private function render_license_packages_field( $post_id, $field_name, $loop = null ) {
        $packages = $this->load_license_packages();
        $selected = class_exists( 'ReactWoo_Subscription_Handler' )
            ? ReactWoo_Subscription_Handler::license_package_ids_for_post( $post_id )
            : array();

        $id      = $field_name;
        $name    = $field_name;
        $present = $field_name . '_present';
        if ( null !== $loop ) {
            $id      = $field_name . '_' . (int) $loop;
            $name    = $field_name . '[' . (int) $loop . ']';
            $present = $field_name . '_present[' . (int) $loop . ']';
        }

        $tag   = null !== $loop ? 'div' : 'p';
        $class = null !== $loop ? 'form-field form-row form-row-full' : 'form-field';

        echo '<' . $tag . ' class="' . esc_attr( $class ) . '">';
        echo '<label for="' . esc_attr( $id ) . '">' . esc_html__( 'License packages', 'reactwoo-api-manager' ) . '</label>';

        if ( is_wp_error( $packages ) ) {
            echo '<span class="description">' . esc_html( sprintf(
                /* translators: %s: error message */
                __( 'Error loading license types: %s', 'reactwoo-api-manager' ),
                $packages->get_error_message()
            ) ) . '</span>';
            echo '</' . $tag . '>';
            return;
        }

        // Present even when nothing is selected, so clearing the list can be saved.
        echo '<input type="hidden" name="' . esc_attr( $present ) . '" value="1" />';
        echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '[]" multiple="multiple" style="height:8em;width:50%;">';
        foreach ( (array) $packages as $package ) {
            $package_id = isset( $package['id'] ) ? absint( $package['id'] ) : 0;
            if ( ! $package_id ) {
                continue;
            }
            $clean_name  = trim( wp_strip_all_tags( isset( $package['name'] ) ? (string) $package['name'] : '' ) );
            $is_selected = in_array( $package_id, $selected, true ) ? ' selected="selected"' : '';
            echo '<option value="' . esc_attr( (string) $package_id ) . '"' . $is_selected . '>' . esc_html( $clean_name ) . '</option>';
        }
        echo '</select>';
        echo '<span class="description">' . esc_html__( 'Select every licence package this subscription should provision. One package keeps a single licence key. Several packages (a bundle) create one key each. On a variation, any selection here replaces the parent product packages.', 'reactwoo-api-manager' ) . '</span>';
        echo '</' . $tag . '>';
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
            
            // Strip HTML tags and clean up whitespace from package name
            $clean_name = wp_strip_all_tags( $package_name );
            $clean_name = trim( $clean_name );
            
            $options[ $package_id ] = $clean_name;
        }

        return $options;
    }

    /**
     * Save license type field
     *
     * @param int $post_id Post ID
     */
    public function save_license_type_field( $post_id ) {
        if ( isset( $_POST['_reactwoo_license_package_ids_present'] ) ) {
            $raw = isset( $_POST['_reactwoo_license_package_ids'] ) ? wp_unslash( $_POST['_reactwoo_license_package_ids'] ) : array();
            $this->store_package_ids( $post_id, $raw );
        } elseif ( isset( $_POST['_reactwoo_license_package_id'] ) ) {
            $package_id = absint( wp_unslash( $_POST['_reactwoo_license_package_id'] ) );
            $this->store_package_ids( $post_id, $package_id ? array( $package_id ) : array() );
        }
        $this->save_plugin_slug_field( $post_id );
        $this->save_maintenance_plan_field( $post_id );
    }

    /**
     * Persist the package list and the legacy single id (the first package).
     *
     * @param int   $post_id Product or variation id.
     * @param mixed $raw Posted ids.
     */
    private function store_package_ids( $post_id, $raw ) {
        $ids = class_exists( 'ReactWoo_Subscription_Handler' )
            ? ReactWoo_Subscription_Handler::sanitize_package_id_list( $raw )
            : array();
        if ( $ids ) {
            update_post_meta( $post_id, '_reactwoo_license_package_ids', $ids );
            update_post_meta( $post_id, '_reactwoo_license_package_id', $ids[0] );
            return;
        }
        delete_post_meta( $post_id, '_reactwoo_license_package_ids' );
        delete_post_meta( $post_id, '_reactwoo_license_package_id' );
    }

    /**
     * Maintenance plan identity. Empty is stored as none.
     *
     * @param int $post_id Product ID.
     */
    private function render_maintenance_plan_field( $post_id ) {
        $selected = (string) get_post_meta( $post_id, '_rw_maintenance_plan_key', true );
        if ( ! in_array( $selected, array( 'essential', 'standard', 'business' ), true ) ) {
            $selected = 'none';
        }

        woocommerce_wp_select(
            array(
                'id'          => '_rw_maintenance_plan_key',
                'label'       => __( 'Maintenance plan', 'reactwoo-api-manager' ),
                'description' => __( 'Identifies this WooCommerce subscription as a ReactWoo Maintenance plan. This is separate from software licensing.', 'reactwoo-api-manager' ),
                'desc_tip'    => true,
                'options'     => array(
                    'none'      => __( 'None', 'reactwoo-api-manager' ),
                    'essential' => __( 'Essential', 'reactwoo-api-manager' ),
                    'standard'  => __( 'Standard', 'reactwoo-api-manager' ),
                    'business'  => __( 'Business', 'reactwoo-api-manager' ),
                ),
                'value'       => $selected,
            )
        );
    }

    /**
     * @param int $post_id Product ID.
     */
    private function save_maintenance_plan_field( $post_id ) {
        if ( ! isset( $_POST['_rw_maintenance_plan_key'] ) ) {
            return;
        }

        $plan = sanitize_key( wp_unslash( $_POST['_rw_maintenance_plan_key'] ) );
        if ( ! in_array( $plan, array( 'essential', 'standard', 'business' ), true ) ) {
            $plan = 'none';
        }

        update_post_meta( $post_id, '_rw_maintenance_plan_key', $plan );
    }

    /**
     * Plugin catalog slug used for My Account R2 downloads.
     *
     * @param int $post_id Product ID.
     */
    private function render_plugin_slug_field( $post_id ) {
        $slug = (string) get_post_meta( $post_id, '_reactwoo_plugin_slug', true );
        woocommerce_wp_text_input(
            array(
                'id'          => '_reactwoo_plugin_slug',
                'label'       => __( 'Plugin download slug', 'reactwoo-api-manager' ),
                'description' => __( 'R2 / updates catalog slug for My Account ZIP downloads (e.g. reactwoo-geo-optimise). Leave blank to fall back to the license package slug.', 'reactwoo-api-manager' ),
                'desc_tip'    => true,
                'value'       => $slug,
                'placeholder' => 'reactwoo-geo-optimise',
            )
        );
    }

    /**
     * @param int $post_id Product ID.
     */
    private function save_plugin_slug_field( $post_id ) {
        if ( ! isset( $_POST['_reactwoo_plugin_slug'] ) ) {
            return;
        }
        $slug = strtolower( sanitize_title( wp_unslash( $_POST['_reactwoo_plugin_slug'] ) ) );
        if ( $slug !== '' && preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ) ) {
            update_post_meta( $post_id, '_reactwoo_plugin_slug', $slug );
        } else {
            delete_post_meta( $post_id, '_reactwoo_plugin_slug' );
        }
    }

    /**
     * Save license type field for variation
     *
     * @param int $variation_id Variation ID
     * @param int $loop Loop index
     */
    public function save_variation_license_type_field( $variation_id, $loop ) {
        // Check if this is a subscription variation
        $product = wc_get_product( $variation_id );
        if ( ! $product || ! $product->is_type( 'subscription_variation' ) ) {
            return;
        }

        // Variation meta is stored with array index in POST data.
        $present = 'variable_reactwoo_license_package_ids_present';
        if ( isset( $_POST[ $present ] ) && is_array( $_POST[ $present ] ) && isset( $_POST[ $present ][ $loop ] ) ) {
            $posted = array();
            $key    = 'variable_reactwoo_license_package_ids';
            if ( isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) && isset( $_POST[ $key ][ $loop ] ) ) {
                $posted = wp_unslash( $_POST[ $key ][ $loop ] );
            }
            $this->store_package_ids( $variation_id, $posted );
        } else {
            $key = 'variable_reactwoo_license_package_id';
            if ( isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) && isset( $_POST[ $key ][ $loop ] ) ) {
                $package_id = absint( wp_unslash( $_POST[ $key ][ $loop ] ) );
                $this->store_package_ids( $variation_id, $package_id ? array( $package_id ) : array() );
            }
        }

        $plan_key = 'variable_rw_maintenance_plan_key';
        if ( isset( $_POST[ $plan_key ] ) && is_array( $_POST[ $plan_key ] ) && isset( $_POST[ $plan_key ][ $loop ] ) ) {
            $plan = sanitize_key( wp_unslash( $_POST[ $plan_key ][ $loop ] ) );
            if ( in_array( $plan, array( 'essential', 'standard', 'business' ), true ) ) {
                update_post_meta( $variation_id, '_rw_maintenance_plan_key', $plan );
            } else {
                delete_post_meta( $variation_id, '_rw_maintenance_plan_key' );
            }
        }
    }

    /**
     * Variation override. Empty inherits the parent plan.
     *
     * @param int     $loop
     * @param array   $variation_data
     * @param WP_Post $variation
     */
    public function render_variation_plan_field( $loop, $variation_data, $variation ) {
        unset( $variation_data );
        $product = wc_get_product( $variation->ID );
        if ( ! $product || ! $product->is_type( 'subscription_variation' ) ) {
            return;
        }

        $this->render_license_packages_field( $variation->ID, 'variable_reactwoo_license_package_ids', $loop );

        $selected = (string) get_post_meta( $variation->ID, '_rw_maintenance_plan_key', true );
        if ( ! in_array( $selected, array( 'essential', 'standard', 'business' ), true ) ) {
            $selected = '';
        }

        woocommerce_wp_select(
            array(
                'id'            => 'variable_rw_maintenance_plan_key[' . $loop . ']',
                'name'          => 'variable_rw_maintenance_plan_key[' . $loop . ']',
                'label'         => __( 'Maintenance plan', 'reactwoo-api-manager' ),
                'description'   => __( 'Leave as Inherit to use the parent product plan. This is separate from software licensing.', 'reactwoo-api-manager' ),
                'value'         => $selected,
                'options'       => array(
                    ''          => __( 'Inherit', 'reactwoo-api-manager' ),
                    'essential' => __( 'Essential', 'reactwoo-api-manager' ),
                    'standard'  => __( 'Standard', 'reactwoo-api-manager' ),
                    'business'  => __( 'Business', 'reactwoo-api-manager' ),
                ),
                'wrapper_class' => 'form-row form-row-full',
            )
        );
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
            'class' => array( 'show_if_subscription', 'show_if_variable-subscription' ),
            'priority' => 25,
        );
        return $tabs;
    }

    /**
     * Add license tab content
     */
    public function add_license_tab_content() {
        global $post;

        $packages = $this->load_license_packages();
        $selected_ids = class_exists( 'ReactWoo_Subscription_Handler' )
            ? ReactWoo_Subscription_Handler::license_package_ids_for_post( $post->ID )
            : array();

        ?>
        <div id="reactwoo_license_product_data" class="panel woocommerce_options_panel">
            <div class="options_group">
                <?php
                if ( is_wp_error( $packages ) ) {
                    echo '<p class="form-field">';
                    echo '<strong>' . esc_html__( 'Error', 'reactwoo-api-manager' ) . ':</strong> ';
                    echo esc_html( $packages->get_error_message() );
                    echo '</p>';
                } elseif ( empty( $packages ) ) {
                    echo '<p class="form-field">';
                    echo esc_html__( 'No license packages are available from the license server. Please create at least one package on license.reactwoo.com and ensure the /api/packages endpoint is reachable.', 'reactwoo-api-manager' );
                    echo '</p>';
                } else {
                    $this->render_license_packages_field( $post->ID, '_reactwoo_license_package_ids' );
                    $this->render_maintenance_plan_field( $post->ID );
                    $this->render_plugin_slug_field( $post->ID );

                    if ( $selected_ids ) {
                        echo '<div class="form-field">';
                        echo '<p><strong>' . esc_html__( 'Selected Package Details', 'reactwoo-api-manager' ) . ':</strong></p>';
                        echo '<ul style="margin-left: 20px;">';
                        foreach ( $selected_ids as $selected_package_id ) {
                            $selected_package = $this->find_package_by_id( $packages, $selected_package_id );
                            if ( ! $selected_package ) {
                                continue;
                            }
                            $detail_name = isset( $selected_package['name'] ) ? trim( wp_strip_all_tags( (string) $selected_package['name'] ) ) : '';
                            if ( $detail_name ) {
                                echo '<li><strong>' . esc_html__( 'Package', 'reactwoo-api-manager' ) . ':</strong> ' . esc_html( $detail_name ) . '</li>';
                            }
                            if ( isset( $selected_package['description'] ) && $selected_package['description'] ) {
                                echo '<li><strong>' . esc_html__( 'Description', 'reactwoo-api-manager' ) . ':</strong> ' . esc_html( $selected_package['description'] ) . '</li>';
                            }
                            if ( isset( $selected_package['slug'] ) && $selected_package['slug'] ) {
                                echo '<li><strong>' . esc_html__( 'Slug', 'reactwoo-api-manager' ) . ':</strong> ' . esc_html( $selected_package['slug'] ) . '</li>';
                            }
                        }
                        echo '</ul>';
                        echo '</div>';
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

