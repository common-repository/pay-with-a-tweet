<?php

/**
 * action.php
 * 
 * File that contains all the functions to use in action hooks
 * 
 * @author Rolando Caldas Sánchez <rolando.caldas@gmail.com>
 * @package Pay With a Tweet
 * @subpackage hooks
 * @version 1.0.4
 * @since 1.0
 * @copyright (c) 2013, Rolando Caldas
 * @license http://opensource.org/licenses/GPL-2.0 GPL2
 * @filesource
 */

/**
 * pwt_admin
 * 
 * Declare admin pages
 */
function pwt_admin() {

    $parent = 'pay-with-a-tweet/admin/pwt-buttons.php';
    $parent_add = 'pay-with-a-tweet/admin/pwt-create-button.php';
    $parent_upload = 'pay-with-a-tweet/admin/pwt-upload.php';
    $parent_files = 'pay-with-a-tweet/admin/pwt-files.php';
    $parent_config = 'pay-with-a-tweet/admin/pwt-config.php';

    $parent3 = 'pay-with-a-tweet/admin/pwt-async-upload.php';
    
    add_object_page(__('Pay with a Tweet', PWT_PLUGIN), __('Pay with a Tweet', PWT_PLUGIN), 0, $parent, '', PWT_PLUGIN_URL . 'img/twitter.png');

    add_submenu_page($parent, __("Manage your buttons", PWT_PLUGIN), __("Manage your buttons", PWT_PLUGIN), 0, $parent);
    add_submenu_page($parent, __('New payment button', PWT_PLUGIN), __('New payment button', PWT_PLUGIN), 0, $parent_add);
    add_submenu_page($parent, __('Manage files', PWT_PLUGIN), __('Manage files', PWT_PLUGIN), 0, $parent_files);
    add_submenu_page($parent, __('Upload files', PWT_PLUGIN), __('Upload files', PWT_PLUGIN), 0, $parent_upload);
    add_submenu_page($parent, __('Configuration', PWT_PLUGIN), __('Configuration', PWT_PLUGIN), 0, $parent_config);
    add_submenu_page(NULL, '', '', 0, $parent3);
}

/**
 * pwt_wp_enqueue_scripts
 * 
 * Enqueue plugin style-file
 */
function pwt_wp_enqueue_scripts() {
    wp_enqueue_script('pwt-script', PWT_PLUGIN_URL . '/js/pwt.js', array('jquery'));
    wp_enqueue_style('pwt-style', PWT_PLUGIN_URL . '/css/style.css');
}

function pwt_download() {
    global $wpdb;
    
    require_once( PWT_PLUGIN_PATH . '/lib/twitteroauth.php');
    
    $action = $_GET['action'];
    $id = intval($_GET['id']);
    $errors = new WP_Error();
    
    switch ($action) {

        case 'get_access':
            if (isset($_COOKIE['oauth_pid'])) {
                setcookie('oauth_token', '', -1);
                setcookie('oauth_token_secret', '', -1);
                setcookie('oauth_pid', '', -1);                
            }

            setcookie('oauth_pid', $id);

            $consumerkey = get_option(PWT_PLUGIN . '_twitter_consumerkey', '');
            $consumersecret = get_option(PWT_PLUGIN . '_twitter_consumersecret', '');

            /* Build TwitterOAuth object with client credentials. */
            $connection = new TwitterOAuth($consumerkey, $consumersecret);

            /* Get temporary credentials. */
            $request_token = $connection->getRequestToken(get_option('siteurl') . '/' . PWT_PLUGIN . '/download/?id=' . $id . '&action=callback');

            /* Save temporary credentials to session. */
            setcookie('oauth_token', $request_token['oauth_token']);
            setcookie('oauth_token_secret', $request_token['oauth_token_secret']);
            $token = $request_token['oauth_token'];
            /* If last connection failed don't display authorization link. */
            if ($connection->http_code == 200) {
                /* Build authorize URL and redirect user to Twitter. */
                $url = $connection->getAuthorizeURL($token);
                header('Location: ' . $url);
                exit;
            } else {

                $GLOBALS['pwt_error'] = $connection;
                add_filter('404_template', 'pwt_download_404_template');    
                apply_filters('template_include', get_404_template());
                exit;
            }

            break;

        case 'callback' :

            if ($_COOKIE['oauth_pid'] != $_GET['id']) {

                exit;
            }

            /* If the oauth_token is old redirect to the connect page. */
            if (isset($_REQUEST['oauth_token']) && $_COOKIE['oauth_token'] !== $_REQUEST['oauth_token']) {
                setcookie('oauth_token', '', -1);
                setcookie('oauth_token_secret', '', -1);
                setcookie('oauth_pid', '', -1);
            
                define('WP_USE_THEMES', true);
                add_filter('404_template', 'pwt_download_404_template');
                apply_filters('template_include', get_404_template());
                exit;
            }
            
            setcookie('oauth_verifier', $_REQUEST['oauth_verifier']);
            
            wp_enqueue_style('pwt-style', PWT_PLUGIN_URL . '/css/style.css');
            add_action('wp_head', 'pwt_download_page_head');
            add_filter('page_template', 'pwt_download_page_template');
            apply_filters('template_include', get_page_template());
            
            break;
            
        case 'download' :
            
            add_filter('page_template', 'pwt_download_page_template');    

            $button = $wpdb->get_results("SELECT * FROM " . $wpdb->prefix . "pwt_button WHERE id='" . intval($_GET['id']) . "'");


            $consumerkey = get_option(PWT_PLUGIN . '_twitter_consumerkey', '');
            $consumersecret = get_option(PWT_PLUGIN . '_twitter_consumersecret', '');

            /* Create TwitteroAuth object with app key/secret and token key/secret from default phase */
            $connection = new TwitterOAuth($consumerkey, $consumersecret, $_COOKIE['oauth_token'], $_COOKIE['oauth_token_secret']);


            /* Request access tokens from twitter */
            $access_token = $connection->getAccessToken($_COOKIE['oauth_verifier']);


            $connection2 = new TwitterOAuth($consumerkey, $consumersecret, $access_token['oauth_token'], $access_token['oauth_token_secret']);
            $twitter_user = $connection2->get('account/verify_credentials');

            $status = $connection2->post('statuses/update', array('status' => $button[0]->message));

            /* Remove no longer needed request tokens */
            setcookie('oauth_token', '', -1);
            setcookie('oauth_token_secret', '', -1);
            setcookie('oauth_pid', '', -1);
            setcookie('oauth_verifier', '', -1);

            /* If HTTP response is 200 continue otherwise send to connect page to retry */
            if ($connection2->http_code == 200) {
                $wpdb->insert(
                        $wpdb->prefix . "pwt_button_stats", array(
                    'bid' => intval($_GET['id']),
                    'time' => current_time('mysql'),
                    'name' => '@' . $row['screen_name']
                        ), array(
                    '%d',
                    '%s',
                    '%s'
                        )
                );

                $mime = 'application/force-download';
                if (class_exists('finfo') && version_compare(PHP_VERSION, '5.3.0') >= 0) {
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime = $finfo->file(PWT_PLUGIN_UPLOAD_DIR . '/' . $button[0]->download);
                }
                $download = file_get_contents(PWT_PLUGIN_UPLOAD_DIR . '/' . $button[0]->download);

                nocache_headers();

                header('Content-type: ' . $mime);
                header("Content-Description: File Transfer");
                header("Content-Length: " . filesize(PWT_PLUGIN_UPLOAD_DIR . '/' . $button[0]->download));
                header('Content-Disposition: attachment; filename="' . $button[0]->download . '"');

                print $download;
                exit;
            } else {
                $GLOBALS['pwt_error'] = $connection2;
                add_filter('404_template', 'pwt_download_404_template');
                apply_filters('template_include', get_404_template());
                exit;
            }
            break;
    }
}

/**
 * pwt_admin_init
 * 
 * Admin init actions
 */
function pwt_admin_init() {

    if ($_SERVER['REQUEST_URI'] == '/wp-admin/admin.php?page=pay-with-a-tweet/admin/pwt-async-upload.php') {
        ob_start();
    }

    add_action('admin_enqueue_scripts', 'pwt_wp_enqueue_scripts');
}

/**
 * pwt_plugin_init
 * 
 * Register plugin actions
 */
function pwt_plugin_init() {
   
     $url = '';
    if (isset($_SERVER['HTTPS']) && !empty($_SERVER['HTTPS'])) {
        $url .= 'https://';
    } else {
        $url .= 'http://';
    }
    $url .= $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    $clean_url = preg_replace('#^' . get_site_url() . '(.*)$#si', '$1', $url);
    $request_uri = explode('?', str_replace('//', '/', $clean_url));
    unset ($url, $clean_url);

    if ($request_uri[0] == '/pay-with-a-tweet/download/' || $request_uri[0] == '/pay-with-a-tweet/download') {
        pwt_download();
        exit;
    }
    
    add_action('admin_menu', 'pwt_admin');
    add_action('admin_init', 'pwt_admin_init');
}


function pwt_download_page_head() {
    echo '<meta http-equiv="refresh" content="5;url=' . get_option('siteurl') . '/' . PWT_PLUGIN . '/download/?id=' . $_GET['id'] . '&action=download" />';
}
