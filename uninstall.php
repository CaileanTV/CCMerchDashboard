<?php
/**
 * Uninstall handler, run by WordPress when the plugin is deleted.
 *
 * The plugin stores no data yet, so there is nothing to remove. When later
 * phases add tables, options or roles, their removal goes here and must only
 * happen when the "Delete all data on uninstall" setting is enabled
 * (see docs/DATABASE.md). This file runs without the plugin being loaded.
 *
 * @package MerchBoard
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
