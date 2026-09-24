<?php
/**
 * Uninstall handler, run by WordPress when the plugin is deleted.
 *
 * Data policy (docs/DATABASE.md): plugin data is PRESERVED on uninstall.
 * Creator records are business data that future sales history and payouts
 * refer to, so deleting the plugin must never destroy them implicitly.
 * Deactivation never touches data either.
 *
 * Kept on uninstall: the {prefix}merchboard_creators table and the
 * merchboard_db_version option. Permanent deletion will be an explicit admin
 * opt-in ("Delete all data on uninstall", roadmap phase 9); until that setting
 * exists this file deliberately deletes nothing.
 *
 * This file runs without the plugin being loaded and must stay parseable on
 * PHP 7.2 (checked in CI).
 *
 * @package MerchBoard
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
