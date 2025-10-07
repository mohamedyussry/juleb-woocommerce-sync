<?php

if ( ! defined( 'WPINC' ) ) {
    die;
}

class Juleb_Sync {

    protected $loader;
    protected $plugin_name;
    protected $version;

    public function __construct() {
        $this->version = defined('JULEB_SYNC_VERSION') ? JULEB_SYNC_VERSION : '1.0.0';
        $this->plugin_name = 'juleb-sync';
        add_action('plugins_loaded', array($this, 'init'));
    }

    /**
     * Initialize the plugin after license check.
     */
    public function init() {
        // First, check the license.
        if ($this->is_license_active()) {
            // License is valid, load the plugin's functionality.
            $this->init_features();
        } else {
            // License is invalid or missing, show a notice and do nothing else.
            add_action('admin_notices', array($this, 'juleb_show_license_notice'));
        }
    }

    /**
     * Load all the plugin's features, hooks, and dependencies.
     * This function only runs if the license is valid.
     */
    public function init_features() {
        $this->load_dependencies();
        $this->define_admin_hooks();
        $this->define_public_hooks();
    }

    private function load_dependencies() {
        // Core API Handler
        require_once JULEB_SYNC_PLUGIN_DIR . 'includes/class-api-handler.php';

        // Admin settings page
        require_once JULEB_SYNC_PLUGIN_DIR . 'admin/settings-page.php';

        // Sync Logic Classes
        require_once JULEB_SYNC_PLUGIN_DIR . 'includes/class-customer-sync.php';
        require_once JULEB_SYNC_PLUGIN_DIR . 'includes/class-order-sync.php';
        require_once JULEB_SYNC_PLUGIN_DIR . 'includes/class-product-sync.php';
    }

    private function define_admin_hooks() {
        // Initialize the settings page
        if ( is_admin() ) {
            new Juleb_Sync_Settings();
			add_filter( 'woocommerce_states', array($this, 'juleb_add_sa_states_for_admin') );
        }
    }

    private function define_public_hooks() {
        // Initialize sync classes
        new Juleb_Customer_Sync();
        new Juleb_Order_Sync();
        new Juleb_Product_Sync();

		// Checkout fields and scripts
		add_filter( 'woocommerce_checkout_fields' , array($this, 'juleb_custom_checkout_fields') );
    }

    public function run() {
        // The plugin is now running through the hooks defined above.
    }

    /**
     * Displays the admin notice for an invalid license.
     */
    public function juleb_show_license_notice() {
        ?>
        <div class="notice notice-error">
            <p><?php _e('Juleb WooCommerce Sync plugin is not active. Please activate your license to enable its functionality.', 'juleb-woocommerce-sync'); ?></p>
        </div>
        <?php
    }

    /**
     * Check if the license is active.
     * Caches the result for 24 hours to improve performance.
     *
     * @return boolean True if licensed, false otherwise.
     */
    private function is_license_active() {
        if (!current_user_can('manage_options')) {
            return true;
        }
        $cached_status = get_transient('juleb_sync_license_status');
        if (false !== $cached_status) {
            return 'valid' === $cached_status;
        }
        
        $license_url = 'https://opensheet.elk.sh/1DE4ZcZv2QeYbpTjDW0TbsRdy_T7yIt5qGSHbUoweOWA/1';
        
        $response = wp_remote_get($license_url, ['timeout' => 20]);
        
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            set_transient('juleb_sync_license_status', 'valid', HOUR_IN_SECONDS);
            return true;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (empty($data) || !is_array($data)) {
            set_transient('juleb_sync_license_status', 'valid', HOUR_IN_SECONDS);
            return true;
        }
        
        $site_url = get_site_url();
        $is_licensed = false;
        
        foreach ($data as $row) {
            if (isset($row['site_url'], $row['is_active']) && $row['site_url'] == $site_url && $row['is_active'] == '1') {
                $is_licensed = true;
                break;
            }
        }
        
        $status_to_cache = $is_licensed ? 'valid' : 'invalid';
        set_transient('juleb_sync_license_status', $status_to_cache, DAY_IN_SECONDS);
        
        return $is_licensed;
    }

	// --- City and Neighborhood Functions ---

	public function juleb_get_saudi_places() {
		$places = array(
			'DAM' => array('name' => 'فرع ظهران', 'neighborhoods' => array(),),
			'KHU' => array('name' => 'فرع الخبر', 'neighborhoods' => array(),),
			//'RIY' => array('name' => 'الرياض', 'neighborhoods' => array()),
			//'JED' => array('name' => 'جدة', 'neighborhoods' => array()),
			//'MAK' => array('name' => 'مكة', 'neighborhoods' => array()),
			//'MED' => array('name' => 'المدينة المنورة', 'neighborhoods' => array()),
			//'DHA' => array('name' => 'الظهران', 'neighborhoods' => array()),
			//'TAB' => array('name' => 'تبوك', 'neighborhoods' => array()),
			//'QAS' => array('name' => 'القصيم', 'neighborhoods' => array()),
			//'ASR' => array('name' => 'عسير', 'neighborhoods' => array()),
			//'TAI' => array('name' => 'الطائف', 'neighborhoods' => array()),
			//'AHB' => array('name' => 'أبها', 'neighborhoods' => array()),
		);
		return $places;
	}

	public function juleb_custom_checkout_fields( $fields ) {

		// Get cities for the dropdown
		$saudi_places = $this->juleb_get_saudi_places();
		$cities = array( '' => __( 'choose branch ', 'juleb-sync' ) );
		foreach ( $saudi_places as $key => $place ) {
			$cities[$key] = $place['name'];
		}

		// Change city fields to dropdowns
		$fields['billing']['billing_city']['type'] = 'select';
		$fields['billing']['billing_city']['options'] = $cities;
		$fields['billing']['billing_city']['label'] = 'Branch';

		$fields['shipping']['shipping_city']['type'] = 'select';
		$fields['shipping']['shipping_city']['options'] = $cities;
		$fields['shipping']['shipping_city']['label'] = 'Branch';

		// Change state fields to be a text input for Neighborhood
        if (isset($fields['billing']['billing_state'])) {
            $fields['billing']['billing_state']['type'] = 'text';
            $fields['billing']['billing_state']['label'] = 'الحي';
            $fields['billing']['billing_state']['placeholder'] = 'ادخل اسم الحي';
            unset($fields['billing']['billing_state']['options']);
        }
        if (isset($fields['shipping']['shipping_state'])) {
            $fields['shipping']['shipping_state']['type'] = 'text';
            $fields['shipping']['shipping_state']['label'] = 'الحي';
            $fields['shipping']['shipping_state']['placeholder'] = 'ادخل اسم الحي';
            unset($fields['shipping']['shipping_state']['options']);
        }

		return $fields;
	}

	public function juleb_enqueue_checkout_scripts() {
		if ( is_checkout() ) {
			wp_enqueue_script(
				'juleb-checkout',
				JULEB_SYNC_PLUGIN_URL . 'juleb-checkout.js',
				array( 'jquery' ),
				$this->version,
				true
			);
			wp_localize_script(
				'juleb-checkout',
				'juleb_checkout_params',
				array(
					'ajax_url' => admin_url( 'admin-ajax.php' ),
					'nonce' => wp_create_nonce( 'juleb_get_neighborhoods_nonce' )
				)
			);
		}
	}

	public function juleb_get_neighborhoods_ajax_handler() {
		// Nonce check
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'juleb_get_neighborhoods_nonce' ) ) {
			wp_send_json_error( 'Invalid nonce', 403 );
			return;
		}

		$city_key = isset( $_POST['city_key'] ) ? sanitize_text_field( $_POST['city_key'] ) : '';
		$saudi_places = $this->juleb_get_saudi_places();

		$neighborhoods = array();
		if ( $city_key && isset( $saudi_places[$city_key]['neighborhoods'] ) ) {
			$neighborhoods = $saudi_places[$city_key]['neighborhoods'];
		}

		wp_send_json_success( $neighborhoods );
	}

	public function juleb_add_sa_states_for_admin( $states ) {
		$saudi_places = $this->juleb_get_saudi_places();
		$flat_list = array();

		foreach ( $saudi_places as $city_key => $city_data ) {
			// Add the main city as an option
			$flat_list[$city_key] = $city_data['name'];
		}

		$states['SA'] = $flat_list;

		return $states;
	}
}
