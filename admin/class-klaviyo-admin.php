<?php
class Klaviyo_Admin {
    public function init() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'settings_init'));
    }

    public function add_admin_menu() {
        add_options_page('Klaviyo Integration', 'Klaviyo Integration', 'manage_options', 'klaviyo_integration', array($this, 'options_page'));
    }

    public function settings_init() {
        register_setting('klaviyo_integration', 'klaviyo_public_api_key');

        add_settings_section(
            'klaviyo_integration_section',
            __('Klaviyo Integration Settings', 'my-klaviyo-woocommerce-plugin'),
            array($this, 'settings_section_callback'),
            'klaviyo_integration'
        );

        add_settings_field(
            'klaviyo_public_api_key',
            __('Klaviyo Public API Key', 'my-klaviyo-woocommerce-plugin'),
            array($this, 'public_api_key_render'),
            'klaviyo_integration',
            'klaviyo_integration_section'
        );
    }

    public function public_api_key_render() {
        $value = get_option('klaviyo_public_api_key', '');
        echo '<input type="text" name="klaviyo_public_api_key" value="' . esc_attr($value) . '">';
    }

    public function settings_section_callback() {
        echo __('Enter your Klaviyo Public API Key here.', 'my-klaviyo-woocommerce-plugin');
    }

    public function options_page() {
        ?>
        <form action="options.php" method="post">
            <h2>Klaviyo Integration</h2>
            <?php
            settings_fields('klaviyo_integration');
            do_settings_sections('klaviyo_integration');
            submit_button();
            ?>
        </form>
        <?php
    }
}
