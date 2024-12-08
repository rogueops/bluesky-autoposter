<?php
/*
* Plugin Name: Simple Auto-Poster for Bluesky
* Description: Automatically posts to Bluesky when a WordPress post is published, including featured images
* Version: 1.5
* Author: Emma Blackwell
* License: GPLv2 or later
* License URI: https://www.gnu.org/licenses/gpl-2.0.html
* Text Domain: simple-auto-poster-for-bluesky
* Domain Path: /languages
*/

// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

class Simple_Bluesky_Poster {
    private $identifier;
    private $password;
    private $log_file;

    public function __construct() {
        add_action('publish_post', [$this, 'handle_post'], 10, 2);
        add_action('admin_menu', [$this, 'add_menu_page']); // Changed to use its own menu item
        add_action('admin_init', [$this, 'register_settings']);
        add_action('plugins_loaded', [$this, 'load_plugin_textdomain']);
        $upload_dir = wp_upload_dir();
        $this->log_file = $upload_dir['basedir'] . '/bluesky_poster_log.txt';
        $this->log(__("Plugin initialized", 'simple-auto-poster-for-bluesky'));
    }

    public function load_plugin_textdomain() {
        load_plugin_textdomain('simple-auto-poster-for-bluesky', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }

    private function log($message) {
        global $wp_filesystem;

        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        WP_Filesystem();

        if ($wp_filesystem->is_writable(dirname($this->log_file))) {
            $timestamp = gmdate('[Y-m-d H:i:s]');
            $log_message = $timestamp . ' ' . sanitize_text_field($message) . "\n";
            $existing_content = $wp_filesystem->get_contents($this->log_file);
            $updated_content = $existing_content . $log_message;
            $wp_filesystem->put_contents($this->log_file, $updated_content, FS_CHMOD_FILE);
        }
    }

    public function handle_post($post_ID, $post, $update = null) {
        $allowed_categories = get_option('bluesky_allowed_categories', []);
        $post_categories = wp_get_post_categories($post_ID);

        if (empty(array_intersect($allowed_categories, $post_categories))) {
            $this->log(__("Post categories do not match allowed categories - skipping", 'simple-auto-poster-for-bluesky'));
            return;
        }

        if (get_post_status($post_ID) === 'publish' && get_post_type($post_ID) === 'post') {
            $this->post_to_bluesky($post_ID, $post);
        }
    }

public function post_to_bluesky($post_ID, $post) {
    $this->identifier = get_option('bluesky_identifier');
    $this->password = get_option('bluesky_password');

    if (empty($this->identifier) || empty($this->password)) {
        $this->log(__("Bluesky credentials not set", 'simple-auto-poster-for-bluesky'));
        return;
    }

    $auth_url = 'https://bsky.social/xrpc/com.atproto.server.createSession';
    $post_url = 'https://bsky.social/xrpc/com.atproto.repo.createRecord';

    $this->log(__("Authenticating with Bluesky", 'simple-auto-poster-for-bluesky'));
    $auth_response = wp_remote_post($auth_url, [
        'body' => wp_json_encode([
            'identifier' => $this->identifier,
            'password' => $this->password
        ]),
        'headers' => ['Content-Type' => 'application/json']
    ]);

    if (is_wp_error($auth_response)) {
        $this->log(sprintf(
            __("Bluesky authentication failed: %s", 'simple-auto-poster-for-bluesky'),
            $auth_response->get_error_message()
        ));
        return;
    }

    if (wp_remote_retrieve_response_code($auth_response) != 200) {
        $this->log(sprintf(
            __("Bluesky authentication failed with status code: %d", 'simple-auto-poster-for-bluesky'),
            wp_remote_retrieve_response_code($auth_response)
        ));
        return;
    }

    $session_data = json_decode(wp_remote_retrieve_body($auth_response), true);
    $access_token = $session_data['accessJwt'];
    $did = $session_data['did'];

    $this->log(__("Authentication successful", 'simple-auto-poster-for-bluesky'));

    $permalink = get_permalink($post_ID);
    $post_data = [
        'repo' => $did,
        'collection' => 'app.bsky.feed.post',
        'record' => [
            'text' => wp_strip_all_tags($post->post_title) . "\n" . $permalink,
            'createdAt' => gmdate('c')
        ]
    ];

    $this->log(__("Posting to Bluesky", 'simple-auto-poster-for-bluesky'));
    $post_response = wp_remote_post($post_url, [
        'body' => wp_json_encode($post_data),
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => "Bearer $access_token"
        ]
    ]);

    if (is_wp_error($post_response)) {
        $this->log(sprintf(
            __("Failed to post to Bluesky: %s", 'simple-auto-poster-for-bluesky'),
            $post_response->get_error_message()
        ));
        return;
    }

    if (wp_remote_retrieve_response_code($post_response) != 200) {
        $this->log(sprintf(
            __("Failed to post to Bluesky. Status code: %d", 'simple-auto-poster-for-bluesky'),
            wp_remote_retrieve_response_code($post_response)
        ));
        return;
    }

    $this->log(__("Successfully posted to Bluesky", 'simple-auto-poster-for-bluesky'));
    update_post_meta($post_ID, 'bluesky_shared', true);
}


    public function add_menu_page() {
        add_menu_page(
            __('Bluesky Auto-Poster', 'simple-auto-poster-for-bluesky'),
            __('Bluesky Auto-Poster', 'simple-auto-poster-for-bluesky'),
            'manage_options',
            'bluesky-settings',
            [$this, 'render_settings_page'],
            'dashicons-share-alt' // Dashicon for the menu item
        );
    }

    public function render_settings_page() {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html(get_admin_page_title()) . '</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields('bluesky_settings');
        do_settings_sections('bluesky-settings');
        submit_button();
        echo '</form>';
        echo '</div>';
    }

    public function register_settings() {
        register_setting('bluesky_settings', 'bluesky_identifier');
        register_setting('bluesky_settings', 'bluesky_password');
        register_setting('bluesky_settings', 'bluesky_allowed_categories', [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_category_selection'],
            'default' => []
        ]);

        add_settings_section(
            'bluesky_settings_section',
            __('Bluesky Credentials and Categories', 'simple-auto-poster-for-bluesky'),
            null,
            'bluesky-settings'
        );

        add_settings_field(
            'bluesky_identifier',
            __('Bluesky Identifier', 'simple-auto-poster-for-bluesky'),
            [$this, 'render_identifier_field'],
            'bluesky-settings',
            'bluesky_settings_section'
        );

        add_settings_field(
            'bluesky_password',
            __('Bluesky App Password', 'simple-auto-poster-for-bluesky'),
            [$this, 'render_password_field'],
            'bluesky-settings',
            'bluesky_settings_section'
        );

        add_settings_field(
            'bluesky_allowed_categories',
            __('Allowed Categories', 'simple-auto-poster-for-bluesky'),
            [$this, 'render_category_field'],
            'bluesky-settings',
            'bluesky_settings_section'
        );
    }

    public function render_identifier_field() {
        echo '<input type="text" name="bluesky_identifier" value="' . esc_attr(get_option('bluesky_identifier')) . '" />';
    }

    public function render_password_field() {
        echo '<input type="password" name="bluesky_password" value="' . esc_attr(get_option('bluesky_password')) . '" />';
    }

    public function render_category_field() {
        $selected_categories = get_option('bluesky_allowed_categories', []);
        $categories = get_categories(['hide_empty' => false]);

        foreach ($categories as $category) {
            printf(
                '<label><input type="checkbox" name="bluesky_allowed_categories[]" value="%d" %s> %s</label><br>',
                esc_attr($category->term_id),
                in_array($category->term_id, $selected_categories) ? 'checked' : '',
                esc_html($category->name)
            );
        }
    }

    public function sanitize_category_selection($input) {
        return array_map('absint', $input);
    }
}

new Simple_Bluesky_Poster();
