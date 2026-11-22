<?php

/**
 * Plugin Name: WC Free Gifts
 * Description: This plugin let's you get the cheapest products for free as gifts if 3 or 5 items are added to the cart.
 * Plugin URI: https://www.mahimzaman.com
 * Version: 1.1
 * Author: Mahim Zaman
 * Author URI: https://www.mahimzaman.com
 * Text Domain: wcfg
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

add_action('woocommerce_before_calculate_totals', 'wcfg_split_and_apply_free_rules', 999);
function wcfg_split_and_apply_free_rules($cart)
{
    $cart_count = $cart->get_cart_contents_count();
    if ($cart_count < 3)  return;
    foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
        $product = $cart_item['data'];

        if ($cart_count == 3) {
            $cheapest = wcfg_get_n_cheapest_cart_items();
            if ($cart_item_key == $cheapest[0]['key'] && $cheapest[0]['data']['quantity'] == 1) {
                $product->set_price(0);
            }
        }

        if ($cart_count == 5) {
            $cheapest = wcfg_get_n_cheapest_cart_items(2);
            if ($cart_item_key == $cheapest[0]['key'] && $cheapest[0]['data']['quantity'] == 1 && $cheapest[1]['data']['quantity'] == 1) {
                $product->set_price(0);
            } elseif ($cart_item_key == $cheapest[1]['key'] && $cheapest[0]['data']['quantity'] == 1 && $cheapest[1]['data']['quantity'] == 1) {
                $product->set_price(0);
            }
        }
    }
}

add_filter('woocommerce_cart_item_price', 'wcfg_show_free_badge_if_free', 20, 3);
function wcfg_show_free_badge_if_free($price_html, $cart_item, $cart_item_key)
{
    // Only show on cart page
    if (!is_cart()) {
        return $price_html;
    }

    $product = $cart_item['data'];
    if (!$product) {
        return $price_html;
    }

    // If the product is free (set by your free rules)
    if (floatval($product->get_price()) == 0) {

        // Get the original price (regular or sale)
        $original_price = $product->get_regular_price();
        if (!$original_price) {
            return $price_html; // safety fallback
        }

        // Format original price as WooCommerce HTML
        $original_html = wc_price($original_price);

        // Build strike-through + free badge
        $striked = '<span style="text-decoration: line-through; opacity:.6;">' . $original_html . '</span>';
        $badge   = '<span style="background:#28a745; color:#fff; font-size:12px; padding:3px 6px; margin-left:6px; border-radius:4px; font-weight:600;">FREE</span>';

        return $striked . ' ' . $badge;
    }

    // Not free → return normal price
    return $price_html;
}

/**
 * Show strike-through original price + FREE badge in cart subtotal
 */
add_filter('woocommerce_cart_item_subtotal', 'wcfg_show_free_badge_in_subtotal', 20, 3);
function wcfg_show_free_badge_in_subtotal($subtotal_html, $cart_item, $cart_item_key)
{
    if (!is_cart()) {
        return $subtotal_html;
    }

    $product = $cart_item['data'];
    if (!$product) {
        return $subtotal_html;
    }

    // Detect your free product
    if (floatval($product->get_price()) == 0) {

        // Get original price × quantity
        $qty = $cart_item['quantity'];
        $original_price = $product->get_regular_price();

        if (!$original_price) {
            return $subtotal_html; // fallback
        }

        $original_subtotal_html = wc_price($original_price * $qty);

        // Build strike-through + badge
        $striked = '<span style="text-decoration: line-through; opacity:.6;">' . $original_subtotal_html . '</span>';
        $badge   = '<span style="background:#28a745; color:#fff; font-size:12px; padding:3px 6px; margin-left:6px; border-radius:4px; font-weight:600;">FREE</span>';

        return $striked . ' ' . $badge;
    }

    return $subtotal_html;
}


function wcfg_get_n_cheapest_cart_items($count = 1)
{
    if (! WC()->cart) {
        return [];
    }

    $cart = WC()->cart->get_cart();
    if (empty($cart)) {
        return [];
    }

    $items_with_prices = [];

    // Collect each cart line with its price
    foreach ($cart as $cart_item_key => $cart_item) {
        $product = $cart_item['data'];

        if (! $product instanceof WC_Product) {
            continue;
        }

        // Line price = product’s effective price * quantity
        $line_price = $product->get_price() * $cart_item['quantity'];

        $items_with_prices[] = [
            'key'   => $cart_item_key,
            'price' => (float) $line_price,
            'data'  => $cart_item
        ];
    }

    // Sort by price ASC
    usort($items_with_prices, function ($a, $b) {
        return $a['price'] <=> $b['price'];
    });

    // Get only the first N cheapest
    return array_slice($items_with_prices, 0, $count);
}

/**
 * Force WooCommerce to split multiple quantities into separate cart items.
 */
add_filter('woocommerce_add_cart_item_data', 'separate_cart_items_on_add', 10, 3);
function separate_cart_items_on_add($cart_item_data, $product_id, $variation_id)
{
    // Add a unique key so WooCommerce treats each item as unique.
    $cart_item_data['unique_line_id'] = uniqid();
    return $cart_item_data;
}

/**
 * After cart is loaded (including cart page update), split quantities.
 */
add_action('woocommerce_cart_loaded_from_session', 'split_cart_item_quantities');
function split_cart_item_quantities($cart)
{

    if (is_admin() && ! defined('DOING_AJAX')) return;

    $items_to_add = [];

    foreach ($cart->get_cart() as $cart_item_key => $item) {

        if ($item['quantity'] > 1) {

            $qty = $item['quantity'];

            // Reduce the original to 1
            $cart->set_quantity($cart_item_key, 1);

            // Prepare (qty - 1) new clones of the product
            for ($i = 1; $i < $qty; $i++) {
                $new_item = $item;

                // Remove WooCommerce internal keys
                unset($new_item['key']);

                // Add new unique identifier
                $new_item['unique_line_id'] = uniqid();

                $items_to_add[] = $new_item;
            }
        }
    }

    // Now safely add new items to the cart
    foreach ($items_to_add as $new_item) {
        $cart->add_to_cart(
            $new_item['product_id'],
            1,
            isset($new_item['variation_id']) ? $new_item['variation_id'] : 0,
            isset($new_item['variation']) ? $new_item['variation'] : [],
            $new_item
        );
    }
}
