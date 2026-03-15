<?php

/**
 * Plugin Name:  Bedrock Site Health Filters
 * Description:  Filters out Site Health tests that are irrelevant for a Bedrock/Docker managed WordPress installation.
 * Version:      1.0.0
 * Author:       Starter
 * License:      MIT
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Remove Site Health tests that produce false positives on Bedrock/Docker setups.
 *
 * In a Bedrock architecture:
 * - All dependencies (core, plugins, themes) are managed via Composer.
 * - The container filesystem is intentionally immutable (DISALLOW_FILE_MODS = true).
 * - Automatic background updates are disabled on purpose (AUTOMATIC_UPDATER_DISABLED = true).
 * - FTP is never used; deployments go through Docker image rebuilds.
 *
 * These tests are designed for traditional WordPress installations and report
 * critical errors that are expected and correct in a Bedrock/Docker context.
 */
add_filter('site_status_tests', function (array $tests): array {
    // "Background updates are not working as expected"
    // Reports: "All automatic updates are disabled" and "FTP credentials" prompts.
    // Irrelevant because updates are handled by Composer + redeployment.
    unset($tests['async']['background_updates']);

    // "Could not access filesystem"
    // WordPress checks if it can write to plugin/theme/core directories.
    // In a Docker container with DISALLOW_FILE_MODS, this is expected to fail.
    unset($tests['direct']['update_temp_backup_writable']);

    // "Plugin and theme auto-updates" — not applicable when file mods are disabled.
    unset($tests['direct']['plugin_theme_auto_updates']);

    // "Page cache is not detected"
    // This test looks for specific cache plugins or HTTP headers.
    // Server response time is already within threshold; caching is handled
    // at the infrastructure level (reverse proxy, CDN) rather than via WP plugins.
    unset($tests['direct']['page_cache']);

    return $tests;
});
