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
    private $history_table_version = '1.0';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'handle_actions']);
        add_action('admin_init', [$this, 'maybe_install_history_table']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        
        // Hooks para sincronização (WC -> ML)
        add_action('woocommerce_update_product', [$this, 'sync_product_to_ml'], 10, 1);
        add_action('woocommerce_product_set_stock', [$this, 'sync_stock_to_ml'], 10, 1);
        add_action('transition_post_status', [$this, 'handle_product_status_change'], 10, 3);

        // Hook do Action Scheduler para processar um único item em background
        add_action('ml_sync_import_single_item', [$this, 'process_single_import_item'], 10, 1);
        add_action('ml_sync_order_single', [$this, 'process_single_order_sync'], 10, 1);
        
        // Cron Job de Pedidos
        add_action('ml_sync_orders_cron', [$this, 'sync_recent_ml_orders']);
        add_action('ml_sync_fees_cron', [$this, 'sync_pending_fees_orders']);
        add_action('init', [$this, 'schedule_cron_jobs']);

        // Ações manuais de pedidos
        add_action('wp_ajax_sync_ml_product', [$this, 'ajax_sync_ml_product']);
        add_filter('woocommerce_order_actions', [$this, 'add_ml_sync_order_action'], 10, 2);
        add_action('woocommerce_order_action_sync_ml_order', [$this, 'process_ml_sync_order_action']);

        // Registrar status customizado "Enviado"
        add_action('init', [$this, 'register_shipped_order_status']);
        add_filter('wc_order_statuses', [$this, 'add_shipped_to_order_statuses']);
        
        // Registrar atributo Marca (pa_marca) no WooCommerce
        add_action('init', [$this, 'ensure_brand_attribute_registered'], 5);

        // Hooks para Categoria
        add_action('add_meta_boxes', [$this, 'add_product_meta_box']);
        add_action('save_post_product', [$this, 'save_product_meta_box']);
        add_action('wp_ajax_ml_predict_category', [$this, 'ml_predict_category_ajax']);
    }

    public function add_product_meta_box() {
        add_meta_box(
            'ml_sync_meta_box',
            'Mercado Livre - Categoria',
            [$this, 'render_product_meta_box'],
            'product',
            'side',
            'default'
        );
    }

    public function render_product_meta_box($post) {
        $ml_category_id = get_post_meta($post->ID, '_ml_category_id', true);
        $ml_category_name = get_post_meta($post->ID, '_ml_category_name', true);
        
        wp_nonce_field('ml_sync_save_meta', 'ml_sync_meta_nonce');
        ?>
        <div style="padding: 10px 0;">
            <p><strong>ID da Categoria (ML):</strong></p>
            <input type="text" id="_ml_category_id" name="_ml_category_id" value="<?php echo esc_attr($ml_category_id); ?>" style="width:100%;" />
            
            <p><strong>Nome da Categoria (ML):</strong></p>
            <input type="text" id="_ml_category_name" name="_ml_category_name" value="<?php echo esc_attr($ml_category_name); ?>" readonly style="width:100%; background:#f0f0f1;" />
            
            <p style="margin-top: 15px;">
                <button type="button" class="button button-secondary" id="ml-predict-category-btn">Sugerir Categoria</button>
                <span class="spinner" id="ml-predict-spinner"></span>
            </p>
            <p class="description">Se vazio, usará a Categoria Padrão Global configurada nas definições do ML Sync ao criar o produto.</p>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                var btn = document.getElementById('ml-predict-category-btn');
                var spinner = document.getElementById('ml-predict-spinner');
                if (btn) {
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        var title = document.getElementById('title') ? document.getElementById('title').value : '';
                        if (!title) {
                            alert('Preencha o título do produto primeiro para sugerir uma categoria.');
                            return;
                        }
                        
                        spinner.classList.add('is-active');
                        btn.disabled = true;

                        var data = new FormData();
                        data.append('action', 'ml_predict_category');
                        data.append('title', title);
                        data.append('security', '<?php echo wp_create_nonce("ml_predict_nonce"); ?>');

                        fetch(ajaxurl, {
                            method: 'POST',
                            body: data
                        })
                        .then(res => res.json())
                        .then(res => {
                            spinner.classList.remove('is-active');
                            btn.disabled = false;
                            
                            if (res.success && res.data) {
                                document.getElementById('_ml_category_id').value = res.data.id;
                                document.getElementById('_ml_category_name').value = res.data.name;
                            } else {
                                alert('Erro: ' + (res.data ? res.data : 'Categoria não encontrada.'));
                            }
                        })
                        .catch(err => {
                            spinner.classList.remove('is-active');
                            btn.disabled = false;
                            alert('Erro na requisição.');
                        });
                    });
                }
            });
        </script>
        <?php
    }

    public function save_product_meta_box($post_id) {
        if (!isset($_POST['ml_sync_meta_nonce']) || !wp_verify_nonce($_POST['ml_sync_meta_nonce'], 'ml_sync_save_meta')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        if (isset($_POST['_ml_category_id'])) {
            update_post_meta($post_id, '_ml_category_id', sanitize_text_field($_POST['_ml_category_id']));
        }
        if (isset($_POST['_ml_category_name'])) {
            update_post_meta($post_id, '_ml_category_name', sanitize_text_field($_POST['_ml_category_name']));
        }
    }

    public function ml_predict_category_ajax() {
        check_ajax_referer('ml_predict_nonce', 'security');

        $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
        if (empty($title)) {
            wp_send_json_error('Título vazio.');
        }

        $response = $this->request_ml('GET', '/sites/MLB/category_predictor/predict?title=' . urlencode($title));

        if (is_wp_error($response)) {
            wp_send_json_error('Erro na API: ' . $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!empty($body['id']) && !empty($body['name'])) {
            wp_send_json_success([
                'id' => $body['id'],
                'name' => $body['name']
            ]);
        } else {
            wp_send_json_error('Não foi possível prever a categoria.');
        }
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

    // --- HISTÓRICO DE SINCRONIZAÇÕES ---

    public function maybe_install_history_table() {
        if (get_option('ml_sync_history_table_version') === $this->history_table_version) {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'ml_sync_history';
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            direction VARCHAR(20) NOT NULL,
            action VARCHAR(50) NOT NULL,
            wc_product_id BIGINT UNSIGNED DEFAULT NULL,
            ml_item_id VARCHAR(50) DEFAULT NULL,
            wc_order_id BIGINT UNSIGNED DEFAULT NULL,
            ml_order_id VARCHAR(50) DEFAULT NULL,
            status VARCHAR(20) NOT NULL,
            message TEXT NULL,
            payload LONGTEXT NULL,
            PRIMARY KEY  (id),
            KEY idx_created_at (created_at),
            KEY idx_wc_product (wc_product_id),
            KEY idx_ml_item (ml_item_id),
            KEY idx_action (action)
        ) {$charset};";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        update_option('ml_sync_history_table_version', $this->history_table_version);
    }

    /**
     * Registra um evento de sincronização na tabela de histórico.
     * $direction: 'wc_to_ml' | 'ml_to_wc'
     * $action: product_create | product_update | product_pictures | stock_update | status_update |
     *          order_create | order_update | order_fees | order_address
     * $status: success | error | skipped
     */
    private function log_sync($direction, $action, $status, $message = '', $context = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'ml_sync_history';
        $payload = !empty($context['payload']) ? wp_json_encode($context['payload']) : null;
        if ($payload && strlen($payload) > 60000) {
            $payload = substr($payload, 0, 60000) . '...[truncado]';
        }
        $wpdb->insert($table, [
            'created_at'    => current_time('mysql'),
            'direction'     => $direction,
            'action'        => $action,
            'wc_product_id' => $context['wc_product_id'] ?? null,
            'ml_item_id'    => $context['ml_item_id'] ?? null,
            'wc_order_id'   => $context['wc_order_id'] ?? null,
            'ml_order_id'   => $context['ml_order_id'] ?? null,
            'status'        => $status,
            'message'       => $message,
            'payload'       => $payload,
        ]);
    }

    /**
     * Retorna a lista ordenada de URLs de fotos do produto WC: featured + galeria.
     * Usado para enviar TODAS as fotos ao Mercado Livre, não só a destacada.
     */
    private function get_product_picture_urls($product) {
        $urls = [];

        $featured_id = $product->get_image_id();
        if ($featured_id) {
            $u = wp_get_attachment_url($featured_id);
            if ($u) {
                $urls[] = $u;
            }
        }

        $gallery_ids = $product->get_gallery_image_ids();
        foreach ($gallery_ids as $gid) {
            $u = wp_get_attachment_url($gid);
            if ($u && !in_array($u, $urls, true)) {
                $urls[] = $u;
            }
        }

        return $urls;
    }

    public function register_settings() {
        register_setting('ml_sync_group', 'ml_client_id');
        register_setting('ml_sync_group', 'ml_client_secret');
        register_setting('ml_sync_group', 'ml_redirect_uri');
        register_setting('ml_sync_group', 'ml_default_category');
        register_setting('ml_sync_group', 'ml_default_listing_type');

        add_settings_section('ml_sync_main', 'Credenciais da API', null, 'ml-sync');
        add_settings_section('ml_sync_defaults', 'Padrões de Publicação', null, 'ml-sync');

        add_settings_field('ml_client_id', 'Client ID (APP ID)', function() {
            $val = get_option('ml_client_id');
            echo "<input type='text' name='ml_client_id' value='" . esc_attr($val) . "' style='width: 300px;' />";
        }, 'ml-sync', 'ml_sync_main');

        add_settings_field('ml_client_secret', 'Client Secret', function() {
            $val = get_option('ml_client_secret');
            echo "<input type='password' name='ml_client_secret' value='" . esc_attr($val) . "' style='width: 300px;' />";
        }, 'ml-sync', 'ml_sync_main');

        add_settings_field('ml_redirect_uri', 'Redirect URI', function() {
            $val = get_option('ml_redirect_uri');
            echo "<input type='url' name='ml_redirect_uri' value='" . esc_attr($val) . "' style='width: 300px;' />";
            echo "<p class='description'>Ex: https://seu-ngrok.dev/wp-json/ml-sync/v1/auth/callback</p>";
        }, 'ml-sync', 'ml_sync_main');

        add_settings_field('ml_default_category', 'Categoria Padrão do ML', function() {
            $val = get_option('ml_default_category', 'MLB3530');
            echo "<input type='text' name='ml_default_category' value='" . esc_attr($val) . "' style='width: 300px;' />";
            echo "<p class='description'>Ex: MLB3530 (ID da categoria principal no Mercado Livre)</p>";
        }, 'ml-sync', 'ml_sync_defaults');

        add_settings_field('ml_default_listing_type', 'Tipo de Anúncio Padrão', function() {
            $val = get_option('ml_default_listing_type', 'gold_special');
            echo "<select name='ml_default_listing_type'>
                    <option value='gold_special' " . selected($val, 'gold_special', false) . ">Clássico (gold_special)</option>
                    <option value='gold_pro' " . selected($val, 'gold_pro', false) . ">Premium (gold_pro)</option>
                    <option value='free' " . selected($val, 'free', false) . ">Grátis (free)</option>
                  </select>";
        }, 'ml-sync', 'ml_sync_defaults');
    }

    public function handle_actions() {
        if (isset($_POST['ml_sync_action']) && current_user_can('manage_options')) {
            if ($_POST['ml_sync_action'] === 'toggle_sync') {
                $status = get_option('ml_sync_status', 'active');
                $new_status = ($status === 'active') ? 'paused' : 'active';
                update_option('ml_sync_status', $new_status);
                wp_redirect(admin_url('admin.php?page=ml-sync'));
                exit;
            } elseif ($_POST['ml_sync_action'] === 'import_from_ml') {
                if (!function_exists('as_enqueue_async_action')) {
                    add_settings_error('ml_sync_messages', 'ml_sync_no_as', "Erro: WooCommerce Action Scheduler não está ativo.", 'error');
                    return;
                }
                $enqueued_count = $this->enqueue_all_ml_products();
                add_settings_error('ml_sync_messages', 'ml_sync_imported', "Importação iniciada em background. {$enqueued_count} itens na fila.", 'updated');
            } elseif ($_POST['ml_sync_action'] === 'retry_import_errors') {
                $this->retry_import_errors();
                add_settings_error('ml_sync_messages', 'ml_sync_retried', "Retentativa iniciada para os itens com falha.", 'updated');
            } elseif ($_POST['ml_sync_action'] === 'clear_import_status') {
                delete_option('ml_import_status');
                add_settings_error('ml_sync_messages', 'ml_sync_cleared', "Status de importação limpo.", 'updated');
            } elseif ($_POST['ml_sync_action'] === 'reprocess_all') {
                if (!function_exists('as_enqueue_async_action')) {
                    add_settings_error('ml_sync_messages', 'ml_sync_no_as', "Erro: WooCommerce Action Scheduler não está ativo.", 'error');
                    return;
                }
                $enqueued_count = $this->enqueue_all_synced_products_for_reprocessing();
                add_settings_error('ml_sync_messages', 'ml_sync_reprocessed', "Reprocessamento iniciado em background. {$enqueued_count} itens na fila.", 'updated');
            } elseif ($_POST['ml_sync_action'] === 'sync_ml_orders') {
                if (!function_exists('as_enqueue_async_action')) {
                    add_settings_error('ml_sync_messages', 'ml_sync_no_as', "Erro: WooCommerce Action Scheduler não está ativo.", 'error');
                    return;
                }
                $period = isset($_POST['ml_orders_period']) ? sanitize_text_field($_POST['ml_orders_period']) : '30';
                $enqueued = $this->enqueue_ml_orders_sync($period);
                add_settings_error('ml_sync_messages', 'ml_sync_orders_synced', "Sincronização de pedidos iniciada. {$enqueued} pedidos encontrados.", 'updated');
            } elseif ($_POST['ml_sync_action'] === 'clear_order_sync_status') {
                delete_option('ml_order_sync_status');
                add_settings_error('ml_sync_messages', 'ml_sync_orders_cleared', "Status de sincronização de pedidos limpo.", 'updated');
            } elseif ($_POST['ml_sync_action'] === 'clear_history') {
                if (!isset($_POST['ml_clear_history_nonce']) || !wp_verify_nonce($_POST['ml_clear_history_nonce'], 'ml_clear_history')) {
                    add_settings_error('ml_sync_messages', 'ml_sync_history_nonce', 'Nonce inválido.', 'error');
                    return;
                }
                global $wpdb;
                $table = $wpdb->prefix . 'ml_sync_history';
                $deleted = $wpdb->query("DELETE FROM {$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
                add_settings_error('ml_sync_messages', 'ml_sync_history_cleared', sprintf('%d registro(s) antigo(s) removido(s) do histórico.', (int)$deleted), 'updated');
            } elseif ($_POST['ml_sync_action'] === 'sync_brands') {
                if (!function_exists('as_enqueue_async_action')) {
                    add_settings_error('ml_sync_messages', 'ml_sync_no_as', "Erro: WooCommerce Action Scheduler não está ativo.", 'error');
                    return;
                }
                $enqueued_count = $this->enqueue_all_synced_products_for_reprocessing();
                add_settings_error('ml_sync_messages', 'ml_sync_brands_synced', "Sincronização de marcas iniciada. {$enqueued_count} produtos reprocessados.", 'updated');
            }
        }
    }

    // --- HELPERS DE CONTAGEM DE PEDIDOS ---

    private function get_wc_ml_orders_count() {
        return count(wc_get_orders([
            'limit'        => -1,
            'return'       => 'ids',
            'meta_key'     => '_ml_order_id',
            'meta_compare' => 'EXISTS',
        ]));
    }

    private function get_wc_ml_orders_by_status() {
        $statuses = ['processing' => 0, 'shipped' => 0, 'completed' => 0, 'cancelled' => 0];
        foreach (array_keys($statuses) as $status) {
            $wc_status = ($status === 'shipped') ? 'wc-shipped' : $status;
            $statuses[$status] = count(wc_get_orders([
                'limit'        => -1,
                'return'       => 'ids',
                'status'       => $wc_status,
                'meta_key'     => '_ml_order_id',
                'meta_compare' => 'EXISTS',
            ]));
        }
        return $statuses;
    }

    private function get_pending_fees_count() {
        return count(wc_get_orders([
            'limit'      => -1,
            'status'     => ['processing', 'wc-shipped', 'on-hold'],
            'return'     => 'ids',
            'meta_key'   => '_ml_fees_synced',
            'meta_value' => 'pending',
        ]));
    }

    private function enqueue_ml_orders_sync($days = 30) {
        $settings = get_option($this->option_name, []);
        if (empty($settings['access_token']) || empty($settings['user_id'])) return 0;

        $date_from = date('Y-m-d\TH:i:s.000-03:00', strtotime("-{$days} days"));
        $all_order_ids = [];
        $offset = 0;
        $limit = 50;

        // Paginação para buscar todos os IDs de pedidos do período
        do {
            $url = '/orders/search?seller=' . $settings['user_id']
                 . '&order.date_created.from=' . urlencode($date_from)
                 . '&limit=' . $limit
                 . '&offset=' . $offset;

            $response = $this->request_ml('GET', $url);

            if (is_wp_error($response)) break;

            $body = json_decode(wp_remote_retrieve_body($response), true);
            $results = $body['results'] ?? [];
            $total = $body['paging']['total'] ?? 0;

            foreach ($results as $order) {
                $all_order_ids[] = $order['id'];
            }

            $offset += $limit;
        } while ($offset < $total);

        if (empty($all_order_ids)) return 0;

        // Salva status do sync
        $sync_status = [
            'total'     => count($all_order_ids),
            'processed' => 0,
        ];
        update_option('ml_order_sync_status', $sync_status);

        // Enfileira cada pedido para processamento em background
        foreach ($all_order_ids as $ml_order_id) {
            as_enqueue_async_action('ml_sync_order_single', ['ml_order_id' => $ml_order_id]);
        }

        return count($all_order_ids);
    }

    public function process_single_order_sync($args) {
        $ml_order_id = is_array($args) ? ($args['ml_order_id'] ?? null) : $args;
        if (!$ml_order_id) return;

        $this->sync_wc_order_from_ml($ml_order_id);

        // Atualiza progresso
        $status = get_option('ml_order_sync_status', ['total' => 0, 'processed' => 0]);
        $status['processed'] = ($status['processed'] ?? 0) + 1;
        update_option('ml_order_sync_status', $status);
    }

    private function enqueue_all_synced_products_for_reprocessing() {
        $args = [
            'post_type' => 'product',
            'post_status' => ['publish', 'draft', 'pending'],
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => '_ml_item_id',
                    'compare' => 'EXISTS'
                ]
            ],
            'fields' => 'ids'
        ];
        
        $query = new WP_Query($args);
        $count = 0;
        
        $status = [
            'total' => $query->found_posts,
            'processed' => 0,
            'errors' => []
        ];
        update_option('ml_import_status', $status);
        
        foreach ($query->posts as $post_id) {
            $ml_item_id = get_post_meta($post_id, '_ml_item_id', true);
            if ($ml_item_id) {
                as_enqueue_async_action('ml_sync_import_single_item', ['ml_item_id' => $ml_item_id]);
                $count++;
            }
        }
        
        $status['total'] = $count;
        update_option('ml_import_status', $status);
        
        return $count;
    }

    private function get_ml_items_count($settings) {
        if (empty($settings['access_token']) || empty($settings['user_id'])) return 0;
        
        $response = $this->request_ml('GET', '/users/' . $settings['user_id'] . '/items/search');
        
        if (is_wp_error($response)) return 0;
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return isset($body['paging']['total']) ? $body['paging']['total'] : 0;
    }

    private function get_wc_products_count() {
        $args = [
            'post_type' => 'product',
            'post_status' => ['publish', 'draft', 'pending'],
            'posts_per_page' => 1,
            'fields' => 'ids'
        ];
        $query = new WP_Query($args);
        return $query->found_posts;
    }

    private function get_synced_products_count() {
        $args = [
            'post_type' => 'product',
            'post_status' => ['publish', 'draft', 'pending'],
            'posts_per_page' => 1,
            'meta_query' => [
                [
                    'key' => '_ml_item_id',
                    'compare' => 'EXISTS'
                ]
            ],
            'fields' => 'ids'
        ];
        $query = new WP_Query($args);
        return $query->found_posts;
    }

    public function admin_page_contents() {
        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'dashboard';
        $tabs = [
            'dashboard' => 'Dashboard',
            'history'   => 'Histórico',
            'settings'  => 'Configurações',
        ];
        if (!isset($tabs[$active_tab])) {
            $active_tab = 'dashboard';
        }
        ?>
        <div class="wrap">
            <h1>Mercado Livre Sync</h1>
            <?php settings_errors('ml_sync_messages'); ?>

            <h2 class="nav-tab-wrapper">
                <?php foreach ($tabs as $slug => $label):
                    $url = admin_url('admin.php?page=ml-sync&tab=' . $slug);
                    $class = ($slug === $active_tab) ? 'nav-tab nav-tab-active' : 'nav-tab';
                    ?>
                    <a href="<?php echo esc_url($url); ?>" class="<?php echo esc_attr($class); ?>">
                        <?php echo esc_html($label); ?>
                    </a>
                <?php endforeach; ?>
            </h2>

            <?php
            if ($active_tab === 'history') {
                $this->render_history_tab();
            } elseif ($active_tab === 'settings') {
                $this->render_settings_tab();
            } else {
                $this->render_dashboard_tab();
            }
            ?>
        </div>
        <?php
    }

    private function render_dashboard_tab() {
        $settings = get_option($this->option_name, []);
        $sync_status = get_option('ml_sync_status', 'active');
        $is_connected = !empty($settings['access_token']);
        $import_status = get_option('ml_import_status', ['total' => 0, 'processed' => 0, 'errors' => []]);
        $is_importing = ($import_status['total'] > 0 && $import_status['processed'] < $import_status['total']);
        ?>
        <div style="margin-top: 20px;">
            <?php if (!$is_connected): ?>
                <div class="notice notice-warning" style="padding: 12px;">
                    <p>Você ainda não conectou ao Mercado Livre. Vá até a aba <a href="<?php echo esc_url(admin_url('admin.php?page=ml-sync&tab=settings')); ?>"><strong>Configurações</strong></a> para autenticar.</p>
                </div>
            <?php endif; ?>

            <?php if ($is_connected): ?>
                
                <!-- PROGRESS BAR & STATUS -->
                <?php if ($import_status['total'] > 0): ?>
                    <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-left: 4px solid #2271b1; border-radius: 4px; margin-top: 20px;">
                        <h3 style="margin-top:0;">Progresso da Importação do ML</h3>
                        <div style="display:flex; align-items:center; gap: 15px;">
                            <progress id="ml-import-progress" value="<?php echo esc_attr($import_status['processed']); ?>" max="<?php echo esc_attr($import_status['total']); ?>" style="width: 100%; height: 25px;"></progress>
                            <span id="ml-import-text" style="font-weight:bold; white-space:nowrap;">
                                <?php echo $import_status['processed']; ?> / <?php echo $import_status['total']; ?>
                            </span>
                        </div>
                        
                        <?php if (!$is_importing): ?>
                            <p style="color:green; font-weight:bold;">Importação Finalizada!</p>
                            <form method="post" action="" style="display:inline-block; margin-top:10px;">
                                <input type="hidden" name="ml_sync_action" value="clear_import_status">
                                <button type="submit" class="button">Ocultar Painel de Importação</button>
                            </form>
                        <?php endif; ?>

                        <?php if (!empty($import_status['errors'])): ?>
                            <div style="margin-top: 20px; border-top: 1px solid #eee; padding-top: 15px;">
                                <h4 style="color: #d63638; margin-top:0;">Erros Encontrados (<?php echo count($import_status['errors']); ?>)</h4>
                                <div style="max-height: 150px; overflow-y: auto; background: #f9f9f9; padding: 10px; border: 1px solid #ddd; margin-bottom: 15px;">
                                    <ul style="margin:0;">
                                        <?php foreach ($import_status['errors'] as $err): ?>
                                            <li><strong><?php echo esc_html($err['id']); ?>:</strong> <?php echo esc_html($err['msg']); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                                <form method="post" action="">
                                    <input type="hidden" name="ml_sync_action" value="retry_import_errors">
                                    <button type="submit" class="button button-primary">Tentar Novamente (Apenas Falhas)</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($is_importing): ?>
                    <script>
                        document.addEventListener("DOMContentLoaded", function() {
                            function pollImportStatus() {
                                fetch('<?php echo esc_url(rest_url('ml-sync/v1/import-status')); ?>')
                                    .then(res => res.json())
                                    .then(data => {
                                        let pBar = document.getElementById('ml-import-progress');
                                        let pText = document.getElementById('ml-import-text');
                                        if (pBar && pText) {
                                            pBar.value = data.processed;
                                            pBar.max = data.total;
                                            pText.innerText = data.processed + ' / ' + data.total;
                                        }
                                        if (data.processed >= data.total) {
                                            location.reload();
                                        } else {
                                            setTimeout(pollImportStatus, 3000);
                                        }
                                    }).catch(err => {
                                        setTimeout(pollImportStatus, 5000); // Retry on error
                                    });
                            }
                            pollImportStatus();
                        });
                    </script>
                    <?php endif; ?>
                <?php endif; ?>
                <!-- END PROGRESS BAR -->

                <div style="display: flex; gap: 20px; margin-top: 20px; flex-wrap: wrap;">
                    <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; min-width: 200px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                        <h3 style="margin-top:0;">Status do Sincronismo</h3>
                        <p style="font-size: 18px; font-weight: bold; color: <?php echo $sync_status === 'active' ? 'green' : 'orange'; ?>;">
                            <?php echo $sync_status === 'active' ? 'ATIVO' : 'PAUSADO'; ?>
                        </p>
                        <form method="post" action="">
                            <input type="hidden" name="ml_sync_action" value="toggle_sync">
                            <button type="submit" class="button">
                                <?php echo $sync_status === 'active' ? 'Pausar Sincronismo' : 'Retomar Sincronismo'; ?>
                            </button>
                        </form>
                    </div>

                    <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; min-width: 200px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                        <h3 style="margin-top:0;">Produtos no WooCommerce</h3>
                        <p style="font-size: 24px; font-weight: bold; margin: 10px 0;"><?php echo $this->get_wc_products_count(); ?></p>
                    </div>

                    <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; min-width: 200px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                        <h3 style="margin-top:0;">Produtos Sincronizados</h3>
                        <p style="font-size: 24px; font-weight: bold; margin: 10px 0;"><?php echo $this->get_synced_products_count(); ?></p>
                    </div>

                    <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; min-width: 200px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                        <h3 style="margin-top:0;">Anúncios no ML (Todos)</h3>
                        <p style="font-size: 24px; font-weight: bold; margin: 10px 0;"><?php echo $this->get_ml_items_count($settings); ?></p>
                        
                        <form method="post" action="" onsubmit="return confirm('Isso fará o enfileiramento de todos os seus anúncios do ML (Ativos e Inativos) para baixar em background. Tem certeza?');">
                            <input type="hidden" name="ml_sync_action" value="import_from_ml">
                            <button type="submit" class="button button-primary" <?php echo $is_importing ? 'disabled' : ''; ?>>
                                <?php echo $is_importing ? 'Importação em Andamento...' : 'Importar do ML'; ?>
                            </button>
                        </form>
                        <form method="post" action="" style="margin-top: 10px;" onsubmit="return confirm('Isso fará o reprocessamento de todos os produtos já sincronizados para buscar imagens e descrições ausentes. Tem certeza?');">
                            <input type="hidden" name="ml_sync_action" value="reprocess_all">
                            <button type="submit" class="button button-secondary" <?php echo $is_importing ? 'disabled' : ''; ?>>
                                Reprocessar Tudo (Descrições e Imagens)
                            </button>
                        </form>
                        <form method="post" action="" style="margin-top: 10px;" onsubmit="return confirm('Isso vai reprocessar todos os produtos do ML para preencher Marcas, SKU, Peso, Dimensões e Categoria. Pode demorar. Continuar?');">
                            <input type="hidden" name="ml_sync_action" value="sync_brands">
                            <button type="submit" class="button button-secondary" <?php echo $is_importing ? 'disabled' : ''; ?>>
                                🏷️ Sincronizar Marcas, SKU, Peso & Dimensões
                            </button>
                        </form>
                    </div>

                    <?php
                    // --- ORDERS CARD DATA ---
                    $wc_ml_orders_total = $this->get_wc_ml_orders_count();
                    $wc_ml_orders_by_status = $this->get_wc_ml_orders_by_status();
                    $pending_fees_count = $this->get_pending_fees_count();
                    $order_sync_status = get_option('ml_order_sync_status', ['total' => 0, 'processed' => 0]);
                    $is_order_syncing = ($order_sync_status['total'] > 0 && $order_sync_status['processed'] < $order_sync_status['total']);
                    ?>
                    <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; min-width: 350px; max-width: 500px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                        <h3 style="margin-top:0;">Pedidos do Mercado Livre</h3>
                        
                        <!-- Contadores de Pedidos -->
                        <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 15px;">
                            <div style="background: #f0f6fc; padding: 10px 15px; border-radius: 4px; text-align: center; flex: 1;">
                                <div style="font-size: 24px; font-weight: bold; color: #2271b1;"><?php echo $wc_ml_orders_total; ?></div>
                                <div style="font-size: 11px; color: #666;">Total no WC</div>
                            </div>
                            <div style="background: #fef8ee; padding: 10px 15px; border-radius: 4px; text-align: center; flex: 1;">
                                <div style="font-size: 24px; font-weight: bold; color: #dba617;"><?php echo $wc_ml_orders_by_status['processing'] ?? 0; ?></div>
                                <div style="font-size: 11px; color: #666;">Processando</div>
                            </div>
                            <div style="background: #eef6ff; padding: 10px 15px; border-radius: 4px; text-align: center; flex: 1;">
                                <div style="font-size: 24px; font-weight: bold; color: #2271b1;"><?php echo $wc_ml_orders_by_status['shipped'] ?? 0; ?></div>
                                <div style="font-size: 11px; color: #666;">Enviados</div>
                            </div>
                            <div style="background: #edf7ed; padding: 10px 15px; border-radius: 4px; text-align: center; flex: 1;">
                                <div style="font-size: 24px; font-weight: bold; color: #00a32a;"><?php echo $wc_ml_orders_by_status['completed'] ?? 0; ?></div>
                                <div style="font-size: 11px; color: #666;">Concluídos</div>
                            </div>
                        </div>

                        <?php if ($pending_fees_count > 0): ?>
                        <div style="background: #fff8e1; padding: 8px 12px; border-left: 3px solid #ffb300; margin-bottom: 15px; border-radius: 2px; font-size: 13px;">
                            ⚠️ <strong><?php echo $pending_fees_count; ?></strong> pedido(s) aguardando taxas do ML (cron a cada 15 min)
                        </div>
                        <?php endif; ?>

                        <!-- Contagem do ML via AJAX -->
                        <div id="ml-orders-count-box" style="background: #f9f9f9; padding: 10px 15px; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 15px;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <strong>Pedidos no Mercado Livre:</strong>
                                    <span id="ml-orders-remote-count" style="font-size: 18px; font-weight: bold; margin-left: 8px;">-</span>
                                </div>
                                <div>
                                    <label for="ml-orders-period-select" style="font-size: 12px; margin-right: 5px;">Período:</label>
                                    <select id="ml-orders-period-select" style="font-size: 12px;">
                                        <option value="7">7 dias</option>
                                        <option value="15">15 dias</option>
                                        <option value="30" selected>30 dias</option>
                                        <option value="60">60 dias</option>
                                        <option value="90">90 dias</option>
                                    </select>
                                </div>
                            </div>
                            <div id="ml-orders-sync-check" style="margin-top: 5px; font-size: 12px; color: #666;">Carregando...</div>
                        </div>

                        <!-- Progress bar para sincronização de pedidos -->
                        <?php if ($order_sync_status['total'] > 0): ?>
                        <div style="margin-bottom: 15px; border: 1px solid #ccc; padding: 10px; border-radius: 4px;">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <progress id="ml-order-sync-progress" value="<?php echo esc_attr($order_sync_status['processed']); ?>" max="<?php echo esc_attr($order_sync_status['total']); ?>" style="width: 100%; height: 20px;"></progress>
                                <span id="ml-order-sync-text" style="font-weight:bold; white-space:nowrap; font-size: 13px;">
                                    <?php echo $order_sync_status['processed']; ?> / <?php echo $order_sync_status['total']; ?>
                                </span>
                            </div>
                            <?php if (!$is_order_syncing && $order_sync_status['total'] > 0): ?>
                                <p style="color: green; font-weight: bold; margin: 5px 0 0;">Sincronização de pedidos finalizada!</p>
                                <form method="post" action="" style="margin-top: 5px;">
                                    <input type="hidden" name="ml_sync_action" value="clear_order_sync_status">
                                    <button type="submit" class="button button-small">Limpar</button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <form method="post" action="" style="display: flex; gap: 10px; align-items: center;">
                            <input type="hidden" name="ml_sync_action" value="sync_ml_orders">
                            <select name="ml_orders_period" style="font-size: 13px;">
                                <option value="7">7 dias</option>
                                <option value="15">15 dias</option>
                                <option value="30" selected>30 dias</option>
                                <option value="60">60 dias</option>
                                <option value="90">90 dias</option>
                            </select>
                            <button type="submit" class="button button-primary" <?php echo $is_order_syncing ? 'disabled' : ''; ?>>
                                <?php echo $is_order_syncing ? 'Sincronizando...' : 'Importar Pedidos do ML'; ?>
                            </button>
                        </form>
                        <p class="description" style="margin-top: 8px;">Webhooks ativos + Cron de segurança a cada 1h. Taxas a cada 15 min.</p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- JS para contagem remota de pedidos do ML e polling de progress -->
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                // Contagem de pedidos ML por período
                var periodSelect = document.getElementById('ml-orders-period-select');
                var countSpan = document.getElementById('ml-orders-remote-count');
                var checkDiv = document.getElementById('ml-orders-sync-check');

                function fetchMLOrdersCount() {
                    if (!periodSelect || !countSpan) return;
                    var days = periodSelect.value;
                    countSpan.innerText = '...';
                    checkDiv.innerText = 'Carregando...';
                    fetch('<?php echo esc_url(rest_url('ml-sync/v1/ml-orders-count')); ?>?days=' + days)
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            countSpan.innerText = data.ml_count;
                            if (data.missing > 0) {
                                checkDiv.innerHTML = '<span style="color:#d63638;">⚠ ' + data.missing + ' pedido(s) não sincronizado(s) (WC: ' + data.wc_count + ')</span>';
                            } else {
                                checkDiv.innerHTML = '<span style="color:#00a32a;">✔ Todos sincronizados (WC: ' + data.wc_count + ')</span>';
                            }
                        })
                        .catch(function() {
                            countSpan.innerText = 'Erro';
                            checkDiv.innerText = 'Erro ao consultar API';
                        });
                }

                if (periodSelect) {
                    periodSelect.addEventListener('change', fetchMLOrdersCount);
                    fetchMLOrdersCount();
                }

                // Polling de progresso da sincronização de pedidos
                <?php if ($is_order_syncing): ?>
                function pollOrderSync() {
                    fetch('<?php echo esc_url(rest_url('ml-sync/v1/order-sync-status')); ?>')
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            var bar = document.getElementById('ml-order-sync-progress');
                            var txt = document.getElementById('ml-order-sync-text');
                            if (bar && txt) {
                                bar.value = data.processed;
                                bar.max = data.total;
                                txt.innerText = data.processed + ' / ' + data.total;
                            }
                            if (data.processed >= data.total) {
                                location.reload();
                            } else {
                                setTimeout(pollOrderSync, 3000);
                            }
                        })
                        .catch(function() { setTimeout(pollOrderSync, 5000); });
                }
                pollOrderSync();
                <?php endif; ?>
            });
            </script>
        </div>
        <?php
    }

    private function render_settings_tab() {
        $settings = get_option($this->option_name, []);
        $is_connected = !empty($settings['access_token']);
        ?>
        <div style="margin-top: 20px;">
            <h2>Configurações da API</h2>
            <form method="post" action="options.php">
                <?php
                settings_fields('ml_sync_group');
                do_settings_sections('ml-sync');
                submit_button('Salvar Configurações');
                ?>
            </form>

            <hr style="margin: 30px 0;">

            <h2>Autenticação</h2>
            <?php if (!$is_connected): ?>
                <?php if (get_option('ml_client_id') && get_option('ml_redirect_uri')): ?>
                    <a href="<?php echo esc_url($this->get_auth_url()); ?>" class="button button-primary button-large">Conectar ao Mercado Livre</a>
                <?php else: ?>
                    <p>Preencha e salve as credenciais acima para habilitar o botão de conexão.</p>
                <?php endif; ?>
            <?php else: ?>
                <p style="color: green; font-weight: bold;">✔ Conectado com sucesso!</p>
                <p><strong>Access Token (Truncado):</strong> <?php echo esc_html(substr($settings['access_token'], 0, 15)) . '...'; ?></p>
                <?php if (!empty($settings['user_id'])): ?>
                    <p><strong>User ID:</strong> <?php echo esc_html($settings['user_id']); ?></p>
                <?php endif; ?>
            <?php endif; ?>

            <hr style="margin: 30px 0;">

            <h2>Webhooks &amp; Endpoints</h2>
            <p class="description">URLs internas usadas pelo plugin (referência para configurar no painel do ML):</p>
            <table class="widefat striped" style="max-width: 700px;">
                <tbody>
                    <tr>
                        <td><strong>Callback OAuth</strong></td>
                        <td><code><?php echo esc_html(rest_url('ml-sync/v1/auth/callback')); ?></code></td>
                    </tr>
                    <tr>
                        <td><strong>Notificações ML</strong></td>
                        <td><code><?php echo esc_html(rest_url('ml-sync/v1/notifications')); ?></code></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function render_history_tab() {
        global $wpdb;
        $table = $wpdb->prefix . 'ml_sync_history';

        // Filtros
        $filter_direction = isset($_GET['filter_direction']) ? sanitize_key($_GET['filter_direction']) : '';
        $filter_action    = isset($_GET['filter_action']) ? sanitize_key($_GET['filter_action']) : '';
        $filter_status    = isset($_GET['filter_status']) ? sanitize_key($_GET['filter_status']) : '';
        $filter_product   = isset($_GET['filter_product']) ? intval($_GET['filter_product']) : 0;
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 50;
        $offset = ($paged - 1) * $per_page;

        $where = ['1=1'];
        $params = [];
        if ($filter_direction) { $where[] = 'direction = %s'; $params[] = $filter_direction; }
        if ($filter_action)    { $where[] = 'action = %s';    $params[] = $filter_action; }
        if ($filter_status)    { $where[] = 'status = %s';    $params[] = $filter_status; }
        if ($filter_product)   { $where[] = 'wc_product_id = %d'; $params[] = $filter_product; }
        $where_sql = implode(' AND ', $where);

        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        $list_sql  = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";

        if ($params) {
            $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, $params));
            $rows  = $wpdb->get_results($wpdb->prepare($list_sql, array_merge($params, [$per_page, $offset])));
        } else {
            $total = (int) $wpdb->get_var($count_sql);
            $rows  = $wpdb->get_results($wpdb->prepare($list_sql, [$per_page, $offset]));
        }

        $total_pages = max(1, (int) ceil($total / $per_page));

        $action_labels = [
            'product_create'    => 'Criar produto',
            'product_update'    => 'Atualizar produto',
            'product_pictures'  => 'Atualizar fotos',
            'stock_update'      => 'Atualizar estoque',
            'status_update'     => 'Atualizar status',
            'order_create'      => 'Criar pedido',
            'order_update'      => 'Atualizar pedido',
            'order_fees'        => 'Sincronizar taxas',
            'order_address'    => 'Sincronizar endereço',
        ];
        ?>
        <div style="margin-top: 20px;">
            <p>Registro de todas as sincronizações realizadas entre o WooCommerce e o Mercado Livre.</p>

            <!-- Filtros -->
            <form method="get" style="background: #fff; padding: 12px; border: 1px solid #ccd0d4; border-radius: 4px; margin-bottom: 15px; display: flex; gap: 10px; align-items: end; flex-wrap: wrap;">
                <input type="hidden" name="page" value="ml-sync">
                <input type="hidden" name="tab" value="history">

                <label>Direção
                    <select name="filter_direction">
                        <option value="">Todas</option>
                        <option value="wc_to_ml" <?php selected($filter_direction, 'wc_to_ml'); ?>>WC → ML</option>
                        <option value="ml_to_wc" <?php selected($filter_direction, 'ml_to_wc'); ?>>ML → WC</option>
                    </select>
                </label>

                <label>Ação
                    <select name="filter_action">
                        <option value="">Todas</option>
                        <?php foreach ($action_labels as $val => $lbl): ?>
                            <option value="<?php echo esc_attr($val); ?>" <?php selected($filter_action, $val); ?>><?php echo esc_html($lbl); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>Status
                    <select name="filter_status">
                        <option value="">Todos</option>
                        <option value="success" <?php selected($filter_status, 'success'); ?>>Sucesso</option>
                        <option value="error" <?php selected($filter_status, 'error'); ?>>Erro</option>
                        <option value="skipped" <?php selected($filter_status, 'skipped'); ?>>Ignorado</option>
                    </select>
                </label>

                <label>Produto WC ID
                    <input type="number" name="filter_product" value="<?php echo esc_attr($filter_product ?: ''); ?>" style="width: 100px;">
                </label>

                <button type="submit" class="button button-primary">Filtrar</button>
                <a href="<?php echo esc_url(admin_url('admin.php?page=ml-sync&tab=history')); ?>" class="button">Limpar</a>

                <span style="margin-left: auto;">
                    <strong><?php echo number_format_i18n($total); ?></strong> registro(s)
                </span>
            </form>

            <?php if (empty($rows)): ?>
                <div class="notice notice-info" style="padding: 12px;"><p>Nenhuma sincronização registrada ainda. Os eventos serão capturados automaticamente conforme produtos e pedidos forem sincronizados.</p></div>
            <?php else: ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th style="width: 140px;">Data/Hora</th>
                            <th style="width: 90px;">Direção</th>
                            <th style="width: 140px;">Ação</th>
                            <th style="width: 80px;">Status</th>
                            <th style="width: 100px;">Produto WC</th>
                            <th style="width: 130px;">Item ML</th>
                            <th>Mensagem</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row):
                            $dir_color = $row->direction === 'wc_to_ml' ? '#2271b1' : '#7e22ce';
                            $dir_label = $row->direction === 'wc_to_ml' ? 'WC → ML' : 'ML → WC';
                            $status_color = ['success' => '#00a32a', 'error' => '#d63638', 'skipped' => '#8c8f94'][$row->status] ?? '#666';
                            $action_label = $action_labels[$row->action] ?? $row->action;
                            ?>
                            <tr>
                                <td><?php echo esc_html(mysql2date('d/m/Y H:i:s', $row->created_at)); ?></td>
                                <td><span style="color: <?php echo esc_attr($dir_color); ?>; font-weight: bold;"><?php echo esc_html($dir_label); ?></span></td>
                                <td><?php echo esc_html($action_label); ?></td>
                                <td><span style="color: <?php echo esc_attr($status_color); ?>; font-weight: bold; text-transform: uppercase;"><?php echo esc_html($row->status); ?></span></td>
                                <td>
                                    <?php if ($row->wc_product_id): ?>
                                        <a href="<?php echo esc_url(get_edit_post_link($row->wc_product_id)); ?>">#<?php echo esc_html($row->wc_product_id); ?></a>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($row->ml_item_id): ?>
                                        <a href="https://produto.mercadolivre.com.br/<?php echo esc_attr(str_replace('MLB', 'MLB-', $row->ml_item_id)); ?>" target="_blank" rel="noopener"><?php echo esc_html($row->ml_item_id); ?></a>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                                <td>
                                    <?php echo esc_html($row->message); ?>
                                    <?php if (!empty($row->payload)): ?>
                                        <details style="margin-top: 4px;">
                                            <summary style="cursor: pointer; font-size: 11px; color: #2271b1;">Ver payload</summary>
                                            <pre style="background: #f6f7f7; padding: 8px; max-height: 200px; overflow: auto; font-size: 11px; margin: 4px 0 0;"><?php echo esc_html($row->payload); ?></pre>
                                        </details>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Paginação -->
                <?php if ($total_pages > 1):
                    $base_url = add_query_arg([
                        'page' => 'ml-sync',
                        'tab'  => 'history',
                        'filter_direction' => $filter_direction,
                        'filter_action'    => $filter_action,
                        'filter_status'    => $filter_status,
                        'filter_product'   => $filter_product ?: '',
                    ], admin_url('admin.php'));
                    ?>
                    <div class="tablenav" style="margin-top: 15px;">
                        <div class="tablenav-pages">
                            <?php
                            echo paginate_links([
                                'base'      => $base_url . '%_%',
                                'format'    => '&paged=%#%',
                                'current'   => $paged,
                                'total'     => $total_pages,
                                'prev_text' => '« Anterior',
                                'next_text' => 'Próxima »',
                            ]);
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <hr style="margin: 30px 0;">

            <form method="post" action="" onsubmit="return confirm('Tem certeza? Isso apagará todos os registros do histórico (não afeta produtos ou pedidos).');">
                <input type="hidden" name="ml_sync_action" value="clear_history">
                <?php wp_nonce_field('ml_clear_history', 'ml_clear_history_nonce'); ?>
                <button type="submit" class="button">Limpar histórico antigo</button>
                <span class="description"> Mantém apenas registros dos últimos 30 dias.</span>
            </form>
        </div>
        <?php
    }

    private function get_auth_url() {
        $client_id = get_option('ml_client_id');
        $redirect_uri = get_option('ml_redirect_uri');
        return "https://auth.mercadolivre.com.br/authorization?response_type=code&client_id={$client_id}&redirect_uri={$redirect_uri}";
    }

    public function get_access_token() {
        $settings = get_option($this->option_name, []);
        if (empty($settings['access_token'])) {
            return false;
        }

        // Verificar expiração (com margem de 5 minutos)
        $expires = isset($settings['token_expires']) ? (int) $settings['token_expires'] : 0;
        if (time() + 300 >= $expires) {
            return $this->refresh_access_token();
        }

        return $settings['access_token'];
    }

    private function refresh_access_token() {
        $settings = get_option($this->option_name, []);
        if (empty($settings['refresh_token'])) {
            error_log('ML Sync: Tentativa de refresh sem refresh_token.');
            return false;
        }

        $client_id = get_option('ml_client_id');
        $client_secret = get_option('ml_client_secret');

        $response = wp_remote_post('https://api.mercadolibre.com/oauth/token', [
            'body' => [
                'grant_type'    => 'refresh_token',
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'refresh_token' => $settings['refresh_token'],
            ]
        ]);

        if (is_wp_error($response)) {
            error_log('ML Sync: Erro no refresh token: ' . $response->get_error_message());
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['access_token'])) {
            error_log('ML Sync: Erro no refresh token: ' . wp_remote_retrieve_body($response));
            return false;
        }

        $settings['access_token'] = $body['access_token'];
        $settings['refresh_token'] = $body['refresh_token'];
        $settings['token_expires'] = time() + (int) $body['expires_in'];
        update_option($this->option_name, $settings);

        error_log('ML Sync: Token renovado com sucesso.');
        return $body['access_token'];
    }

    public function request_ml($method, $endpoint, $body = null) {
        $token = $this->get_access_token();
        if (!$token) {
            return new WP_Error('no_token', 'Acesso não autorizado ao Mercado Livre.');
        }

        $args = [
            'method'  => strtoupper($method),
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 30,
        ];

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $url = $this->api_url . '/' . ltrim($endpoint, '/');
        $response = wp_remote_request($url, $args);

        // Se falhar com 401, tenta um refresh forçado uma única vez
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 401) {
             $token = $this->refresh_access_token();
             if ($token) {
                 $args['headers']['Authorization'] = 'Bearer ' . $token;
                 $response = wp_remote_request($url, $args);
             }
        }

        return $response;
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

        register_rest_route('ml-sync/v1', '/import-status', [
            'methods' => 'GET',
            'callback' => [$this, 'get_import_status_api'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route('ml-sync/v1', '/order-sync-status', [
            'methods' => 'GET',
            'callback' => [$this, 'get_order_sync_status_api'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route('ml-sync/v1', '/ml-orders-count', [
            'methods' => 'GET',
            'callback' => [$this, 'get_ml_orders_count_api'],
            'permission_callback' => '__return_true'
        ]);
    }

    public function get_import_status_api() {
        $status = get_option('ml_import_status', ['total' => 0, 'processed' => 0, 'errors' => []]);
        return new WP_REST_Response($status, 200);
    }

    public function get_order_sync_status_api() {
        $status = get_option('ml_order_sync_status', ['total' => 0, 'processed' => 0]);
        return new WP_REST_Response($status, 200);
    }

    public function get_ml_orders_count_api($request) {
        $days = intval($request->get_param('days'));
        if ($days < 1) $days = 30;

        $settings = get_option($this->option_name, []);
        if (empty($settings['access_token']) || empty($settings['user_id'])) {
            return new WP_REST_Response(['count' => 0, 'error' => 'Not connected'], 200);
        }

        $date_from = date('Y-m-d\TH:i:s.000-03:00', strtotime("-{$days} days"));
        $url = '/orders/search?seller=' . $settings['user_id'] . '&order.date_created.from=' . urlencode($date_from) . '&limit=1';
        
        $response = $this->request_ml('GET', $url);

        if (is_wp_error($response)) {
            return new WP_REST_Response(['count' => 0, 'error' => $response->get_error_message()], 200);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $total = $body['paging']['total'] ?? 0;

        // Conta quantos desses já temos no WC
        $date_after = date('Y-m-d', strtotime("-{$days} days"));
        $wc_count = count(wc_get_orders([
            'limit'      => -1,
            'return'     => 'ids',
            'date_after' => $date_after,
            'meta_key'   => '_ml_order_id',
            'meta_compare' => 'EXISTS',
        ]));

        return new WP_REST_Response([
            'ml_count' => $total,
            'wc_count' => $wc_count,
            'missing'  => max(0, $total - $wc_count)
        ], 200);
    }

    public function handle_auth_callback($request) {
        $code = $request->get_param('code');
        $error = $request->get_param('error');

        if ($error) {
            return new WP_REST_Response(['message' => 'Erro na autorização', 'error' => $error], 400);
        }

        if (empty($code)) {
            return new WP_REST_Response(['message' => 'Código não fornecido'], 400);
        }

        $client_id = get_option('ml_client_id');
        $client_secret = get_option('ml_client_secret');
        $redirect_uri = get_option('ml_redirect_uri');

        $response = wp_remote_post('https://api.mercadolibre.com/oauth/token', [
            'body' => [
                'grant_type'   => 'authorization_code',
                'client_id'    => $client_id,
                'client_secret'=> $client_secret,
                'code'         => $code,
                'redirect_uri' => $redirect_uri
            ]
        ]);

        if (is_wp_error($response)) {
            return new WP_REST_Response(['message' => 'Erro na requisição', 'details' => $response->get_error_message()], 500);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['access_token'])) {
            $settings = get_option($this->option_name, []);
            $settings['access_token']  = $body['access_token'];
            $settings['refresh_token'] = $body['refresh_token'] ?? '';
            $settings['user_id']       = $body['user_id'] ?? '';
            $settings['expires_in']    = time() + ($body['expires_in'] ?? 21600);
            
            update_option($this->option_name, $settings);

            wp_redirect(admin_url('admin.php?page=ml-sync'));
            exit;
        }

        return new WP_REST_Response(['message' => 'Falha ao trocar token', 'response' => $body], 400);
    }

    public function handle_ml_notifications($request) {
        $data = $request->get_json_params();
        error_log('ML Notification Received: ' . print_r($data, true));
        
        $topic = isset($data['topic']) ? $data['topic'] : '';
        $resource = isset($data['resource']) ? $data['resource'] : '';

        if ($topic === 'items' && !empty($resource)) {
            $this->update_wc_product_from_ml($resource);
        } elseif (($topic === 'orders_v2' || $topic === 'orders') && !empty($resource)) {
            // Resource format usually: /orders/{order_id}
            preg_match('/\/orders\/(\d+)/', $resource, $matches);
            if (!empty($matches[1])) {
                $this->sync_wc_order_from_ml($matches[1]);
            }
        }
        
        return new WP_REST_Response(['status' => 'ok'], 200);
    }

    private function update_wc_product_from_ml($resource) {
        $response = $this->request_ml('GET', $resource);

        if (is_wp_error($response)) return;

        $ml_item = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($ml_item['id'])) return;

        $args = [
            'post_type' => 'product',
            'post_status' => 'any',
            'meta_query' => [
                [
                    'key' => '_ml_item_id',
                    'value' => $ml_item['id'],
                    'compare' => '='
                ]
            ],
            'posts_per_page' => 1
        ];
        $query = new WP_Query($args);

        if ($query->have_posts()) {
            $product_id = $query->posts[0]->ID;
            $wc_product = wc_get_product($product_id);
            
            if ($wc_product) {
                // Previne loop: remove TODOS os hooks de sync WC->ML
                remove_action('woocommerce_update_product', [$this, 'sync_product_to_ml'], 10);
                remove_action('woocommerce_product_set_stock', [$this, 'sync_stock_to_ml'], 10);
                remove_action('transition_post_status', [$this, 'handle_product_status_change'], 10);

                $wc_product->set_manage_stock(true);
                $wc_product->set_stock_quantity($ml_item['available_quantity']);
                
                if ($ml_item['status'] !== 'active') {
                    $wc_product->set_status('draft');
                } else {
                    $wc_product->set_status('publish');
                }

                $wc_product->save();

                // Restaura hooks
                add_action('woocommerce_update_product', [$this, 'sync_product_to_ml'], 10, 1);
                add_action('woocommerce_product_set_stock', [$this, 'sync_stock_to_ml'], 10, 1);
                add_action('transition_post_status', [$this, 'handle_product_status_change'], 10, 3);

                $this->log_sync('ml_to_wc', 'product_update', 'success',
                    sprintf('Estoque %d, status ML "%s"', (int)$ml_item['available_quantity'], $ml_item['status']),
                    ['wc_product_id' => $product_id, 'ml_item_id' => $ml_item['id']]);
            }
        }
    }

    public function sync_product_to_ml($product_id) {
        if (get_option('ml_sync_status', 'active') !== 'active') return;

        $product = wc_get_product($product_id);
        if (!$product) return;

        $ml_item_id = $product->get_meta('_ml_item_id');

        $price = $product->get_price();
        $stock = $product->get_stock_quantity() ? $product->get_stock_quantity() : 1;
        $title = $product->get_name();
        
        $picture_urls = $this->get_product_picture_urls($product);
        $pictures = array_map(function($u) { return ['source' => $u]; }, $picture_urls);
        $current_pictures_hash = !empty($picture_urls) ? md5(implode('|', $picture_urls)) : '';

        if (empty($ml_item_id)) {
            // Criar novo anúncio no ML — só quando não existe
            $ml_category_id = $product->get_meta('_ml_category_id');
            $default_category = !empty($ml_category_id) ? $ml_category_id : get_option('ml_default_category', 'MLB3530');
            $listing_type = get_option('ml_default_listing_type', 'gold_special');

            $body = [
                'title' => substr($title, 0, 60),
                'category_id' => $default_category,
                'price' => (float) $price,
                'currency_id' => 'BRL',
                'available_quantity' => (int) $stock,
                'buying_mode' => 'buy_it_now',
                'condition' => 'new',
                'listing_type_id' => $listing_type,
                'pictures' => $pictures
            ];

            $response = $this->request_ml('POST', '/items', $body);

            if (!is_wp_error($response)) {
                $ml_data = json_decode(wp_remote_retrieve_body($response), true);
                if (isset($ml_data['id'])) {
                    $product->update_meta_data('_ml_item_id', $ml_data['id']);
                    $product->update_meta_data('_ml_last_pictures_hash', $current_pictures_hash);
                    $product->save_meta_data();
                    $this->log_sync('wc_to_ml', 'product_create', 'success',
                        sprintf('Anúncio criado com %d foto(s)', count($pictures)),
                        ['wc_product_id' => $product_id, 'ml_item_id' => $ml_data['id'], 'payload' => ['pictures' => $picture_urls]]);
                } else {
                    error_log('ML Sync Create Error: ' . print_r($ml_data, true));
                    $this->log_sync('wc_to_ml', 'product_create', 'error',
                        'Falha ao criar anúncio: ' . ($ml_data['message'] ?? 'erro desconhecido'),
                        ['wc_product_id' => $product_id, 'payload' => $ml_data]);
                }
            } else {
                $this->log_sync('wc_to_ml', 'product_create', 'error',
                    'Erro de rede: ' . $response->get_error_message(),
                    ['wc_product_id' => $product_id]);
            }
        } else {
            // Atualizar anúncio existente — preço, estoque e título (SEM status)
            $body = [
                'price' => (float) $price,
                'available_quantity' => (int) ($stock ? $stock : 1)
            ];

            // Só envia fotos quando mudaram. Evita sobrescrever as fotos do ML
            // a cada save do produto (qualquer alteração disparava reenvio só da featured).
            $last_pictures_hash = $product->get_meta('_ml_last_pictures_hash');
            $pictures_changed = !empty($pictures) && ($current_pictures_hash !== $last_pictures_hash);

            if ($pictures_changed) {
                $body['pictures'] = $pictures;
            }

            $response = $this->request_ml('PUT', '/items/' . $ml_item_id, $body);

            if (is_wp_error($response)) {
                error_log('ML Sync Update Error: ' . $response->get_error_message());
                $this->log_sync('wc_to_ml', 'product_update', 'error',
                    'Erro de rede: ' . $response->get_error_message(),
                    ['wc_product_id' => $product_id, 'ml_item_id' => $ml_item_id]);
            } else {
                $ml_data = json_decode(wp_remote_retrieve_body($response), true);
                if (!empty($ml_data['error'])) {
                    error_log('ML Sync Update API Error [' . $ml_item_id . ']: ' . ($ml_data['message'] ?? $ml_data['error']));
                    $this->log_sync('wc_to_ml', 'product_update', 'error',
                        'API ML: ' . ($ml_data['message'] ?? $ml_data['error']),
                        ['wc_product_id' => $product_id, 'ml_item_id' => $ml_item_id, 'payload' => $ml_data]);
                } else {
                    $msg = sprintf('Preço R$ %.2f, estoque %d', (float)$price, (int)$stock);
                    if ($pictures_changed) {
                        $msg .= sprintf(', %d foto(s) atualizadas', count($pictures));
                        $product->update_meta_data('_ml_last_pictures_hash', $current_pictures_hash);
                        $product->save_meta_data();
                        $this->log_sync('wc_to_ml', 'product_pictures', 'success',
                            sprintf('Enviadas %d foto(s) ao ML', count($pictures)),
                            ['wc_product_id' => $product_id, 'ml_item_id' => $ml_item_id, 'payload' => ['pictures' => $picture_urls]]);
                    }
                    $this->log_sync('wc_to_ml', 'product_update', 'success', $msg,
                        ['wc_product_id' => $product_id, 'ml_item_id' => $ml_item_id]);
                }
            }
        }
    }

    public function sync_stock_to_ml($product) {
        if (get_option('ml_sync_status', 'active') !== 'active') return;

        $product_obj = is_numeric($product) ? wc_get_product($product) : $product;
        if (!$product_obj) return;

        $ml_item_id = $product_obj->get_meta('_ml_item_id');
        if (empty($ml_item_id)) return;

        $stock = $product_obj->get_stock_quantity() ? $product_obj->get_stock_quantity() : 0;
        $post_status = get_post_status($product_obj->get_id());

        // 1) Atualiza estoque
        $response = $this->request_ml('PUT', '/items/' . $ml_item_id, ['available_quantity' => max((int) $stock, 0)]);

        if (is_wp_error($response)) {
            $this->log_sync('wc_to_ml', 'stock_update', 'error', 'Erro de rede: ' . $response->get_error_message(),
                ['wc_product_id' => $product_obj->get_id(), 'ml_item_id' => $ml_item_id]);
        } else {
            $this->log_sync('wc_to_ml', 'stock_update', 'success', 'Estoque atualizado: ' . max((int)$stock, 0),
                ['wc_product_id' => $product_obj->get_id(), 'ml_item_id' => $ml_item_id]);
        }

        // 2) Atualiza status separadamente
        if ($stock > 0 && $post_status === 'publish') {
            $this->update_ml_item_status($ml_item_id, 'active', $product_obj->get_id());
        } elseif ($stock <= 0) {
            $this->update_ml_item_status($ml_item_id, 'paused', $product_obj->get_id());
        }
    }

    /**
     * Quando o status do post muda (draft -> publish, publish -> draft, etc.),
     * atualiza o status do anúncio no ML correspondente.
     */
    public function handle_product_status_change($new_status, $old_status, $post) {
        if ($post->post_type !== 'product') return;
        if ($new_status === $old_status) return;
        if (get_option('ml_sync_status', 'active') !== 'active') return;

        $product = wc_get_product($post->ID);
        if (!$product) return;

        $ml_item_id = $product->get_meta('_ml_item_id');
        if (empty($ml_item_id)) return;

        if ($new_status === 'publish') {
            $stock = $product->get_stock_quantity();
            $manage_stock = $product->get_manage_stock();
            if (!$manage_stock || $stock > 0) {
                $this->update_ml_item_status($ml_item_id, 'active', $product->get_id());
            }
        } elseif (in_array($new_status, ['draft', 'pending', 'trash', 'private'])) {
            $this->update_ml_item_status($ml_item_id, 'paused', $product->get_id());
        }
    }

    /**
     * Envia PUT com APENAS o campo status para o ML.
     * ML rejeita status misturado com title/price/pictures/available_quantity.
     */
    private function update_ml_item_status($ml_item_id, $status, $wc_product_id = null) {
        $response = $this->request_ml('PUT', '/items/' . $ml_item_id, ['status' => $status]);

        if (is_wp_error($response)) {
            $this->log_sync('wc_to_ml', 'status_update', 'error', 'Rede: ' . $response->get_error_message(),
                ['wc_product_id' => $wc_product_id, 'ml_item_id' => $ml_item_id, 'payload' => ['status' => $status]]);
            return;
        }

        $ml_data = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($ml_data['error'])) {
            error_log('ML Status Change Error [' . $ml_item_id . ' -> ' . $status . ']: ' . ($ml_data['message'] ?? $ml_data['error']));
            $this->log_sync('wc_to_ml', 'status_update', 'error', 'API ML: ' . ($ml_data['message'] ?? $ml_data['error']),
                ['wc_product_id' => $wc_product_id, 'ml_item_id' => $ml_item_id, 'payload' => ['status' => $status]]);
        } else {
            $this->log_sync('wc_to_ml', 'status_update', 'success', 'Status alterado para: ' . $status,
                ['wc_product_id' => $wc_product_id, 'ml_item_id' => $ml_item_id]);
        }
    }

    public function register_shipped_order_status() {
        register_post_status('wc-shipped', [
            'label'                     => 'Enviado',
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'     => true,
            'show_in_admin_status_list'  => true,
            'label_count'               => _n_noop('Enviado (%s)', 'Enviados (%s)')
        ]);
    }

    public function add_shipped_to_order_statuses($order_statuses) {
        $new_statuses = [];
        foreach ($order_statuses as $key => $status) {
            $new_statuses[$key] = $status;
            if ($key === 'wc-processing') {
                $new_statuses['wc-shipped'] = 'Enviado';
            }
        }
        return $new_statuses;
    }

    /**
     * Garante que o atributo 'Marca' (pa_marca) existe na tabela woocommerce_attribute_taxonomies.
     * Deve rodar via hook 'init' com prioridade baixa.
     */
    public function ensure_brand_attribute_registered() {
        if (!function_exists('wc_get_attribute_taxonomies')) return;

        $taxonomies = wc_get_attribute_taxonomies();
        foreach ($taxonomies as $tax) {
            if ($tax->attribute_name === 'marca') return; // já existe
        }

        // Cria o atributo globalmente no WooCommerce
        wc_create_attribute([
            'name'         => 'Marca',
            'slug'         => 'marca',
            'type'         => 'select',
            'order_by'     => 'menu_order',
            'has_archives' => false,
        ]);
    }

    private function map_ml_status_to_wc($ml_order) {
        $status = $ml_order['status'] ?? '';
        $shipping_status = $ml_order['shipping']['status'] ?? '';
        $tags = $ml_order['tags'] ?? [];

        // delivered ou closed → Concluído
        if ($status === 'delivered' || $shipping_status === 'delivered' || in_array('delivered', $tags)) {
            return 'completed';
        }
        // shipped → Enviado
        if ($status === 'shipped' || $shipping_status === 'shipped' || $shipping_status === 'ready_to_ship' || in_array('shipped', $tags)) {
            return 'shipped';
        }
        // cancelled → Cancelado
        if ($status === 'cancelled') {
            return 'cancelled';
        }
        // paid → Processando
        if ($status === 'paid') {
            return 'processing';
        }
        return 'processing';
    }

    private function is_order_finished($wc_status) {
        return in_array($wc_status, ['completed', 'cancelled', 'refunded']);
    }

    public function schedule_cron_jobs() {
        if (function_exists('as_next_scheduled_action')) {
            // Cron geral de pedidos (1h)
            if (!as_next_scheduled_action('ml_sync_orders_cron')) {
                as_schedule_recurring_action(time(), 3600, 'ml_sync_orders_cron');
            }
            // Cron rápido para pedidos sem taxas (15 min)
            if (!as_next_scheduled_action('ml_sync_fees_cron')) {
                as_schedule_recurring_action(time(), 900, 'ml_sync_fees_cron');
            }
        }
    }

    public function sync_recent_ml_orders() {
        if (get_option('ml_sync_status', 'active') !== 'active') return;

        $user_id = get_option('ml_user_id');
        if (empty($user_id)) return;

        // 1. Busca pedidos novos dos últimos 2 dias
        $date_from = date('Y-m-d\TH:i:s.000\Z', strtotime('-2 days'));
        $url = '/orders/search?seller=' . $user_id . '&order.date_created.from=' . $date_from;
        
        $response = $this->request_ml('GET', $url);

        if (!is_wp_error($response)) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (!empty($body['results'])) {
                foreach ($body['results'] as $order) {
                    $this->sync_wc_order_from_ml($order['id']);
                }
            }
        } else {
            error_log('ML Sync Order Search Error: ' . $response->get_error_message());
        }

        // 2. Atualiza status de pedidos WC que ainda não estão finalizados
        $open_orders = wc_get_orders([
            'limit'      => 50,
            'status'     => ['processing', 'wc-shipped', 'on-hold', 'pending'],
            'meta_key'   => '_ml_order_id',
            'meta_compare' => 'EXISTS',
            'orderby'    => 'date',
            'order'      => 'DESC',
        ]);

        foreach ($open_orders as $wc_order) {
            $ml_order_id = $wc_order->get_meta('_ml_order_id');
            if ($ml_order_id) {
                $this->sync_wc_order_from_ml($ml_order_id);
            }
        }
    }

    public function sync_wc_order_from_ml($ml_order_id) {
        $response = $this->request_ml('GET', '/orders/' . $ml_order_id);

        if (is_wp_error($response)) {
            error_log('ML Sync Order Fetch Error: ' . $response->get_error_message());
            return;
        }

        $ml_order = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($ml_order['id'])) return;

        // Verifica se pedido já existe no WooCommerce
        $existing_orders = wc_get_orders([
            'limit' => 1,
            'meta_key' => '_ml_order_id',
            'meta_value' => $ml_order_id,
        ]);

        $new_wc_status = $this->map_ml_status_to_wc($ml_order);

        if (!empty($existing_orders)) {
            // --- ATUALIZAR pedido existente ---
            $wc_order = $existing_orders[0];
            $current_status = $wc_order->get_status();

            // Se já está finalizado, não mexe mais
            if ($this->is_order_finished($current_status)) {
                return;
            }

            // Tenta sincronizar taxas se ainda pendente
            $this->sync_ml_order_fees($wc_order, $ml_order);
            $this->sync_ml_order_address($wc_order, $ml_order);

            // Só atualiza se o status mudou
            if ($current_status !== $new_wc_status) {
                $status_labels = [
                    'processing' => 'Processando',
                    'shipped'    => 'Enviado',
                    'completed'  => 'Concluído',
                    'cancelled'  => 'Cancelado',
                ];
                $label = $status_labels[$new_wc_status] ?? $new_wc_status;
                $wc_order->set_status($new_wc_status, "Status atualizado pelo ML: {$label}.");
                $wc_order->save();
                $this->log_sync('ml_to_wc', 'order_update', 'success', sprintf('Status: %s → %s', $current_status, $new_wc_status),
                    ['wc_order_id' => $wc_order->get_id(), 'ml_order_id' => (string)$ml_order_id]);
            }
            return;
        }

        // --- CRIAR pedido novo ---
        $wc_order = wc_create_order();
        $wc_order->set_created_via('Mercado Livre');
        
        $wc_total = 0;

        foreach ($ml_order['order_items'] as $item) {
            $ml_item_id = $item['item']['id'];
            $qty = $item['quantity'];
            $unit_price = $item['unit_price'];

            // Procura o produto no WC
            $args = [
                'post_type' => 'product',
                'post_status' => 'any',
                'meta_query' => [['key' => '_ml_item_id', 'value' => $ml_item_id, 'compare' => '=']],
                'posts_per_page' => 1,
                'fields' => 'ids'
            ];
            $query = new WP_Query($args);

            if (!empty($query->posts)) {
                $product_id = $query->posts[0];
                $product = wc_get_product($product_id);
                $item_id = $wc_order->add_product($product, $qty);
                $order_item = $wc_order->get_item($item_id);
                $order_item->set_subtotal($unit_price * $qty);
                $order_item->set_total($unit_price * $qty);
                $order_item->save();
            } else {
                $item_wc = new WC_Order_Item_Product();
                $item_wc->set_name($item['item']['title']);
                $item_wc->set_quantity($qty);
                $item_wc->set_subtotal($unit_price * $qty);
                $item_wc->set_total($unit_price * $qty);
                $wc_order->add_item($item_wc);
            }
            $wc_total += ($unit_price * $qty);
        }

        $wc_order->calculate_totals();

        // Diferença de valores (Fretes, Cupons, Bônus)
        $ml_total_amount = $ml_order['total_amount'];
        $diff = $ml_total_amount - $wc_order->get_total();

        if (abs($diff) > 0.01) {
            $fee = new WC_Order_Item_Fee();
            if ($diff > 0) {
                $fee->set_name('Acréscimo Mercado Livre (Frete/Taxa)');
                $fee->set_total($diff);
            } else {
                $fee->set_name('Desconto Mercado Livre');
                $fee->set_total($diff);
            }
            $wc_order->add_item($fee);
            $wc_order->calculate_totals();
        }

        $wc_order->update_meta_data('_ml_order_id', $ml_order_id);
        
        // Usar data real do pedido do ML
        if (!empty($ml_order['date_created'])) {
            $ml_date = new WC_DateTime($ml_order['date_created']);
            $wc_order->set_date_created($ml_date);
            $wc_order->set_date_paid($ml_date);
        }

        // ML Buyer Info
        if (!empty($ml_order['buyer']['nickname'])) {
             $wc_order->set_customer_note('Comprador ML: ' . $ml_order['buyer']['nickname']);
        }
        
        // Sempre cria como 'processing' para garantir redução de estoque e relatórios
        $wc_order->set_status('processing', 'Pedido importado do Mercado Livre.');
        $wc_order->save();

        // Tenta sincronizar taxas e endereço imediatamente
        $this->sync_ml_order_fees($wc_order, $ml_order);
        $this->sync_ml_order_address($wc_order, $ml_order);

        // Agora aplica o status real do ML (pode ser shipped/completed/cancelled)
        // O pedido pode já ter sido entregue se é antigo
        if ($new_wc_status !== 'processing') {
            $status_labels = [
                'shipped'    => 'Enviado',
                'completed'  => 'Concluído',
                'cancelled'  => 'Cancelado',
            ];
            $label = $status_labels[$new_wc_status] ?? $new_wc_status;
            $wc_order->set_status($new_wc_status, "Status atualizado pelo ML: {$label}.");
            $wc_order->save();
        }

        $this->log_sync('ml_to_wc', 'order_create', 'success',
            sprintf('Pedido criado: total R$ %.2f, status %s', (float)$ml_total_amount, $new_wc_status),
            ['wc_order_id' => $wc_order->get_id(), 'ml_order_id' => (string)$ml_order_id]);
    }

    /**
     * Sincroniza as taxas/fees do ML para um pedido WC.
     * Busca marketplace_fee (sale_fee nos items) e shipping_cost (no shipment).
     */
    private function sync_ml_order_fees($wc_order, $ml_order) {
        $fees_synced = $wc_order->get_meta('_ml_fees_synced');
        if ($fees_synced === 'yes') return;

        $settings = get_option($this->option_name, []);
        $payments = $ml_order['payments'] ?? [];
        $wc_id = $wc_order->get_id();
        
        error_log("ML Sync Fees: Checking order {$wc_id} (ML: {$ml_order['id']})");

        // Se o pedido foi cancelado ou inválido no ML, não há taxas a receber/sincronizar.
        if (isset($ml_order['status']) && in_array($ml_order['status'], ['cancelled', 'invalid'])) {
            $wc_order->update_meta_data('_ml_fees_synced', 'not_applicable');
            $wc_order->save();
            error_log("ML Sync Fees SKIP: Order {$wc_id} is {$ml_order['status']}, no fees applicable.");
            return;
        }

        // Busca o valor LÍQUIDO que o vendedor realmente recebe via /collections
        $total_net_received = 0;
        $total_transaction = 0;
        $net_found = false;

        foreach ($payments as $payment) {
            if (empty($payment['id']) || $payment['status'] !== 'approved') continue;

            $col_response = $this->request_ml('GET', '/collections/' . $payment['id']);

            if (!is_wp_error($col_response)) {
                $col_data = json_decode(wp_remote_retrieve_body($col_response), true);
                if (isset($col_data['net_received_amount'])) {
                    $total_net_received += floatval($col_data['net_received_amount']);
                    $total_transaction += floatval($col_data['transaction_amount'] ?? 0);
                    $net_found = true;
                }
            }
        }

        if ($net_found) {
            $wc_subtotal = floatval($wc_order->get_subtotal());
            $total_ml_fees = $total_transaction - $total_net_received;
            $adjustment = $total_net_received - $wc_subtotal;

            // Salva metadados detalhados para relatórios
            $wc_order->update_meta_data('_ml_transaction_amount', $total_transaction);
            $wc_order->update_meta_data('_ml_net_received', $total_net_received);
            $wc_order->update_meta_data('_ml_total_fees', $total_ml_fees);
            $wc_order->update_meta_data('_ml_fees_synced', 'yes');

            // Remove fees anteriores de ML (evita duplicar no reprocessamento)
            foreach ($wc_order->get_items('fee') as $item_id => $item) {
                $fee_name = $item->get_name();
                if (strpos($fee_name, 'Taxas ML') !== false || 
                    strpos($fee_name, 'Ajuste ML') !== false ||
                    strpos($fee_name, 'Comissão ML') !== false ||
                    strpos($fee_name, 'Acréscimo Mercado Livre') !== false ||
                    strpos($fee_name, 'Desconto Mercado Livre') !== false) {
                    $wc_order->remove_item($item_id);
                }
            }

            // Adiciona fee de ajuste: diferença entre valor recebido e preço dos produtos
            // Se positivo: preço ML era maior que WC, sobrou lucro
            // Se negativo: taxas ML comeram parte do valor
            if (abs($adjustment) > 0.01) {
                $fee = new WC_Order_Item_Fee();
                if ($adjustment > 0) {
                    $fee->set_name(sprintf('Ajuste ML (+R$%s recebido a mais)',
                        number_format($adjustment, 2, ',', '.')
                    ));
                } else {
                    $fee->set_name(sprintf('Ajuste ML (Taxas: -R$%s)',
                        number_format(abs($adjustment), 2, ',', '.')
                    ));
                }
                $fee->set_total($adjustment);
                $wc_order->add_item($fee);
            }

            $wc_order->calculate_totals();

            error_log("ML Sync Fees SUCCESS: Order {$wc_id} - Transaction: {$total_transaction}, Net: {$total_net_received}, ML Fees: {$total_ml_fees}, Adjustment: {$adjustment}");

            $wc_order->add_order_note(sprintf(
                'Taxas ML sincronizadas — Faturado: R$ %s | Taxas ML: R$ %s | Recebido líquido: R$ %s | Ajuste no pedido: R$ %s',
                number_format($total_transaction, 2, ',', '.'),
                number_format($total_ml_fees, 2, ',', '.'),
                number_format($total_net_received, 2, ',', '.'),
                number_format($adjustment, 2, ',', '.')
            ));
            $wc_order->save();
        } else {
            // net_received_amount ainda não disponível
            $wc_order->update_meta_data('_ml_fees_synced', 'pending');
            $wc_order->save();
            error_log("ML Sync Fees PENDING: Order {$wc_id} - net_received_amount not available yet.");
        }
    }

    /**
     * Sincroniza o endereço de entrega do ML para o WC.
     */
    private function sync_ml_order_address($wc_order, $ml_order) {
        if (empty($ml_order['shipping']['id'])) return;

        $settings = get_option($this->option_name, []);
        $ship_response = $this->request_ml('GET', '/shipments/' . $ml_order['shipping']['id']);

        if (is_wp_error($ship_response)) return;

        $ship_data = json_decode(wp_remote_retrieve_body($ship_response), true);
        $address = $ship_data['receiver_address'] ?? null;
        if (!$address) return;

        $address_1 = ($address['address_line'] ?? '') . ($address['street_number'] ? ', ' . $address['street_number'] : '');
        $address_2 = $address['comment'] ?? '';
        
        // Bairro (neighborhood) costuma ir no address_2 no Brasil
        $neighborhood = $address['neighborhood']['name'] ?? '';
        if ($neighborhood) {
            $address_2 = $neighborhood . ($address_2 ? ' (' . $address_2 . ')' : '');
        }

        $wc_order->set_shipping_address_1($address_1);
        $wc_order->set_shipping_address_2($address_2);
        $wc_order->set_shipping_city($address['city']['name'] ?? '');
        $wc_order->set_shipping_state($address['state']['id'] ?? ''); // O ML manda sigla tipo "SP"
        $wc_order->set_shipping_postcode($address['zip_code'] ?? '');
        $wc_order->set_shipping_country($address['country']['id'] ?? 'BR');

        // Billing costuma ser o mesmo para Mercado Livre
        $wc_order->set_billing_address_1($address_1);
        $wc_order->set_billing_address_2($address_2);
        $wc_order->set_billing_city($address['city']['name'] ?? '');
        $wc_order->set_billing_state($address['state']['id'] ?? '');
        $wc_order->set_billing_postcode($address['zip_code'] ?? '');
        $wc_order->set_billing_country($address['country']['id'] ?? 'BR');

        $wc_order->save();
        error_log("ML Sync Address: Updated address for order {$wc_order->get_id()}");
    }

    /**
     * Adiciona ação de sincronização manual no admin de pedidos.
     */
    public function add_ml_sync_order_action($actions, $order) {
        if ($order && $order->get_meta('_ml_order_id')) {
            $actions['sync_ml_order'] = 'Sincronizar Mercado Livre';
        }
        return $actions;
    }

    /**
     * Processa a ação manual de sincronização.
     */
    public function process_ml_sync_order_action($order) {
        $ml_order_id = $order->get_meta('_ml_order_id');
        if (!$ml_order_id) return;

        $settings = get_option($this->option_name, []);
        $response = $this->request_ml('GET', '/orders/' . $ml_order_id);

        if (is_wp_error($response)) {
            $order->add_order_note('Falha ao sincronizar com ML: ' . $response->get_error_message());
            return;
        }

        $ml_order = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($ml_order['id'])) {
            $order->add_order_note('Pedido não encontrado no Mercado Livre.');
            return;
        }

        // Reseta meta para forçar resync de taxas
        $order->update_meta_data('_ml_fees_synced', 'pending');
        $this->sync_ml_order_fees($order, $ml_order);
        $this->sync_ml_order_address($order, $ml_order);
        
        $order->add_order_note('Sincronização manual do Mercado Livre concluída.');
        $order->save();
    }

    /**
     * Cron rápido (15 min) — reprocessa apenas pedidos que ainda não tiveram taxas sincronizadas.
     */
    public function sync_pending_fees_orders() {
        error_log("ML Sync Cron: Starting sync_pending_fees_orders...");
        if (get_option('ml_sync_status', 'active') !== 'active') return;

        $settings = get_option($this->option_name, []);
        if (empty($settings['access_token'])) return;

        $pending_orders = wc_get_orders([
            'limit'      => 30,
            'status'     => ['processing', 'wc-shipped', 'on-hold'],
            'meta_key'   => '_ml_fees_synced',
            'meta_value' => 'pending',
            'orderby'    => 'date',
            'order'      => 'ASC',
        ]);

        foreach ($pending_orders as $wc_order) {
            $ml_order_id = $wc_order->get_meta('_ml_order_id');
            if (!$ml_order_id) continue;

            // Busca dados atualizados do pedido no ML
            $response = $this->request_ml('GET', '/orders/' . $ml_order_id);

            if (is_wp_error($response)) continue;

            $ml_order = json_decode(wp_remote_retrieve_body($response), true);
            if (empty($ml_order['id'])) continue;

            $this->sync_ml_order_fees($wc_order, $ml_order);
        }
    }

    /**
     * Sincroniza a marca do ML como atributo de produto WooCommerce (pa_marca).
     * Cria o term se não existir e vincula ao produto.
     */
    private function sync_ml_brand_to_product($product_id, $brand_name) {
        if (empty($product_id) || empty($brand_name)) return;

        $taxonomy = 'pa_marca';

        // Garante que a taxonomy existe (WooCommerce pode não ter registrado ainda em contexto background)
        if (!taxonomy_exists($taxonomy)) {
            register_taxonomy($taxonomy, 'product', [
                'label'        => 'Marca',
                'hierarchical' => false,
                'public'       => true,
                'rewrite'      => ['slug' => 'marca'],
            ]);
        }

        // Busca ou cria o term
        $term = get_term_by('name', $brand_name, $taxonomy);
        if (!$term) {
            $result = wp_insert_term($brand_name, $taxonomy);
            if (is_wp_error($result)) {
                error_log('ML Sync Brand: Failed to insert term "' . $brand_name . '": ' . $result->get_error_message());
                return;
            }
            $term_id = $result['term_id'];
        } else {
            $term_id = $term->term_id;
        }

        // Vincula o term ao produto (sem remover outras marcas, wp_set_post_terms faz merge)
        $existing_terms = wp_get_post_terms($product_id, $taxonomy, ['fields' => 'ids']);
        if (!in_array($term_id, $existing_terms)) {
            wp_set_post_terms($product_id, array_merge($existing_terms, [$term_id]), $taxonomy);
        }

        // Garante que o atributo pa_marca está registrado nos metadados do produto WooCommerce
        $product = wc_get_product($product_id);
        if ($product) {
            $attributes = $product->get_attributes();
            if (!isset($attributes[$taxonomy])) {
                $attribute = new WC_Product_Attribute();
                $attribute->set_name($taxonomy);
                $attribute->set_options([$term_id]);
                $attribute->set_position(0);
                $attribute->set_visible(true);
                $attribute->set_variation(false);
                $attributes[$taxonomy] = $attribute;
                $product->set_attributes($attributes);
                $product->save();
            }
        }
    }

    // --- MÉTODOS DE IMPORTAÇÃO ASSÍNCRONA ---

    private function enqueue_all_ml_products() {
        $user_id = get_option('ml_user_id');
        if (empty($user_id)) return 0;
        
        $item_ids = [];
        $offset = 0;
        $limit = 50;

        do {
            $url = "/users/{$user_id}/items/search?limit={$limit}&offset={$offset}";
            $response = $this->request_ml('GET', $url);

            if (is_wp_error($response)) break;

            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (empty($body['results'])) break;

            $item_ids = array_merge($item_ids, $body['results']);
            
            $total_paging = $body['paging']['total'] ?? 0;
            $offset += $limit;

        } while ($offset < $total_paging && $offset <= 1000); // 1000 limit de offset da API do ML para /search sem scroll

        if (empty($item_ids)) return 0;

        update_option('ml_import_status', [
            'total' => count($item_ids),
            'processed' => 0,
            'errors' => []
        ]);

        foreach ($item_ids as $id) {
            as_enqueue_async_action('ml_sync_import_single_item', ['ml_item_id' => $id]);
        }

        return count($item_ids);
    }

    private function retry_import_errors() {
        $status = get_option('ml_import_status', ['total' => 0, 'processed' => 0, 'errors' => []]);
        if (empty($status['errors'])) return;

        $errors_to_retry = $status['errors'];
        
        // Recalcular processed: total - qtd_erros. E zerar erros.
        $status['processed'] = $status['total'] - count($errors_to_retry);
        $status['errors'] = [];
        update_option('ml_import_status', $status);

        foreach ($errors_to_retry as $err) {
            as_enqueue_async_action('ml_sync_import_single_item', ['ml_item_id' => $err['id']]);
        }
    }

    public function process_single_import_item($ml_item_id) {
        $status = get_option('ml_import_status', ['total' => 0, 'processed' => 0, 'errors' => []]);

        try {
            $item_response = $this->request_ml('GET', '/items/' . $ml_item_id);

            if (is_wp_error($item_response)) {
                throw new Exception("Falha na requisição: " . $item_response->get_error_message());
            }
            
            $ml_item = json_decode(wp_remote_retrieve_body($item_response), true);
            if (empty($ml_item['id'])) {
                 throw new Exception("ID inválido retornado na API do ML.");
            }

            // Verifica se existe no WooCommerce
            $args = [
                'post_type' => 'product',
                'post_status' => 'any',
                'meta_query' => [['key' => '_ml_item_id', 'value' => $ml_item['id'], 'compare' => '=']],
                'posts_per_page' => 1
            ];
            $query = new WP_Query($args);

            if ($query->have_posts()) {
                $product_id = $query->posts[0]->ID;
                $product = wc_get_product($product_id);
            } else {
                $product = new WC_Product_Simple();
            }

            if (!$product) {
                 throw new Exception("Falha ao instanciar WC_Product.");
            }

            // Impede recursão
            remove_action('woocommerce_update_product', [$this, 'sync_product_to_ml'], 10);
            remove_action('woocommerce_product_set_stock', [$this, 'sync_stock_to_ml'], 10);
            remove_action('transition_post_status', [$this, 'handle_product_status_change'], 10);

            $product->set_name($ml_item['title']);
            $product->set_price($ml_item['price']);
            $product->set_regular_price($ml_item['price']);
            $product->set_manage_stock(true);
            $product->set_stock_quantity($ml_item['available_quantity']);
            
            if ($ml_item['status'] !== 'active') {
                $product->set_status('draft');
            } else {
                $product->set_status('publish');
            }

            $product->update_meta_data('_ml_item_id', $ml_item['id']);

            // --- SKU: usa o ID do anúncio no ML como SKU ---
            if (empty($product->get_sku())) {
                $product->set_sku($ml_item['id']);
            }

            // --- Peso e Dimensões ---
            // ML retorna em gramas/centímetros dentro de shipping.dimensions
            // --- Peso e Dimensões ---
            $weight = null;
            $height = null;
            $width = null;
            $length = null;

            // 1. Tenta buscar de shipping.dimensions (ex: "15x15x15,200g") - formato antigo ou específico
            $dimensions = $ml_item['shipping']['dimensions'] ?? null;
            if (!empty($dimensions)) {
                // Dimensões na API do ML costumam vir como "10x15x20,100g" ou objecto. Se for object, o código anterior tratava.
                // Mas vamos focar nos attributes, que é onde eles estão de forma estruturada.
            }

            // 2. Tenta buscar de attributes
            if (!empty($ml_item['attributes'])) {
                foreach ($ml_item['attributes'] as $attr) {
                    $attr_id = $attr['id'];
                    $struct = $attr['values'][0]['struct'] ?? null;
                    if ($struct && isset($struct['number']) && isset($struct['unit'])) {
                        $number = (float) $struct['number'];
                        $unit = strtolower($struct['unit']);
                        
                        if (strpos($attr_id, 'WEIGHT') !== false) {
                            // Converter para KG se estiver em gramas
                            $weight = ($unit === 'g') ? $number / 1000 : $number;
                        } elseif (strpos($attr_id, 'HEIGHT') !== false) {
                            // Manter em CM
                            $height = ($unit === 'm') ? $number * 100 : $number;
                        } elseif (strpos($attr_id, 'WIDTH') !== false) {
                            $width = ($unit === 'm') ? $number * 100 : $number;
                        } elseif (strpos($attr_id, 'LENGTH') !== false) {
                            $length = ($unit === 'm') ? $number * 100 : $number;
                        }
                    }
                }
            }

            if ($weight !== null) $product->set_weight($weight);
            if ($height !== null) $product->set_height($height);
            if ($width !== null)  $product->set_width($width);
            if ($length !== null) $product->set_length($length);

            // --- Categoria ML ---
            if (!empty($ml_item['category_id'])) {
                $product->update_meta_data('_ml_category_id', $ml_item['category_id']);

                // Tenta buscar nome da categoria somente se não houver ainda
                $existing_cat_name = $product->get_meta('_ml_category_name');
                if (empty($existing_cat_name)) {
                    $cat_response = $this->request_ml('GET', '/categories/' . $ml_item['category_id']);
                    if (!is_wp_error($cat_response)) {
                        $cat_body = json_decode(wp_remote_retrieve_body($cat_response), true);
                        if (!empty($cat_body['name'])) {
                            $product->update_meta_data('_ml_category_name', $cat_body['name']);
                        }
                    }
                }
            }
            
            // Descrição (PlainText)
            $desc_response = $this->request_ml('GET', '/items/' . $ml_item_id . '/description');

            if (!is_wp_error($desc_response)) {
                $desc_body = json_decode(wp_remote_retrieve_body($desc_response), true);
                if (!empty($desc_body['plain_text'])) {
                    $product->set_description($desc_body['plain_text']);
                }
            }


            // --- Marca (Atributo BRAND do ML) ---
            // ML retorna atributos em $ml_item['attributes'] como array de {id, value_name}
            if (!empty($ml_item['attributes'])) {
                foreach ($ml_item['attributes'] as $attr) {
                    if ($attr['id'] === 'BRAND' && !empty($attr['value_name'])) {
                        $this->sync_ml_brand_to_product($product_id ?? $product->get_id(), $attr['value_name']);
                        break;
                    }
                }
            }

            $product_id = $product->save();

            // Lidar com imagem
            if (isset($ml_item['pictures']) && count($ml_item['pictures']) > 0) {
                require_once(ABSPATH . 'wp-admin/includes/media.php');
                require_once(ABSPATH . 'wp-admin/includes/file.php');
                require_once(ABSPATH . 'wp-admin/includes/image.php');

                $gallery_ids = $product->get_gallery_image_ids();
                $thumbnail_id = get_post_thumbnail_id($product_id);
                $new_gallery_ids = $gallery_ids;

                foreach ($ml_item['pictures'] as $index => $pic) {
                    $pic_id_ml = $pic['id'];
                    
                    // Verifica se já baixou essa foto pesquisando meta _ml_picture_id no anexo
                    $args = [
                        'post_type' => 'attachment',
                        'post_status' => 'inherit',
                        'meta_query' => [
                            [
                                'key' => '_ml_picture_id',
                                'value' => $pic_id_ml,
                                'compare' => '='
                            ]
                        ],
                        'posts_per_page' => 1,
                        'fields' => 'ids'
                    ];
                    $existing_attachment = get_posts($args);
                    $img_id = null;

                    if (!empty($existing_attachment)) {
                        $img_id = $existing_attachment[0];
                    } else {
                        $pic_url = $pic['secure_url'] ?? $pic['url'];
                        if ($pic_url) {
                            $img_id = media_sideload_image($pic_url, $product_id, null, 'id');
                            if (!is_wp_error($img_id)) {
                                update_post_meta($img_id, '_ml_picture_id', $pic_id_ml);
                            } else {
                                $img_id = null;
                            }
                        }
                    }

                    if ($img_id) {
                        if ($index === 0 && !$thumbnail_id) {
                            set_post_thumbnail($product_id, $img_id);
                            $thumbnail_id = $img_id;
                        } elseif ($index > 0 && !in_array($img_id, $new_gallery_ids) && $img_id != $thumbnail_id) {
                            $new_gallery_ids[] = $img_id;
                        }
                    }
                }
                
                $product->set_gallery_image_ids($new_gallery_ids);
                $product->save();
            }

            // Sucesso!
            $this->log_sync('ml_to_wc', 'product_update', 'success',
                sprintf('Importado: %d foto(s), estoque %d', count($ml_item['pictures'] ?? []), (int)($ml_item['available_quantity'] ?? 0)),
                ['wc_product_id' => $product_id, 'ml_item_id' => $ml_item_id]);
        } catch (Exception $e) {
            // Falha
            $status['errors'][] = ['id' => $ml_item_id, 'msg' => $e->getMessage()];
            $this->log_sync('ml_to_wc', 'product_update', 'error', $e->getMessage(),
                ['ml_item_id' => $ml_item_id]);
        } finally {
            // Sempre adiciona processado, quer deu erro ou não
            $status['processed']++;
            update_option('ml_import_status', $status);
            
            // Re-habilita
            add_action('woocommerce_update_product', [$this, 'sync_product_to_ml'], 10);
            add_action('woocommerce_product_set_stock', [$this, 'sync_stock_to_ml'], 10);
            add_action('transition_post_status', [$this, 'handle_product_status_change'], 10);
        }
    }
}

new ML_Sync();
