<?php
/**
 * Plugin Name: Verde Abissal - Ordenação por Estoque & Vendas
 * Description: Ordena os produtos em TODAS as listagens da loja: primeiro os com estoque (mais vendidos no topo), depois os sem estoque (também do mais vendido para o menos vendido).
 * Version: 1.0.0
 * Author: VerdeAbissal
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class VA_Product_Order {

    /**
     * Marcador interno usado em $query->get('orderby') para sinalizar que
     * o posts_clauses deve injetar nosso ORDER BY composto.
     */
    const ORDERBY_KEY = 'va_stock_sales';

    public function __construct() {
        // 1) Substitui o orderby padrão da página da loja/categoria/tag/busca pelo nosso.
        add_filter( 'woocommerce_get_catalog_ordering_args', [ $this, 'override_catalog_ordering_args' ], 99, 3 );

        // 2) Para queries de produtos fora do fluxo padrão do WC (shortcodes, widgets,
        //    blocks, related products, upsells), aplica o nosso ordering quando
        //    o orderby for o padrão (menu_order/date) e não houver escolha explícita.
        add_action( 'pre_get_posts', [ $this, 'apply_to_product_queries' ], 99 );

        // 3) Injeta o JOIN/ORDER BY real no SQL quando vê o nosso marcador.
        add_filter( 'posts_clauses', [ $this, 'inject_order_clauses' ], 99, 2 );

        // 4) Adiciona a opção "Estoque & Vendas" no dropdown de ordenação do WC
        //    e marca como padrão. O usuário ainda pode escolher outro critério.
        add_filter( 'woocommerce_catalog_orderby', [ $this, 'add_orderby_option' ] );
        add_filter( 'woocommerce_default_catalog_orderby_options', [ $this, 'add_orderby_option' ] );
        add_filter( 'pre_option_woocommerce_default_catalog_orderby', [ $this, 'force_default_orderby' ] );
    }

    /**
     * Garante que o "padrão da loja" no WC seja a nossa ordenação composta.
     * Só aplica se o usuário não tiver definido manualmente algo diferente
     * em Ajustes > Produtos. Para isso checamos se a opção atual é vazia ou
     * o default histórico do WC ('menu_order').
     */
    public function force_default_orderby( $value ) {
        // Não usar get_option recursivamente (já estamos em pre_option_).
        global $wpdb;
        $current = $wpdb->get_var( $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            'woocommerce_default_catalog_orderby'
        ) );

        if ( $current === null || $current === '' || $current === 'menu_order' ) {
            return self::ORDERBY_KEY;
        }
        return $value; // respeita escolha explícita do admin
    }

    /**
     * Adiciona a nossa opção no dropdown de ordenação do tema.
     */
    public function add_orderby_option( $options ) {
        $new = [ self::ORDERBY_KEY => __( 'Em estoque + mais vendidos', 'va-product-order' ) ];
        // Garante que aparece no topo da lista.
        return $new + $options;
    }

    /**
     * Quando a query de catálogo do WC chega, se o orderby selecionado for o nosso
     * (ou o default não tiver sido sobrescrito explicitamente), aplicamos o marcador.
     */
    public function override_catalog_ordering_args( $args, $orderby = '', $order = '' ) {
        $effective = $orderby !== '' ? $orderby : get_option( 'woocommerce_default_catalog_orderby', self::ORDERBY_KEY );

        // Se o admin escolheu manualmente outra coisa, respeitamos.
        $respeitar = [ 'price', 'price-desc', 'date', 'popularity', 'rating', 'title', 'menu_order' ];
        if ( in_array( $effective, $respeitar, true ) ) {
            return $args;
        }

        $args['orderby']  = self::ORDERBY_KEY;
        $args['order']    = 'ASC';
        $args['meta_key'] = '';
        return $args;
    }

    /**
     * Cobertura adicional para queries que não passam pelo dropdown do catálogo
     * (shortcodes [products], widgets, blocks, etc.). Só agimos quando o orderby
     * vier como padrão (menu_order / date / vazio) — assim não atropelamos
     * casos como related products que pedem rand explicitamente.
     */
    public function apply_to_product_queries( $query ) {
        if ( is_admin() ) {
            return;
        }
        if ( ! $query instanceof WP_Query ) {
            return;
        }

        $post_type = $query->get( 'post_type' );
        $is_product_query = (
            $post_type === 'product'
            || ( is_array( $post_type ) && in_array( 'product', $post_type, true ) )
        );

        // Para a main query, força em qualquer arquivo de produto.
        if ( $query->is_main_query() ) {
            if ( function_exists( 'is_shop' ) && ( is_shop() || is_product_category() || is_product_tag() ) ) {
                $is_product_query = true;
            }
            if ( $query->is_search() && ( $post_type === 'product' || ( is_array( $post_type ) && in_array( 'product', $post_type, true ) ) ) ) {
                $is_product_query = true;
            }
        }

        if ( ! $is_product_query ) {
            return;
        }

        $current_orderby = $query->get( 'orderby' );

        // Não mexer se já está usando nosso marcador.
        if ( $current_orderby === self::ORDERBY_KEY ) {
            return;
        }

        // Não mexer se o tema/shortcode escolheu critério explícito.
        $criterios_explicitos = [ 'rand', 'price', 'popularity', 'rating', 'title', 'name', 'ID', 'meta_value', 'meta_value_num' ];
        if ( is_string( $current_orderby ) && in_array( $current_orderby, $criterios_explicitos, true ) ) {
            return;
        }
        if ( is_array( $current_orderby ) && ! empty( $current_orderby ) ) {
            return;
        }

        // 'menu_order', 'menu_order title', 'date' ou vazio: aplicamos nossa ordenação.
        $query->set( 'orderby', self::ORDERBY_KEY );
        $query->set( 'order', 'ASC' );
    }

    /**
     * Permite desabilitar a injeção em uma query específica via filtro.
     */
    private function should_apply( $query ) {
        if ( $query->get( 'orderby' ) !== self::ORDERBY_KEY ) {
            return false;
        }
        return apply_filters( 'va_product_order_apply', true, $query );
    }

    /**
     * Coração do plugin: injeta JOIN para _stock_status e total_sales e
     * substitui o ORDER BY por:
     *   1) com estoque (instock) primeiro, fora-de-estoque depois
     *   2) total_sales DESC (mais vendido primeiro)
     *   3) post_date DESC (mais novo primeiro como desempate)
     */
    public function inject_order_clauses( $clauses, $query ) {
        if ( ! $this->should_apply( $query ) ) {
            return $clauses;
        }

        global $wpdb;

        // Joins LEFT para não eliminar produtos sem o meta (raro, mas seguro).
        $stock_join = " LEFT JOIN {$wpdb->postmeta} va_pm_stock "
                    . "ON va_pm_stock.post_id = {$wpdb->posts}.ID "
                    . "AND va_pm_stock.meta_key = '_stock_status' ";
        $sales_join = " LEFT JOIN {$wpdb->postmeta} va_pm_sales "
                    . "ON va_pm_sales.post_id = {$wpdb->posts}.ID "
                    . "AND va_pm_sales.meta_key = 'total_sales' ";

        // Evita duplicar joins se o filtro rodar duas vezes na mesma query.
        if ( strpos( $clauses['join'], 'va_pm_stock' ) === false ) {
            $clauses['join'] .= $stock_join;
        }
        if ( strpos( $clauses['join'], 'va_pm_sales' ) === false ) {
            $clauses['join'] .= $sales_join;
        }

        $clauses['orderby'] =
              " CASE WHEN va_pm_stock.meta_value = 'instock' THEN 0 ELSE 1 END ASC, "
            . " CAST(COALESCE(va_pm_sales.meta_value, '0') AS UNSIGNED) DESC, "
            . " {$wpdb->posts}.post_date DESC ";

        return $clauses;
    }
}

new VA_Product_Order();
