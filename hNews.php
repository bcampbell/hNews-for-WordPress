<?php

/*
Plugin Name: hNews
Plugin URI: http://valueaddednews.org
Description:
Author: Matt Harris
Version: 1.0
Author URI: http://themattharris.com
*/

class hNews {
  var $supported_fields_main = array(
    'principles_url' => 'Principles URL',
    'license_url'    => 'License URL',
    'license_text'   => 'License Name',
  );

  var $supported_fields_org = array(
    'org_name'         => 'Organization Name',
    'org_unit'         => 'Organization Unit',
    'email'            => 'Email',
    'url'              => 'URL',
    'tel'              => 'Phone',
    'post_office_box'  => 'PO Box',
    'extended_address' => 'Apartment/Suite Number',
    'street_address'   => 'Street Address',
    'locality'         => 'City/Town',
    'region'           => 'State/County',
    'postal_code'      => 'Zip/Postal Code',
    'country_name'     => 'Country',
  );

  /**
   * Register our actions with the WordPress hooks
   */
  function __construct() {
    add_action('admin_init', array($this, 'admin_init'));
    add_action('admin_menu', array($this, 'admin_menu'));
    add_action('save_post', array($this, 'save_post'), 10, 2);

    // add the custom css to style our boxes
    add_action('admin_head-post.php', array($this, 'add_css'));
    add_action('admin_head-post-new.php', array($this, 'add_css'));

    // add the custom js required for our boxes
    add_action('admin_print_scripts-post.php', array($this, 'add_js'));
    add_action('admin_print_scripts-post-new.php', array($this, 'add_js'));

    // This filter needs adding to WordPress core. line 395 wp-admin/includes/post.php
    // add_filter('post_to_edit', array($this, 'post_to_edit'));

    add_filter('posts_results', array($this, 'posts_results'));
  }

  /**
   * Magic function for handling the renders, Note &$return is missing for 
   * PHP4 as in this class we don't need it
   */
  function __call($method, $arguments) {
    if (strstr($method, 'render_'))
      $this->render($method);
    else
      trigger_error('Call to undefined method ' . __CLASS__ . '::' . $method . '()', E_USER_ERROR);
  }

  function render($what) {
    $options = get_option('hnews_options');
    $var = str_replace('render_', '', $what);

    $class = (strstr($var, 'url') || strstr($var, 'email')) ? 'class="code"' : '';
    echo "<input id='$var' name='hnews_options[$var]' size='70' type='text' value='{$options[$var]}'$class />";
  }

  /**
   * Register the hNews Settings
   */
  function admin_init() {
    register_setting('hnews_options', 'hnews_options', array($this, 'options_validate'));
    add_settings_section('hnews_settings_main', __('Main Settings'), array($this, 'render_main_section_text'), 'hnews_page');
    foreach ($this->supported_fields_main as $k => $v) {
      add_settings_field($k, __($v), array($this, "render_$k"), 'hnews_page', 'hnews_settings_main');
    }

    add_settings_section('hnews_settings_org', __('Source Organisation'), array($this, 'render_org_section_text'), 'hnews_page');
    foreach ($this->supported_fields_org as $k => $v) {
      add_settings_field($k, __($v), array($this, "render_$k"), 'hnews_page', 'hnews_settings_org');
    }
  }

  /**
   * Validation and sanitisation for the hNews options
   */
  function options_validate($fields) {
    foreach ($fields as $k => &$v) {
      $v = stripslashes(wp_filter_post_kses(addslashes(trim($v)))); // wp_filter_post_kses() expects slashed
    }
    return $fields;
  }

  /**
   * The text to display in the header of the options section
   */
  function render_main_section_text() {
    echo '<p>'.__('The URLs and labels you enter here will be used as the default value when adding a new post.').'</p>';
  }
  function render_org_section_text() {
    echo '<p>'.__('The source organisation you enter here will be used as the default organisation when adding a new post.').'</p>';
  }

  /**
   * Register the meta boxes and admin options
   */
  function admin_menu() {
    add_meta_box('hnews_main', __('hNews'), array($this, 'meta_box_main'), 'post', 'normal', 'high');
    add_meta_box('hnews_geo', __('Geolocation'), array($this, 'meta_box_geo'), 'post', 'side', 'high');

    add_options_page('hNews Defaults', 'hNews', 'manage_options', 'hNews', array($this, 'hnews_options_page'));
  }

  /**
   * Process the post meta on save
   *
   * @param string $post_ID the ID of the post to be updated or that was just saved
   * @param string $post the post object
   */
  function save_post($post_ID, $post) {
    // url defaults are read from wp_options
    $defaults = array(
      'geo_latitude'         => 0,
      'geo_longitude'        => 0,
    );

    foreach ($this->supported_fields_main + $this->supported_fields_org as $k => $v) {
      $defaults["hnews_$k"] = '';
    }

    // parse the args through WordPress parsing function and sanitize
    $postarr = wp_parse_args($_POST, $defaults);
    $postarr = sanitize_post($postarr, 'db');
    // drop fields we don't want
    $postarr = array_intersect_key($postarr, $defaults);

    // only save the fields which are different. This also catches any instances
    // where the hnews fields are not added to a page or custom post type (WP 3)
    $diffs = array_diff($postarr, $defaults);

    // save to the database, renaming all keys from 'key' to '_key'
    foreach ($diffs as $k => $v) {
      add_post_meta($post_ID, "_$k", $v, true) or update_post_meta($post_ID, "_$k", $v);
    }
  }

  /**
   * Add the custom CSS we need to render our meta boxes
   */
  function add_css() {
    require 'hnews_css.php';
  }

  /**
   * Add the custom Javascript we need to render our meta boxes and register
   * our hNews javascript file.
   */
  function add_js() {
    require 'hnews_js.php';
    wp_enqueue_script('hNews', '/'.PLUGINDIR.'/hNews/hnews.js');
  }

  /**
   * The main hNews meta box rendering function
   */
  function meta_box_main($post) {
    // fallback for when the filter for post_to_edit isn't present
    if ( ! isset($post->hnews_principles_url)) {
      $this->post_to_edit($post);
    }
    $options = get_option('hnews_options');
    foreach ($this->supported_fields_main + $this->supported_fields_org as $k => $v) {
      if (empty($post->{"hnews_$k"}) && empty($post->ID))
        $$k = $options[$k];
      elseif ( ! empty($post->{"hnews_$k"})) {
        $$k = $post->{"hnews_$k"};
      }
    }
    require 'box_main.php';
  }

  /**
   * The hNews Geolocation meta box rendering function
   */
  function meta_box_geo($post) {
    // fallback for when the filter for post_to_edit isn't present
    if ( ! isset($post->geo_latitude)) {
      $this->post_to_edit($post);
    }
    require 'box_geo.php';
  }

  /**
   * Add our extra fields to the post being edited
   */
  function post_to_edit($post) {
    $id = $post->ID;
    $post->geo_latitude         = get_post_meta($id, '_geo_latitude', true );
    $post->geo_longitude        = get_post_meta($id, '_geo_longitude', true );
    foreach ($this->supported_fields_main + $this->supported_fields_org as $k => $v) {
      $post->{"hnews_$k"} = get_post_meta($id, "_hnews_$k", true );
    }
    return $post;
  }

  /**
   * Process all post results and add the custom fields
   */
  function posts_results($posts) {
    foreach ($posts as &$post) {
      $post = $this->post_to_edit($post);
    }
    return $posts;
  }

  /**
   * The hNews admin options rendering function
   */
  function hnews_options_page() {
    require 'admin_options.php';
  }
}

include 'template_functions.php';

new hNews();

?>