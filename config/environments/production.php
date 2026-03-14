<?php

/**
 * Configuration overrides for WP_ENV === 'production'
 */

use Roots\WPConfig\Config;

Config::define('WP_DEBUG', false);
Config::define('DISALLOW_INDEXING', false);
