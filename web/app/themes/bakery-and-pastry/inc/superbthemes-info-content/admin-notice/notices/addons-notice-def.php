<?php
defined('ABSPATH') || exit;
if (!class_exists('TGM_Plugin_Activation') || (isset($_GET['page']) && $_GET['page'] === 'tgmpa-install-plugins')) {
    return;
}

$tgmpa = \TGM_Plugin_Activation::get_instance();
if (!isset($tgmpa->plugins['superb-blocks']) || $tgmpa->is_plugin_active('superb-blocks')) {
    return;
}

$plugin_installed = $tgmpa->is_plugin_installed('superb-blocks');
$action = $plugin_installed ? 'update' : 'install';
$nonce_url = wp_nonce_url(
    add_query_arg(
        array(
            'plugin'           => 'superb-blocks',
            'tgmpa-' . $action => $action . '-plugin',
        ),
        $tgmpa->get_tgmpa_url()
    ),
    'tgmpa-' . $action,
    'tgmpa-nonce'
);
?>
<div class="notice notice-info is-dismissible <?php echo esc_attr($notice['unique_id']); ?>">
    <h2 class="notice-title"><?php echo esc_html(__("Enable Additional WordPress Features", 'bakery-and-pastry')); ?></h2>
    <p>
        <?php echo esc_html__("Install the theme companion plugin to access all new features and customization options.", 'bakery-and-pastry'); ?>
    </p>
    <p>
        <a style='margin-bottom:15px;' class='button button-large button-primary' href="<?php echo esc_url($nonce_url); ?>"><?php echo esc_html__("Install & Activate", 'bakery-and-pastry'); ?></a>
    </p>
</div>