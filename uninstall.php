<?php
/**
 * Uninstall Fast Site Search Chatbot
 */
if ( ! defined('WP_UNINSTALL_PLUGIN') ) {
  exit;
}

/**
 * Option keys (keep in sync with register_settings + dataset meta)
 */
$FSSC_OPTION_KEYS = [
  'fssc_position',
  'fssc_topk',
  'fssc_exclude_ids',
  'fssc_exclude_cats',
  'fssc_exclude_tags',
  'fssc_disable_pages',
  'fssc_color_bg',
  'fssc_color_fg',
  'fssc_dataset_file',
  'fssc_content_sig',
];

/**
 * Recursively delete a directory (files + subdirs), then remove the directory.
 */
function fssc_rmdir_recursive($dir) {
  if (!is_dir($dir)) return;
  $items = scandir($dir);
  if (!is_array($items)) return;
  foreach ($items as $item) {
    if ($item === '.' || $item === '..') continue;
    $path = $dir . DIRECTORY_SEPARATOR . $item;
    if (is_dir($path)) {
      fssc_rmdir_recursive($path);
    } else {
      @chmod($path, 0644);
      @unlink($path);
    }
  }
  @rmdir($dir);
}

/**
 * Remove all plugin data for the current site (options, cron, transients, files).
 */
function fssc_cleanup_current_site($FSSC_OPTION_KEYS) {
  // 1) Delete options in this site
  foreach ($FSSC_OPTION_KEYS as $key) {
    delete_option($key);
    delete_site_option($key); // harmless on single-site; ensures network-level cleanup
  }

  // 2) Clear ALL scheduled instances of our cron hook
  if (function_exists('wp_clear_scheduled_hook')) {
    wp_clear_scheduled_hook('fssc_daily_rebuild_event');
  } else {
    // Fallback (very old WP)
    $ts = wp_next_scheduled('fssc_daily_rebuild_event');
    if ($ts) wp_unschedule_event($ts, 'fssc_daily_rebuild_event');
  }

  // 3) Purge rate-limit transients for this site
  global $wpdb;
  if (isset($wpdb)) {
    // Transient option names look like:
    // _transient_fssc_rl_min_<hash>, _transient_timeout_fssc_rl_min_<hash>, same for _hour_
    $like_min   = $wpdb->esc_like('_transient_fssc_rl_min_') . '%';
    $like_min_t = $wpdb->esc_like('_transient_timeout_fssc_rl_min_') . '%';
    $like_hr    = $wpdb->esc_like('_transient_fssc_rl_hour_') . '%';
    $like_hr_t  = $wpdb->esc_like('_transient_timeout_fssc_rl_hour_') . '%';

    // Delete both transient values and their timeouts
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_min   ) );
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_min_t ) );
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_hr    ) );
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_hr_t  ) );
  }

  // 4) Remove dataset directory & files for this site
  $up = wp_upload_dir(null, false);
  if (!empty($up['basedir'])) {
    $base = trailingslashit($up['basedir']) . 'fssc-dataset';
    if (is_dir($base)) {
      fssc_rmdir_recursive($base);
    }
  }
}

/**
 * Single-site vs. Multisite handling
 */
if ( is_multisite() ) {
  // Network: run cleanup for EACH site so per-blog options/transients/files are removed.
  $sites = get_sites(['fields' => 'ids']);
  if (is_array($sites)) {
    $current_blog_id = get_current_blog_id();
    foreach ($sites as $blog_id) {
      switch_to_blog($blog_id);
      fssc_cleanup_current_site($FSSC_OPTION_KEYS);
      restore_current_blog();
    }
    // Also clean on the current site (in case not covered)
    switch_to_blog($current_blog_id);
    fssc_cleanup_current_site($FSSC_OPTION_KEYS);
    restore_current_blog();
  } else {
    // Fallback: at least clean current site
    fssc_cleanup_current_site($FSSC_OPTION_KEYS);
  }
} else {
  // Single-site
  fssc_cleanup_current_site($FSSC_OPTION_KEYS);
}
