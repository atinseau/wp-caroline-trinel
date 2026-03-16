<?php
/**
 * @see http://tgmpluginactivation.com/configuration/ for detailed documentation.
 *
 * @package    TGM-Plugin-Activation
 * @subpackage Example
 * @version    2.6.1 for parent theme Creative Minimalist for publication on WordPress.org
 * @author     Thomas Griffin, Gary Jones, Juliette Reinders Folmer
 * @copyright  Copyright (c) 2011, Thomas Griffin
 * @license    http://opensource.org/licenses/gpl-2.0.php GPL v2 or later
 * @link       https://github.com/TGMPA/TGM-Plugin-Activation
 */

require_once get_template_directory() . '/inc/tgm/class-tgm-plugin-activation.php';

add_action('tgmpa_register', 'creative_minimalist_register_required_plugins', 0);
function creative_minimalist_register_required_plugins()
{
	$plugins = array(
		array(
			'name'      => 'Superb Addons',
			'slug'      => 'superb-blocks',
			'required'  => false,
		),
	);

	$config = array(
		'id'           => 'creative-minimalist',
		'default_path' => '',
		'menu'         => 'tgmpa-install-plugins',
		'has_notices'  => true,
		'dismissable'  => true,
		'dismiss_msg'  => '',
		'is_automatic' => true,
		'message'      => '',
	);

	tgmpa($plugins, $config);
}


function creative_minimalist_pattern_styles()
{
	wp_enqueue_style('creative-minimalist-patterns', get_stylesheet_directory_uri() . '/assets/css/patterns.css', array(), filemtime(get_template_directory() . '/assets/css/patterns.css'));
	if (is_admin()) {
		global $pagenow;
		if ('site-editor.php' === $pagenow) {
			// Do not enqueue editor style in site editor
			return;
		}
		wp_enqueue_style('creative-minimalist-editor', get_stylesheet_directory_uri() . '/assets/css/editor.css', array(), filemtime(get_template_directory() . '/assets/css/editor.css'));
	}
}
add_action('enqueue_block_assets', 'creative_minimalist_pattern_styles');


add_theme_support('wp-block-styles');

// Removes the default wordpress patterns
add_action('init', function () {
	remove_theme_support('core-block-patterns');
});





// Register customer Creative Minimalist pattern categories
function creative_minimalist_register_block_pattern_categories()
{
	register_block_pattern_category(
		'header',
		array(
			'label'       => __('Header', 'creative-minimalist'),
			'description' => __('Header patterns', 'creative-minimalist'),
		)
	);
	register_block_pattern_category(
		'call_to_action',
		array(
			'label'       => __('Call To Action', 'creative-minimalist'),
			'description' => __('Call to action patterns', 'creative-minimalist'),
		)
	);
	register_block_pattern_category(
		'content',
		array(
			'label'       => __('Content', 'creative-minimalist'),
			'description' => __('Bakery and Pastry content patterns', 'creative-minimalist'),
		)
	);
	register_block_pattern_category(
		'teams',
		array(
			'label'       => __('Teams', 'creative-minimalist'),
			'description' => __('Team patterns', 'creative-minimalist'),
		)
	);
	register_block_pattern_category(
		'banners',
		array(
			'label'       => __('Banners', 'creative-minimalist'),
			'description' => __('Banner patterns', 'creative-minimalist'),
		)
	);
	register_block_pattern_category(
		'contact',
		array(
			'label'       => __('Contact', 'creative-minimalist'),
			'description' => __('Contact patterns', 'creative-minimalist'),
		)
	);
	register_block_pattern_category(
		'layouts',
		array(
			'label'       => __('Layouts', 'creative-minimalist'),
			'description' => __('layout patterns', 'creative-minimalist'),
		)
	);
	register_block_pattern_category(
		'testimonials',
		array(
			'label'       => __('Testimonial', 'creative-minimalist'),
			'description' => __('Testimonial and review patterns', 'creative-minimalist'),
		)
	);

}

add_action('init', 'creative_minimalist_register_block_pattern_categories');