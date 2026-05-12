<?php
/**
 * Plugin Name:       Verde Abissal - Calculadora de Aquário
 * Plugin URI:        https://github.com/victorsantosbhz/VerdeAbissal
 * Description:       Calculadora completa para construção de aquários: gera os cortes exatos do vidro, espessura recomendada (com normas de segurança), volume em litros e peso total cheio. Disponibiliza um shortcode e cria automaticamente uma página pronta para o menu.
 * Version:           1.0.0
 * Requires at least: 5.6
 * Requires PHP:      7.2
 * Author:            Verde Abissal
 * Author URI:        https://github.com/victorsantosbhz/VerdeAbissal
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       verdeabissal-calc-aquario
 * Domain Path:       /languages
 *
 * @package VerdeAbissal\CalcAquario
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Acesso direto bloqueado.
}

/**
 * Constantes principais do plugin.
 */
define( 'VA_CALC_AQUARIO_VERSION', '1.0.0' );
define( 'VA_CALC_AQUARIO_FILE', __FILE__ );
define( 'VA_CALC_AQUARIO_DIR', plugin_dir_path( __FILE__ ) );
define( 'VA_CALC_AQUARIO_URL', plugin_dir_url( __FILE__ ) );
define( 'VA_CALC_AQUARIO_SLUG', 'calculadora-de-aquario' );
define( 'VA_CALC_AQUARIO_OPTION_PAGE_ID', 'va_calc_aquario_page_id' );

/**
 * Carrega os módulos do plugin.
 */
require_once VA_CALC_AQUARIO_DIR . 'includes/class-va-calc-aquario.php';
require_once VA_CALC_AQUARIO_DIR . 'includes/class-va-calc-aquario-api.php';
require_once VA_CALC_AQUARIO_DIR . 'includes/class-va-calc-aquario-shortcode.php';
require_once VA_CALC_AQUARIO_DIR . 'includes/class-va-calc-aquario-admin.php';

/**
 * Inicializa o plugin.
 */
function va_calc_aquario_boot() {
	VA_Calc_Aquario_API::init();
	VA_Calc_Aquario_Shortcode::init();
	if ( is_admin() ) {
		VA_Calc_Aquario_Admin::init();
	}
}
add_action( 'plugins_loaded', 'va_calc_aquario_boot' );

/**
 * Hook de ativação: cria a página da calculadora se ainda não existir.
 */
function va_calc_aquario_activate() {
	$existing_id = (int) get_option( VA_CALC_AQUARIO_OPTION_PAGE_ID, 0 );

	if ( $existing_id > 0 && get_post( $existing_id ) ) {
		return; // Já temos uma página associada.
	}

	$page = get_page_by_path( VA_CALC_AQUARIO_SLUG );

	if ( $page ) {
		update_option( VA_CALC_AQUARIO_OPTION_PAGE_ID, (int) $page->ID );
		return;
	}

	$page_id = wp_insert_post(
		array(
			'post_title'     => 'Calculadora de Aquário',
			'post_name'      => VA_CALC_AQUARIO_SLUG,
			'post_status'    => 'publish',
			'post_type'      => 'page',
			'post_content'   => '<!-- wp:shortcode -->[verdeabissal_calc_aquario]<!-- /wp:shortcode -->',
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		)
	);

	if ( ! is_wp_error( $page_id ) && $page_id > 0 ) {
		update_option( VA_CALC_AQUARIO_OPTION_PAGE_ID, (int) $page_id );
	}
}
register_activation_hook( __FILE__, 'va_calc_aquario_activate' );

/**
 * Hook de desativação: limpa apenas opções transitórias.
 * (Mantemos a página criada para não perder o link do menu.)
 */
function va_calc_aquario_deactivate() {
	// Nada a apagar por enquanto. Mantemos a página associada.
}
register_deactivation_hook( __FILE__, 'va_calc_aquario_deactivate' );
