<?php
/**
 * Plugin Name: gb_wp_cloudflare
 * Description: Purge automatique Cloudflare via l’API officielle (api.cloudflare.com). Ajoute aussi un bouton manuel dans l’admin pour vider le cache.
 * Version: 1.0
 * Author: Gaétan Boishue
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Configuration – remplacez par vos vraies valeurs
 */
// Charger les valeurs depuis les options WordPress
define('GB_CF_ZONE_ID', get_option('gb_cf_zone_id', ''));
define('GB_CF_API_TOKEN', get_option('gb_cf_api_token', ''));

// Ajout de la page d’options dans l’admin
add_action('admin_menu', function() {
    add_options_page(
        'Cloudflare – Configuration',
        'Cloudflare',
        'manage_options',
        'gb-cf-settings',
        'gb_cf_settings_page'
    );
});

function gb_cf_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Tu n’as pas la permission pour ça.');
    }

    if (isset($_POST['gb_cf_save_settings'])) {
        update_option('gb_cf_zone_id', sanitize_text_field($_POST['gb_cf_zone_id']));
        update_option('gb_cf_api_token', sanitize_text_field($_POST['gb_cf_api_token']));
        echo '<div class="notice notice-success"><p>Configuration enregistrée ✅</p></div>';
    }

    $zone_id = esc_attr(get_option('gb_cf_zone_id', ''));
    $api_token = esc_attr(get_option('gb_cf_api_token', ''));

    echo '<div class="wrap">';
    echo '<h1>Configuration Cloudflare</h1>';
    echo '<form method="post">';
    echo '<table class="form-table">';
    echo '<tr><th scope="row"><label for="gb_cf_zone_id">Zone ID</label></th>';
    echo '<td><input type="text" id="gb_cf_zone_id" name="gb_cf_zone_id" value="' . $zone_id . '" class="regular-text" /></td></tr>';
    echo '<tr><th scope="row"><label for="gb_cf_api_token">API Token</label></th>';
    echo '<td><input type="text" id="gb_cf_api_token" name="gb_cf_api_token" value="' . $api_token . '" class="regular-text" /></td></tr>';
    echo '</table>';
    submit_button('Enregistrer', 'primary', 'gb_cf_save_settings');
    echo '</form></div>';
}

/**
 * Fonction générique de purge Cloudflare
 *
 * @param array $data – Exemple : ['purge_everything' => true] ou ['files' => ['https://...']]
 * @return bool – true si succès, false sinon
 */
function gb_cf_perform_purge($data) {
    $url = "https://api.cloudflare.com/client/v4/zones/" . GB_CF_ZONE_ID . "/purge_cache";

    $response = wp_remote_post($url, [
        'headers' => [
            'Authorization' => 'Bearer ' . GB_CF_API_TOKEN,
            'Content-Type'  => 'application/json',
        ],
        'body' => wp_json_encode($data),
        'timeout' => 20,
    ]);

    if (is_wp_error($response)) {
        error_log('gb_wp_cloudflare: HTTP error ' . $response->get_error_message());
        return false;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!empty($body['success']) && $body['success'] === true) {
        return true;
    }

    error_log('gb_wp_cloudflare: API error ' . print_r($body, true));
    return false;
}

/**
 * Purge auto Cloudflare quand un contenu est mis à jour (posts / produits publiés)
 */
add_action('save_post', function($post_ID, $post, $update) {
    // Types de post à purger : articles, pages, produits
    $allowed_types = ['post', 'page', 'product'];
    if (!in_array($post->post_type, $allowed_types, true)) {
        return;
    }

    if ($post->post_status !== 'publish') {
        return;
    }

    $urls_to_purge = [];

    // Purge l’URL du post
    $permalink = get_permalink($post_ID);
    if ($permalink) {
        $urls_to_purge[] = $permalink;
    }

    // Purge la catégorie d’article si applicable
    if ($post->post_type === 'post') {
        $categories = get_the_category($post_ID);
        foreach ($categories as $cat) {
            $cat_link = get_category_link($cat->term_id);
            if ($cat_link) {
                $urls_to_purge[] = $cat_link;
            }
        }
    }

    // Purge la catégorie de produit si applicable (WooCommerce)
    if ($post->post_type === 'product') {
        $terms = get_the_terms($post_ID, 'product_cat');
        if ($terms && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                $term_link = get_term_link($term, 'product_cat');
                if ($term_link && !is_wp_error($term_link)) {
                    $urls_to_purge[] = $term_link;
                }
            }
        }
    }

    if (!empty($urls_to_purge)) {
        gb_cf_perform_purge([
            'files' => $urls_to_purge
        ]);
    }
}, 20, 3);

/**
 * Ajout d’un menu admin avec bouton “Purger le cache CF”
 */
add_action('admin_menu', function() {
    add_menu_page(
        'Purger Cloudflare',
        'Purger Cloudflare',
        'manage_options',
        'gb-cf-purge',
        'gb_cf_purge_admin_page',
        'dashicons-cloud',
        75
    );
});

add_action('admin_bar_menu', function($wp_admin_bar) {
    if (!current_user_can('manage_options')) {
        return;
    }
    $wp_admin_bar->add_node([
        'id'    => 'gb_cf_purge_topbar',
        'title' => 'Purger Cloudflare',
        'href'  => admin_url('admin.php?page=gb-cf-purge'),
        'meta'  => [
            'class' => 'gb-cf-purge-topbar',
            'title' => 'Purger tout le cache Cloudflare'
        ]
    ]);
}, 100);

/**
 * Page d’admin avec bouton de purge manuelle
 */
function gb_cf_purge_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Tu n’as pas la permission pour ça.');
    }

    if (isset($_POST['gb_cf_purge_btn'])) {
        $ok = gb_cf_perform_purge(['purge_everything' => true]);
        if ($ok) {
            echo '<div class="notice notice-success"><p>Cache Cloudflare purgé avec succès ✅</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Erreur lors de la purge du cache ❌ (voir logs).</p></div>';
        }
    }

    echo '<div class="wrap">';
    echo '<h1>Purger Cloudflare</h1>';
    echo '<form method="post">';
    submit_button('Purger tout le cache Cloudflare', 'primary', 'gb_cf_purge_btn');
    echo '</form></div>';
}
