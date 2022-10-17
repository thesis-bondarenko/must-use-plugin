<?php
/**
 * Plugin Name: Singleton WordPress Modifications
 * Description: Custom PHP needed by the Singleton WordPress CMS.
 * Author:      Dan Bondarenko
 */

// Basic security, prevents file from being loaded directly.
defined( 'ABSPATH' ) or die();


// Utility function
function sg_web_ends_with( $haystack, $needle ) {
    $length = strlen( $needle );
    if( !$length ) {
        return true;
    }
    return substr( $haystack, -$length ) === $needle;
}

// Configure TinyMCE editor for editing pink-text-paragraph content
add_filter( 'tiny_mce_before_init', function($mceInit) {
  $mceInit['wpautop'] = false;

  $tinyMceBodyClasses = explode(' ', $mceInit['body_class']);

  $isAnyClassnameWithFullyCustomizableEnding = empty(array_filter(
    $tinyMceBodyClasses,
    fn($classname) => sg_web_ends_with($classname, "-customizable")
  ));

  if ($isAnyClassnameWithFullyCustomizableEnding) {
    unset($mceInit['toolbar1']);
    unset($mceInit['toolbar2']);
    unset($mceInit['toolbar3']);
    unset($mceInit['toolbar4']);

    $mceInit['toolbar'] = 'italic';
    $mceInit['valid_elements'] = 'p,em,br';
    $mceInit['force_root_block'] = 'p';    
  } else {
    $mceInit['force_root_block'] = 'div';
  }

  return $mceInit;
});

// Hide HTML tab from TinyMCE editor
add_filter('wp_editor_settings', function($settings) {
    $settings['quicktags'] = false;
    return $settings;
});

// Define a set of namespace-prefixed utility functions
function sg_web_array_map_and_merge($callback, $array) {
  return array_merge(...array_map(
    $callback,
    $array
  ));
}

function sg_web_get_pod_field_value($pod_name, $pod_id, $field_name) {
  $field_value = pods_field($pod_name, $pod_id, $field_name);
  $field_metadata = pods($pod_name) -> fields()[$field_name];

  if ($field_metadata['media'] === 'file' || $field_metadata['type'] === 'file' && $field_metadata['options']['file_allowed_extensions'] === 'svg') {
    if ($field_metadata['options']['file_format_type'] === 'single') {
      return file_get_contents(get_attached_file($field_value['ID']));
    }

    if ($field_metadata['options']['file_format_type'] === 'multi') {
      return array_map(fn($item) => file_get_contents(get_attached_file($item['ID'])), $field_value);
    }
  }

  if ($field_metadata['type'] === 'pick' && $field_metadata['pick_object'] === 'post_type') {
    return array_map(function($item) {
      $child_pod_name = $item['post_type'];
      $child_pod_id = $item['ID'];
      $child_pod_fields = array_keys(pods($child_pod_name) -> fields());

      return sg_web_array_map_and_merge(
        fn($child_field_name) => array($child_field_name => sg_web_get_pod_field_value($child_pod_name, $child_pod_id, $child_field_name)),
        $child_pod_fields
      );
    }, $field_value);
  }

  return $field_value;
}

function sg_web_get_setting_data($pod_name) {
  $pod_fields = array_keys(pods($pod_name) -> fields());

  return sg_web_array_map_and_merge(
    fn($field_name) => array( $field_name => sg_web_get_pod_field_value($pod_name, false, $field_name) ),
    $pod_fields
  );
}

function sg_web_get_pod_data($pod_name) {
  $pod_fields = array_keys(pods($pod_name) -> fields());
  $pod_object_ids = array_map(
    fn($pod) => $pod -> id,
    pods($pod_name) -> find(array('limit' => -1)) -> data()
  );

  return array_map(
    fn($id) => sg_web_array_map_and_merge(
      fn($field_name) => array($field_name => sg_web_get_pod_field_value($pod_name, $id, $field_name)),
      $pod_fields
    ),
    $pod_object_ids
  );
}

function sg_web_get_case($request) {
  $postType = 'case_specific';
  $posts = get_posts( array(
      'name' => $request['case'],
      'post_type' => $postType
    )
  );
  foreach( $posts as $post ) {
    $post_data = (object) array( 
        'heading' => $post->heading,
        'case_description' => $post->case_description,
        'info_table' => $post->info_table,
        'info_table' => sg_web_get_case_infotable_content($post->info_table),
        'who_knows_more' => $post->who_knows_more,
        'primary_color' => $post->primary_color,
        'secondary_color' => $post->secondary_color,
        'body_content' => sg_web_get_case_body_content($postType, $post->ID),
        'font' => $post->font,
    );
  }
  $employeeID = $post_data->who_knows_more['contact_person'];
  $post_data->who_knows_more['image'] = sg_web_get_pod_field_value('employee', $employeeID, 'image');
  $post_data->who_knows_more['full_name'] = sg_web_get_pod_field_value('employee', $employeeID, 'full_name');
  $post_data->who_knows_more['job_title'] = sg_web_get_pod_field_value('employee', $employeeID, 'job_title');
  $post_data->who_knows_more['email'] = sg_web_get_pod_field_value('employee', $employeeID, 'email');
  return $post_data; 
}

function sg_web_get_case_infotable_content($infoTable) {
  for ($i = 0; $i < count($infoTable['values']); $i++) {
    $value = $infoTable['values'][$i];
    for ($m = 0; $m < count($value['media']); $m++) {
      $mediaID = $value['media'][$m]['media'];
      if ($media !== [] && $mediaID !== null) {
        $infoTable['values'][$i]['media'][$m]['src'] = wp_get_attachment_image_url($mediaID, 'original');
      }
    }
  }
  return $infoTable;
}

function sg_web_get_case_body_content($postType, $postID) {
  $body_content_data = sg_web_get_pod_field_value($postType, $postID, 'body_content');
  foreach ($body_content_data as $data) {
    for ($i = 0; $i < count($data['content']); $i++) {
      $media = $data['content'][$i]['media'][0];
      $mediaID = $media['media'];
      
      if ($media == []) {
        unset($data['content'][$i]['media']);
      }
      else if ($mediaID !== null) {
        $media['src'] = wp_get_attachment_image_url($mediaID, 'original');
        $media['placeholder_src'] = wp_get_attachment_image_url($mediaID, 'thumbnail');
        unset($media['media']);
        $data['content'][$i]['media'] = $media;
      }
    }
    $body_content[] = $data;
  }
  return $body_content;
}


function sg_web_get_cases_list() {
  $posts_list = array();
  $posts = get_posts( array(
      'post_type' => array('case_specific'),
      'nopaging' => true
    )
  ); 
  foreach( $posts as $post ) {
    $posts_list[] = $post->post_name;
  }               
  return (object) array( 
      'cases_list' => $posts_list,
  );
}

// Add custom REST API endpoints
add_action( 'rest_api_init', function () {
  register_rest_route( 'singleton-custom-endpoint', '/site-wide-data', array(
    'methods' => 'GET',
    'callback' => fn() => array(
      'header' => sg_web_get_setting_data('header'),
      'footer' => sg_web_get_setting_data('footer'),
      'side_menu' => sg_web_get_setting_data('side_menu'),
      'privacy_notice' => sg_web_get_setting_data('privacy_notice'),
      'n_jobs' => count(sg_web_get_setting_data('careers_page')['jobs_section_jobs_shown'])
		)
  ));

  register_rest_route( 'singleton-custom-endpoint', '/landing-page', array(
    'methods' => 'GET',
    'callback' => fn() => sg_web_get_setting_data('landing_page'),
  ));

  register_rest_route( 'singleton-custom-endpoint', '/story', array(
    'methods' => 'GET',
    'callback' => fn() => sg_web_get_setting_data('story'),
  ));

  register_rest_route( 'singleton-custom-endpoint', '/team', array(
    'methods' => 'GET',
    'callback' => fn() => sg_web_get_setting_data('team'),
  ));

  register_rest_route( 'singleton-custom-endpoint', '/cases-page', array(
    'methods' => 'GET',
    'callback' => fn() => sg_web_get_setting_data('cases_page'),
  ));

  register_rest_route( 'singleton-custom-endpoint', '/contacts', array(
    'methods' => 'GET',
    'callback' => fn() => sg_web_get_setting_data('contacts'),
  ));

  register_rest_route( 'singleton-custom-endpoint', '/careers-page', array(
    'methods' => 'GET',
    'callback' => fn() => sg_web_get_setting_data('careers_page'),
  ));

  register_rest_route( 'singleton-custom-endpoint', '/page-404', array(
    'methods' => 'GET',
    'callback' => fn() => sg_web_get_setting_data('page_404'),
  ));

  register_rest_route( 'singleton-custom-endpoint', '/privacy', array(
    'methods' => 'GET',
    'callback' => fn() => sg_web_get_setting_data('privacy'),
  ));

  register_rest_route( 'singleton-custom-endpoint', '/thank-you', array(
    'methods' => 'GET',
    'callback' => fn() => sg_web_get_setting_data('thank_you'),
  ));

  register_rest_route( 'singleton-custom-endpoint', '/how-to-plan-mobile-apps', array(
    'methods' => 'GET',
    'callback' => fn() => sg_web_get_setting_data('how_to_plan_mobile_apps'),
  ));

  register_rest_route( 'singleton-custom-endpoint', '/pangea-verification-announcement', array(
    'methods' => 'GET',
    'callback' => fn() => sg_web_get_setting_data('pangea_verification_announcement'),
  ));

  register_rest_route( 'singleton-custom-endpoint', '/case-specific', array(
    'methods' => 'GET',
    'callback' => 'sg_web_get_cases_list',
  ));

  register_rest_route( 'singleton-custom-endpoint', '/case-specific/(?P<case>\S+)', array(
    'methods' => 'GET',
    'callback' => 'sg_web_get_case',
  ));
});


add_filter('the_title', function ($title, $id = null) {
  $post = get_post($id);
  $pod = pods($post -> post_type);

  if (!empty($pod)) {
    $pod_fields = array_keys($pod -> fields());

    if (in_array('title_field', $pod_fields)) {
      return pods_field($post -> post_type, $post -> ID, 'title_field');
    }

    if (in_array('full_name', $pod_fields)) {
      return pods_field($post -> post_type, $post -> ID, 'full_name');
    }
  }

  return $title;
});

define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'SCRIPT_DEBUG', true );
define( 'SAVEQUERIES', true );
