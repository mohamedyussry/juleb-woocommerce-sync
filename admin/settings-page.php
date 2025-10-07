<?php

if ( ! defined( 'WPINC' ) ) {
    die;
}

class Juleb_Sync_Settings {

    private $options;
    private $api_handler;

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_plugin_page' ) );
        add_action( 'admin_init', array( $this, 'page_init' ) );
        add_action( 'wp_ajax_juleb_test_connection', array( $this, 'handle_test_connection_ajax' ) );
        $this->api_handler = new Juleb_API_Handler();
    }

    public function add_plugin_page() {
        add_submenu_page(
            'options-general.php', // Changed parent slug
            'Juleb Sync Settings',
            'Juleb Sync',
            'manage_options',
            'juleb-sync-settings',
            array( $this, 'create_admin_page' )
        );
    }

    public function create_admin_page() {
        $this->options = get_option( 'juleb_sync_options' );
        $companies = $this->get_companies();
        $active_tab = isset( $_GET['tab'] ) ? $_GET['tab'] : 'general';
        ?>
        <div class="wrap">
            <h1>Juleb ERP Sync Settings</h1>

            <nav class="nav-tab-wrapper">
                <a href="?page=juleb-sync-settings&tab=general" class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>">General</a>
                <a href="?page=juleb-sync-settings&tab=city_routing" class="nav-tab <?php echo $active_tab == 'city_routing' ? 'nav-tab-active' : ''; ?>">City Routing</a>
                <a href="?page=juleb-sync-settings&tab=shipping_zone_mapping" class="nav-tab <?php echo $active_tab == 'shipping_zone_mapping' ? 'nav-tab-active' : ''; ?>">Zone Routing (Legacy)</a>
                <?php
                if ( ! is_wp_error( $companies ) ) {
                    foreach ( $companies as $company ) {
                        echo '<a href="?page=juleb-sync-settings&tab=branch_' . esc_attr( $company['id'] ) . '" class="nav-tab ' . ( $active_tab == 'branch_' . $company['id'] ? 'nav-tab-active' : '' ) . '">' . esc_html( $company['name'] ) . '</a>';
                    }
                }
                ?>
            </nav>

            <form method="post" action="options.php">
                <?php
                    settings_fields( 'juleb_sync_option_group' );
                    
                    if ($active_tab === 'general') {
                        do_settings_sections( 'juleb-sync-admin-general' );
                    } elseif ($active_tab === 'city_routing') {
                        do_settings_sections( 'juleb-sync-admin-city-routing' );
                    } elseif ($active_tab === 'shipping_zone_mapping') {
                        do_settings_sections( 'juleb-sync-admin-shipping' );
                    }
                    else {
                        // Assumes branch tab
                        do_settings_sections( 'juleb-sync-admin-branch' );
                    }
                    
                    submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function handle_test_connection_ajax() {
        check_ajax_referer( 'juleb_test_connection_nonce' );
        $response = $this->api_handler->test_connection();
        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 'message' => 'Connection Failed: ' . $response->get_error_message() ) );
        } else {
            wp_send_json_success( array( 'message' => 'Connection Successful! The API credentials are correct.' ) );
        }
    }

    public function page_init() {
        $this->options = get_option( 'juleb_sync_options' );
        $active_tab = isset( $_GET['tab'] ) ? $_GET['tab'] : 'general';

        register_setting(
            'juleb_sync_option_group',
            'juleb_sync_options',
            array( $this, 'sanitize' )
        );

        // General Settings
        add_settings_section('juleb_setting_section_api', 'API Credentials', null, 'juleb-sync-admin-general');
        add_settings_field('juleb_base_url', 'Juleb Base URL', array( $this, 'render_text_field' ), 'juleb-sync-admin-general', 'juleb_setting_section_api', ['name' => 'juleb_base_url']);
        add_settings_field('juleb_bearer_token', 'Juleb Bearer Token', array( $this, 'render_text_field' ), 'juleb-sync-admin-general', 'juleb_setting_section_api', ['name' => 'juleb_bearer_token', 'type' => 'password']);
        add_settings_field('test_connection', 'Test Connection', array( $this, 'render_test_connection_button' ), 'juleb-sync-admin-general', 'juleb_setting_section_api');

        // City Routing Settings
        add_settings_section('juleb_setting_section_city_routing', 'City to Branch Mapping', array($this, 'print_city_routing_section_info'), 'juleb-sync-admin-city-routing');
        add_settings_field('city_map', 'City Mappings', array( $this, 'render_city_routing_mapping' ), 'juleb-sync-admin-city-routing', 'juleb_setting_section_city_routing');

        // Branch Routing Settings
        add_settings_section('juleb_setting_section_shipping', 'Shipping Zone to Branch Mapping', array($this, 'print_shipping_section_info'), 'juleb-sync-admin-shipping');
        add_settings_field('shipping_zone_map', 'Zone Mappings', array( $this, 'render_shipping_zone_mapping' ), 'juleb-sync-admin-shipping', 'juleb_setting_section_shipping');

        // Branch Specific Settings
        if ( strpos( $active_tab, 'branch_' ) === 0 ) {
            $branch_id = (int) str_replace( 'branch_', '', $active_tab );
            
            // Session Section
            add_settings_section('juleb_setting_section_branch_session', 'Session Selection', null, 'juleb-sync-admin-branch');
            add_settings_field('juleb_session', 'Session', array( $this, 'render_session_dropdown' ), 'juleb-sync-admin-branch', 'juleb_setting_section_branch_session', ['branch_id' => $branch_id]);

            // Payment Mapping Section
            add_settings_section('juleb_setting_section_payment', 'Payment Method Mapping', array($this, 'print_payment_section_info'), 'juleb-sync-admin-branch');
            if ( class_exists('WooCommerce') ) {
                $payment_gateways = WC()->payment_gateways->get_available_payment_gateways();
                foreach ($payment_gateways as $gateway) {
                    add_settings_field(
                        'payment_map_' . $gateway->id,
                        $gateway->get_title(),
                        array($this, 'render_payment_method_dropdown'),
                        'juleb-sync-admin-branch',
                        'juleb_setting_section_payment',
                        ['gateway_id' => $gateway->id, 'branch_id' => $branch_id]
                    );
                }
            }
        }
    }

    public function sanitize( $input ) {
        $new_input = $input ? $input : [];
        $output = get_option( 'juleb_sync_options' );
        if ( ! is_array( $output ) ) {
            $output = [];
        }

        $active_tab = isset( $_POST['_wp_http_referer'] ) ? wp_parse_url( $_POST['_wp_http_referer'], PHP_URL_QUERY ) : '';
        parse_str( $active_tab, $query_params );
        $active_tab = isset( $query_params['tab'] ) ? $query_params['tab'] : 'general';

        if ( $active_tab === 'general' ) {
            if( isset( $new_input['juleb_base_url'] ) ) $output['juleb_base_url'] = esc_url_raw( trim( $new_input['juleb_base_url'] ) );
            if( isset( $new_input['juleb_bearer_token'] ) ) $output['juleb_bearer_token'] = sanitize_text_field( trim( $new_input['juleb_bearer_token'] ) );
        } elseif ( $active_tab === 'city_routing' ) {
            $output['city_map'] = [];
            if (isset($new_input['city_map']) && is_array($new_input['city_map'])) {
                foreach ($new_input['city_map'] as $map) {
                    if (!empty($map['city']) && !empty($map['branch'])) {
                        $output['city_map'][] = [
                            'city' => sanitize_text_field(strtoupper($map['city'])),
                            'branch' => absint($map['branch'])
                        ];
                    }
                }
            }
        } elseif ( $active_tab === 'shipping_zone_mapping' ) {
            $output['shipping_zone_map'] = isset($new_input['shipping_zone_map']) ? array_map('absint', $new_input['shipping_zone_map']) : [];
        } elseif ( strpos( $active_tab, 'branch_' ) === 0 ) {
            $branch_id = (int) str_replace( 'branch_', '', $active_tab );
            if ( ! isset( $output['branches'][ $branch_id ] ) ) {
                $output['branches'][ $branch_id ] = [];
            }
            if( isset( $new_input['session'] ) ) $output['branches'][ $branch_id ]['session'] = absint( $new_input['session'] );
            
            if ( class_exists('WooCommerce') ) {
                $payment_gateways = WC()->payment_gateways->get_available_payment_gateways();
                foreach ($payment_gateways as $gateway) {
                    $field_name = 'payment_map_' . $gateway->id;
                    if( array_key_exists($field_name, $new_input) ) {
                        $output['branches'][$branch_id][$field_name] = $new_input[$field_name] ? absint( $new_input[$field_name] ) : '';
                    }
                }
            }
        }
        
        return $output;
    }

    // Helper functions to print section info
    public function print_city_routing_section_info() {
        echo '<p>Map a City Code (e.g., DAM, RYD) to a Juleb branch. This is the primary method for routing orders.<br>City codes are not case-sensitive.</p>';
    }

    public function print_shipping_section_info() {
        echo '<p>Map your WooCommerce Shipping Zones to the corresponding Juleb branch. This is a legacy method and will only be used if no match is found in City Routing.</p>';
    }

    public function print_payment_section_info() {
        echo '<p>Select the corresponding Juleb payment method for each WooCommerce payment method for this specific branch.</p>';
    }

    // --- RENDER FUNCTIONS ---

    public function render_text_field( $args ) {
        $name = $args['name'];
        $type = isset($args['type']) ? $args['type'] : 'text';
        $value = isset($this->options[$name]) ? esc_attr($this->options[$name]) : '';
        printf('<input type="%s" id="%s" name="juleb_sync_options[%s]" value="%s" class="regular-text" />', $type, $name, $name, $value);
    }

    public function render_city_routing_mapping() {
        $companies = $this->get_companies();
        $mappings = $this->options['city_map'] ?? [];
        ?>
        <style>
            #juleb-city-map-table .button-danger { color: #a00; border-color: #a00; }
            #juleb-city-map-table .button-danger:hover { background: #a00; color: #fff; }
        </style>
        <table id="juleb-city-map-table" class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th style="width: 40%;">City Code</th>
                    <th style="width: 40%;">Branch</th>
                    <th style="width: 20%;">Action</th>
                </tr>
            </thead>
            <tbody id="juleb-city-map-body">
                <?php
                if (!empty($mappings)) {
                    foreach ($mappings as $index => $map) {
                        ?>
                        <tr>
                            <td><input type="text" name="juleb_sync_options[city_map][<?php echo $index; ?>][city]" value="<?php echo esc_attr($map['city']); ?>" class="regular-text"></td>
                            <td>
                                <select name="juleb_sync_options[city_map][<?php echo $index; ?>][branch]">
                                    <option value="">-- Select a Branch --</option>
                                    <?php
                                    if (!is_wp_error($companies)) {
                                        foreach ($companies as $company) {
                                            printf('<option value="%s" %s>%s</option>',
                                                esc_attr($company['id']),
                                                selected($map['branch'], $company['id'], false),
                                                esc_html($company['name'])
                                            );
                                        }
                                    }
                                    ?>
                                </select>
                            </td>
                            <td><button type="button" class="button button-danger juleb-remove-city-map-row">Remove</button></td>
                        </tr>
                        <?php
                    }
                }
                ?>
            </tbody>
        </table>
        <p><button type="button" class="button" id="juleb-add-city-map-row">Add Mapping</button></p>

        <script type="text/template" id="juleb-city-map-template">
            <tr>
                <td><input type="text" name="juleb_sync_options[city_map][__INDEX__][city]" class="regular-text"></td>
                <td>
                    <select name="juleb_sync_options[city_map][__INDEX__][branch]">
                        <option value="">-- Select a Branch --</option>
                        <?php
                        if (!is_wp_error($companies)) {
                            foreach ($companies as $company) {
                                printf('<option value="%s">%s</option>', esc_attr($company['id']), esc_html($company['name']));
                            }
                        }
                        ?>
                    </select>
                </td>
                <td><button type="button" class="button button-danger juleb-remove-city-map-row">Remove</button></td>
            </tr>
        </script>

        <script>
        jQuery(document).ready(function($) {
            var rowIndex = <?php echo count($mappings); ?>;

            $('#juleb-add-city-map-row').on('click', function() {
                var template = $('#juleb-city-map-template').html().replace(/__INDEX__/g, rowIndex);
                $('#juleb-city-map-body').append(template);
                rowIndex++;
            });

            $('#juleb-city-map-table').on('click', '.juleb-remove-city-map-row', function() {
                $(this).closest('tr').remove();
            });
        });
        </script>
        <?php
    }

    public function render_shipping_zone_mapping() {
        if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
            echo '<p>WooCommerce is not active. Cannot fetch shipping zones.</p>';
            return;
        }
        $zones = WC_Shipping_Zones::get_zones();
        // Add the default "Rest of the World" zone
        $zones[] = ['id' => 0, 'zone_name' => 'Rest of the World'];

        $companies = $this->get_companies();
        $mappings = isset($this->options['shipping_zone_map']) ? $this->options['shipping_zone_map'] : [];

        if ( is_wp_error( $companies ) ) {
            echo '<p>Could not fetch branches from Juleb. Please check API credentials.</p>';
            return;
        }

        echo '<table>';
        foreach ($zones as $zone) {
            $zone_id = $zone['id'];
            $zone_name = $zone['zone_name'];
            $mapped_branch_id = isset($mappings[$zone_id]) ? $mappings[$zone_id] : '';

            echo '<tr>';
            echo '<th style="text-align: left; padding-right: 1em;">' . esc_html($zone_name) . '</th>';
            echo '<td>';
            echo '<select name="juleb_sync_options[shipping_zone_map][' . esc_attr($zone_id) . ']">';
            echo '<option value="">-- Select a Branch --</option>';
            foreach ($companies as $company) {
                printf('<option value="%s" %s>%s</option>',
                    esc_attr($company['id']),
                    selected($mapped_branch_id, $company['id'], false),
                    esc_html($company['name'])
                );
            }
            echo '</select>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }

    public function render_session_dropdown( $args ) {
        $branch_id = $args['branch_id'];
        $pos_configs = $this->get_pos_configs();
        $value = isset($this->options['branches'][$branch_id]['session']) ? $this->options['branches'][$branch_id]['session'] : '';

        if ( is_wp_error( $pos_configs ) ) {
            echo '<p>Could not fetch sessions from Juleb. Please check API credentials.</p>';
            return;
        }

        echo '<select name="juleb_sync_options[session]">';
        echo '<option value="">-- Select a Session --</option>';
        foreach ($pos_configs as $config) {
            if ($config['company_id'] == $branch_id) {
                printf('<option value="%s" %s>%s</option>',
                    esc_attr($config['id']),
                    selected($value, $config['id'], false),
                    esc_html($config['name'])
                );
            }
        }
        echo '</select>';
    }

    public function render_payment_method_dropdown( $args ) {
        $branch_id = $args['branch_id'];
        $gateway_id = $args['gateway_id'];
        $field_name = 'payment_map_' . $gateway_id;
        
        $juleb_payment_methods = $this->api_handler->get_payment_methods($branch_id);
        $value = isset($this->options['branches'][$branch_id][$field_name]) ? $this->options['branches'][$branch_id][$field_name] : '';

        if (is_wp_error($juleb_payment_methods) || empty($juleb_payment_methods)) {
            echo '<p class="description">No payment methods found for this branch.</p>';
            return;
        }

        echo '<select name="juleb_sync_options[' . esc_attr($field_name) . ']">';
        echo '<option value="">-- Select a Method --</option>';
        foreach ($juleb_payment_methods as $method) {
            if (isset($method['id']) && isset($method['name'])) {
                printf('<option value="%s" %s>%s</option>',
                    esc_attr($method['id']),
                    selected($value, $method['id'], false),
                    esc_html($method['name'])
                );
            }
        }
        echo '</select>';
    }

    public function render_test_connection_button() {
        ?>
        <button type="button" class="button button-secondary" id="juleb-test-connection-btn">Test Connection</button>
        <div id="juleb-test-connection-result" style="display: inline-block; margin-left: 10px;"></div>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('#juleb-test-connection-btn').on('click', function() {
                    var resultDiv = $('#juleb-test-connection-result');
                    resultDiv.html('<span class="spinner is-active" style="float:left"></span>');
                    $.post(ajaxurl, { action: 'juleb_test_connection', _ajax_nonce: '<?php echo wp_create_nonce( "juleb_test_connection_nonce" ); ?>' }, function(response) {
                        resultDiv.empty();
                        if (response.success) {
                            resultDiv.html('<span style="color:green">Success!</span>');
                        } else {
                            resultDiv.html('<span style="color:red">Failed!</span>');
                        }
                    });
                });
            });
        </script>
        <?php
    }

    // --- DATA FETCHING HELPERS ---

    private function get_companies() {
        $companies = $this->api_handler->get_companies();
        if ( !is_wp_error($companies) && isset( $companies['data'] ) && is_array( $companies['data'] ) ) {
            return $companies['data'];
        }
        return new WP_Error('api_error', 'Could not fetch companies from Juleb.');
    }

    private function get_pos_configs() {
        $pos_configs = $this->api_handler->get_pos_configs();
        if ( !is_wp_error($pos_configs) && isset($pos_configs['data']) && is_array( $pos_configs['data'] ) ) {
            return $pos_configs['data'];
        }
        return new WP_Error('api_error', 'Could not fetch POS configs from Juleb.');
    }
}
