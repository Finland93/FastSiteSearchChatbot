<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

define('FSSC_OPT_FILE', 'fssc_dataset_file');
define('FSSC_OPT_SIG',  'fssc_content_sig');

// Remove options
delete_option('fssc_position');
delete_option('fssc_exclude_ids');
delete_option('fssc_exclude_cats');
delete_option('fssc_exclude_tags');
delete_option(FSSC_OPT_FILE);
delete_option(FSSC_OPT_SIG);

// Unschedule cron if present
$hook = 'fssc_daily_rebuild_event';
$ts = wp_next_scheduled($hook);
if ($ts) wp_unschedule_event($ts, $hook);

// Remove dataset directory & files
$up = wp_upload_dir(null, false);
$base = trailingslashit($up['basedir']) . 'fssc-dataset';
if (is_dir($base)) {
  $files = @scandir($base);
  if (is_array($files)) {
    foreach ($files as $f) {
      if ($f === '.' || $f === '..') continue;
      @unlink($base . '/' . $f);
    }
  }
  @rmdir($base);
}
