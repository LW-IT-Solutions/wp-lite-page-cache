<?php

/**
 * Plugin Name: Lite Page Cache
 * Description: A lightweight, blazingly fast caching plugin for static pages and blog posts.
 * Version: 1.0.1
 * Author: LukasWojcik.com
 * Text Domain: lite-page-cache
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class Lite_Page_Cache {
    private $cache_dir;

    public function __construct() {
        // Define cache directory inside wp-content/cache
        $this->cache_dir = WP_CONTENT_DIR . '/cache/lite-page-cache/';

        // Front-end hooks
        add_action( 'template_redirect', [ $this, 'serve_or_start_cache' ], 0 );

        // Back-end hooks
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_post_lpc_clear_cache', [ $this, 'clear_cache_action' ] );
        add_action( 'admin_notices', [ $this, 'admin_notices' ] );
        
        // Auto-clear cache on post/page updates and deletions
        add_action( 'save_post', [ $this, 'delete_cache_files' ] );
        add_action( 'deleted_post', [ $this, 'delete_cache_files' ] );

        // Auto-clear cache on comment approval
        add_action( 'comment_post', [ $this, 'flush_on_new_comment' ], 10, 2 );
        add_action( 'transition_comment_status', [ $this, 'flush_on_comment_status_change' ], 10, 3 );
    }

    /**
     * Check if page should be cached. If cached, serve it. If not, start output buffering.
     */
    public function serve_or_start_cache() {
        // Do not cache if user is logged in, it's not a singular post/page, or it's a GET request with parameters
        if ( is_user_logged_in() || ! empty( $_GET ) || $_SERVER['REQUEST_METHOD'] !== 'GET' ) {
            return;
        }

        $cache_file = $this->get_cache_file_path();

        // Serve cache if it exists
        if ( file_exists( $cache_file ) ) {
			$x = file_get_contents($cache_file);
			if(strlen($x) < 300) { unset($cache_file); return; }
            readfile( $cache_file );
            echo "\n<!-- Served from Lite Page Cache -->";
            exit;
        }

        // Start buffering to create cache
        ob_start( [ $this, 'save_cache' ] );
    }

    /**
     * Output buffering callback to save the generated HTML.
     */
    public function save_cache( $buffer ) {
        if ( ! file_exists( $this->cache_dir ) ) {
            wp_mkdir_p( $this->cache_dir );
        }

        $cache_file = $this->get_cache_file_path();
        
        // Save the buffer to a file
		if(strlen($buffer) < 300) { return $buffer; }
        file_put_contents( $cache_file, $buffer . "\n<!-- LukasWojcik.com -->\n<!-- Cached by Lite Page Cache at " . current_time('mysql') . "-->" );
        
        return $buffer;
    }

    /**
     * Generate unique filename based on the requested URL.
     */
    private function get_cache_file_path() {
        $url  = ( isset( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] === 'on' ? "https" : "http" ) . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        $hash = md5( $url );
        return $this->cache_dir . $hash . '.html';
    }

    /**
     * Add settings page to the Settings menu.
     */
    public function add_admin_menu() {
        add_options_page( 
            'Lite Page Cache', 
            'Lite Page Cache', 
            'manage_options', 
            'lite-page-cache', 
            [ $this, 'settings_page' ] 
        );
    }

    /**
     * Render the admin settings page.
     */
    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>Lite Page Cache</h1>
            <p>This plugin caches static pages and blog posts to HTML files to improve loading speed.</p>
            
            <div class="card" style="max-width: 600px; margin-top: 20px; padding: 20px;">
                <h2>Cache Management</h2>
                <p>Use the button below to manually flush all cached files.</p>
                <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
                    <input type="hidden" name="action" value="lpc_clear_cache">
                    <?php wp_nonce_field( 'lpc_clear_cache_nonce', 'lpc_nonce' ); ?>
                    <?php submit_button( 'Clear All Cache', 'primary' ); ?>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Handle the clear cache request.
     */
    public function clear_cache_action() {
        if ( ! isset( $_POST['lpc_nonce'] ) || ! wp_verify_nonce( $_POST['lpc_nonce'], 'lpc_clear_cache_nonce' ) || ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized request.' );
        }

        $this->delete_cache_files();

        wp_redirect( add_query_arg( [ 'page' => 'lite-page-cache', 'cleared' => 'true' ], admin_url( 'options-general.php' ) ) );
        exit;
    }

    /**
     * Flush cache when a new comment is added and immediately approved.
     */
    public function flush_on_new_comment( $comment_ID, $comment_approved ) {
        // $comment_approved can be 1 (approved), 0 (pending), or 'spam'
        if ( $comment_approved === 1 || $comment_approved === '1' ) {
            $this->delete_cache_files();
        }
    }

    /**
     * Flush cache when a comment's status changes (e.g., approved from moderation).
     */
    public function flush_on_comment_status_change( $new_status, $old_status, $comment ) {
        if ( $new_status === 'approved' ) {
            $this->delete_cache_files();
        }
    }

    /**
     * Delete all generated HTML cache files.
     */
    public function delete_cache_files() {
        if ( file_exists( $this->cache_dir ) ) {
            $files = glob( $this->cache_dir . '*.html' );
            if ( $files ) {
                array_map( 'unlink', $files );
            }
        }
    }

    /**
     * Show success notice after clearing cache.
     */
    public function admin_notices() {
        if ( isset( $_GET['cleared'] ) && $_GET['cleared'] === 'true' ) {
            echo '<div class="notice notice-success is-dismissible"><p><strong>Lite Page Cache:</strong> All cached files have been successfully cleared.</p></div>';
        }
    }
}

// Initialize the plugin
new Lite_Page_Cache();
