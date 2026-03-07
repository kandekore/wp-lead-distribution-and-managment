<?php
// Display the credit field in the product edit page
add_action('woocommerce_product_options_general_product_data', function() {
    woocommerce_wp_text_input([
        'id' => '_credits',
        'label' => __('Credits', 'text-domain'),
        'desc_tip' => 'true',
        'description' => __('Number of credits this product represents.', 'text-domain'),
        'type' => 'number',
        'custom_attributes' => [
            'step' => '1',
            'min' => '1',
        ],
    ]);
});

// Save the credit field value in product meta
add_action('woocommerce_admin_process_product_object', function($product) {
    $credits = isset($_POST['_credits']) ? intval($_POST['_credits']) : 1;
    $product->update_meta_data('_credits', $credits);
});

// Handle credit assignment when an order is completed (covers both new subscriptions and renewals).
// The _ld_credits_assigned guard prevents double-assignment if this hook fires more than once.
add_action('woocommerce_order_status_completed', function($order_id) {
    $order = wc_get_order($order_id);
    $user_id = $order->get_user_id();

    if (!$user_id) return;
    if ($order->get_meta('_ld_credits_assigned')) return; // Idempotency guard

    foreach ($order->get_items() as $item) {
        $product = $item->get_product();

        if ($product && $product->get_meta('_credits')) {
            $credits = (int) $product->get_meta('_credits');
            $existing_credits = (int) get_user_meta($user_id, '_user_credits', true);
            update_user_meta($user_id, '_user_credits', $existing_credits + $credits);
        }
    }

    $order->update_meta_data('_ld_credits_assigned', '1');
    $order->save();
}, 10, 1);

// Add "Renew on Credit Depletion" checkbox in product settings
add_action('woocommerce_product_options_general_product_data', function() {
    woocommerce_wp_checkbox([
        'id' => '_renew_on_credit_depletion',
        'label' => __('Renew on Credit Depletion', 'text-domain'),
        'desc_tip' => 'true',
        'description' => __('Check this box to renew the subscription when user credits reach 5 or less.', 'text-domain'),
    ]);
});

// Save the "Renew on Credit Depletion" checkbox value
add_action('woocommerce_admin_process_product_object', function($product) {
    $renew_on_credit_depletion = isset($_POST['_renew_on_credit_depletion']) ? 'yes' : 'no';
    $product->update_meta_data('_renew_on_credit_depletion', $renew_on_credit_depletion);
});
