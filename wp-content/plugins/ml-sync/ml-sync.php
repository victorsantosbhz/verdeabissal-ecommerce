<?php
/**
 * Plugin Name: Mercado Livre Sync for WooCommerce
 * Description: Sincronização de produtos, estoque e vendas entre WooCommerce e Mercado Livre.
 * Version: 1.0.0
 * Author: VerdeAbissal
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ML_Sync {
    private $api_url = 'https://api.mercadolibre.com';
    private $option_name = 'ml_sync_settings';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        
        // Hooks para sincronização (WC -> ML)
        add_action('woocommerce_update_product', [$this, 'sync_product_to_ml'], 10, 1);
        add_action('woocommerce_product_set_stock', [$this, 'sync_stock_to_ml'], 10, 1);
    }

    public function add_admin_menu() {
        add_menu_page(
            'ML Sync',
            'ML Sync',
            'manage_options',
            'ml-sync',
            [$this, 'admin_page_contents'],
            'dashicons-update',
            56
        );
    }

    public function admin_page_contents() {
        $settings = get_option($this->option_name);
        ?>
        <div class="wrap">
            <h1>Configuração Mercado Livre Sync</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('ml_sync_group');
                do_settings_sections('ml-sync');
                submit_button();
                ?>
            </form>
            
            <hr>
            <h2>Autenticação</h2>
            <?php if (empty($settings['access_token'])): ?>
                <a href="<?php echo $this->get_auth_url(); ?>" class="button button-primary">Conectar ao Mercado Livre</a>
            <?php else: ?>
                <p style="color: green;">Conectado com sucesso!</p>
                <button id="import-ml-products" class="button">Importar Produtos do ML</button>
            <?php endif; ?>
        </div>
        <?php
    }

    private function get_auth_url() {
        $client_id = get_option('ml_client_id');
        $redirect_uri = get_option('ml_redirect_uri');
        return "https://auth.mercadolivre.com.br/authorization?response_type=code&client_id={$client_id}&redirect_uri={$redirect_uri}";
    }

    public function register_rest_routes() {
        register_rest_route('ml-sync/v1', '/auth/callback', [
            'methods' => 'GET',
            'callback' => [$this, 'handle_auth_callback'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route('ml-sync/v1', '/notifications', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_ml_notifications'],
            'permission_callback' => '__return_true'
        ]);
    }

    public function handle_auth_callback($request) {
        $code = $request->get_param('code');
        // Lógica para trocar o code por access_token via cURL
        return new WP_REST_Response(['message' => 'Autenticação recebida. Implementar troca de token.'], 200);
    }

    public function handle_ml_notifications($request) {
        $data = $request->get_json_params();
        error_log('ML Notification: ' . print_r($data, true));
        // Lógica para processar venda ou atualização de estoque do ML -> WC
        return new WP_REST_Response(['status' => 'ok'], 200);
    }

    public function sync_product_to_ml($product_id) {
        // Lógica para atualizar preço/dados no ML quando o produto mudar no WC
    }

    public function sync_stock_to_ml($product) {
        // Lógica para atualizar estoque no ML quando o estoque mudar no WC
    }
}

new ML_Sync();
