jQuery(document).ready(function($) {

    // Target the billing and shipping city/state fields
    var billing_country_select = '#billing_country';
    var shipping_country_select = '#shipping_country';
    var billing_city_select = '#billing_city';
    var shipping_city_select = '#shipping_city';
    var billing_state_select = '#billing_state';
    var shipping_state_select = '#shipping_state';

    function handle_country_change(country, city_selector, state_selector) {
        var city_field = $(city_selector).closest('.form-row');
        var state_field = $(state_selector).closest('.form-row');

        // This logic makes the fields dependent on the country being SA
        if (country === 'SA') {
            city_field.show();
            state_field.show();
        } else {
            // For other countries, revert to standard behavior if needed
            // For now, we just ensure they are visible if SA is selected
        }
    }

    // --- Initial state on page load ---
    var initial_billing_country = $(billing_country_select).val();
    handle_country_change(initial_billing_country, billing_city_select, billing_state_select);
    
    if ($(shipping_country_select).length) {
        var initial_shipping_country = $(shipping_country_select).val();
        handle_country_change(initial_shipping_country, shipping_city_select, shipping_state_select);
    }

    // --- Listen for country changes ---
    $(document.body).on('change', 'select[name="billing_country"], select[name="shipping_country"] ', function() {
        var country = $(this).val();
        var field_prefix = $(this).attr('name').split('_')[0]; // billing or shipping
        handle_country_change(country, '#' + field_prefix + '_city', '#' + field_prefix + '_state');
    });


    function update_neighborhoods(city_key, state_selector) {
        var state_dropdown = $(state_selector);

        if (!city_key) {
            state_dropdown.empty().append('<option value="">' + 'اختر مدينة أولاً' + '</option>').trigger('change');
            return;
        }

        $.ajax({
            url: juleb_checkout_params.ajax_url,
            type: 'POST',
            data: {
                action: 'juleb_get_neighborhoods',
                nonce: juleb_checkout_params.nonce,
                city_key: city_key
            },
            beforeSend: function() {
                state_dropdown.prop('disabled', true).empty().append('<option value="">' + 'جاري التحميل...' + '</option>');
            },
            success: function(response) {
                state_dropdown.prop('disabled', false).empty();
                if (response.success) {
                    var neighborhoods = response.data;
                    state_dropdown.append('<option value="">' + 'اختر الحي' + '</option>');
                    if (Object.keys(neighborhoods).length > 0) {
                        $.each(neighborhoods, function(key, name) {
                            state_dropdown.append($('<option></option>').attr('value', key).text(name));
                        });
                    } else {
                         state_dropdown.append('<option value="">' + 'لا توجد أحياء لهذه المدينة' + '</option>');
                    }
                } else {
                    state_dropdown.append('<option value="">' + 'حدث خطأ' + '</option>');
                }
                state_dropdown.trigger('change'); // Notify other plugins of the change
            },
            error: function() {
                state_dropdown.prop('disabled', false).empty().append('<option value="">' + 'فشل الطلب' + '</option>');
            }
        });
    }

    // --- Listen for city changes ---
    $(document.body).on('change', 'select[name="billing_city"], select[name="shipping_city"] ', function() {
        var city_key = $(this).val();
        var field_prefix = $(this).attr('name').split('_')[0]; // billing or shipping
        update_neighborhoods(city_key, '#' + field_prefix + '_state');
    });

});
