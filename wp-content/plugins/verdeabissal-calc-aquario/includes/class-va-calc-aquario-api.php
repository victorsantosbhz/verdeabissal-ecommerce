<?php
/**
 * REST API para a Calculadora de Aquário.
 *
 * @package VerdeAbissal\CalcAquario
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Bloqueia acesso direto
}

/**
 * Classe responsável por registrar e lidar com o endpoint REST.
 */
class VA_Calc_Aquario_API {

	/**
	 * Inicializa a API registrando os hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_endpoints' ) );
	}

	/**
	 * Registra o endpoint REST.
	 */
	public static function register_endpoints() {
		register_rest_route(
			'verdeabissal/v1',
			'/calculadora',
			array(
				'methods'             => WP_REST_Server::CREATABLE, // POST
				'callback'            => array( __CLASS__, 'handle_request' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
				'args'                => array(
					'comprimento' => array(
						'required'          => true,
						'type'              => 'number',
						'sanitize_callback' => 'absint',
					),
					'largura'     => array(
						'required'          => true,
						'type'              => 'number',
						'sanitize_callback' => 'absint',
					),
					'altura'      => array(
						'required'          => true,
						'type'              => 'number',
						'sanitize_callback' => 'absint',
					),
					'fator_seguranca' => array(
						'required'          => true,
						'type'              => 'number',
						'sanitize_callback' => 'abs', // aceita float
					),
				),
			)
		);
	}

	/**
	 * Verifica permissão usando nonce, garantindo que veio do site.
	 */
	public static function check_permission( $request ) {
		// Opcional: Se quisermos exigir nonce restrito para logados, poderiamos usar is_user_logged_in(), 
		// mas como é front-end, apenas verificamos um nonce gerado para usuários anônimos/logados.
		// O WP REST API já verifica o _wpnonce se passado no header X-WP-Nonce.
		// Vamos deixar o WordPress cuidar do X-WP-Nonce nativamente, retornando true aqui se quisermos que
		// funcione para não logados que tenham o nonce.
		
		// NOTA: Para usuários NÃO logados, o nonce do WP REST API muitas vezes pode ter escopo diferente se não configurado direito,
		// porém a checagem padrão de nonces no front-end é segura o suficiente usando wp_create_nonce('wp_rest').
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Você não tem permissão para fazer isso.', 'verdeabissal-calc-aquario' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Lida com a requisição e retorna o JSON.
	 */
	public static function handle_request( WP_REST_Request $request ) {
		$comprimento = $request->get_param( 'comprimento' );
		$largura     = $request->get_param( 'largura' );
		$altura      = $request->get_param( 'altura' );
		$fator_seg   = $request->get_param( 'fator_seguranca' );

		// Utiliza a lógica central de cálculo já existente
		$resultado = VA_Calc_Aquario::calcular_tudo( $comprimento, $largura, $altura, $fator_seg );

		if ( is_wp_error( $resultado ) ) {
			return new WP_Error( 'calc_error', $resultado->get_error_message(), array( 'status' => 400 ) );
		}

		return rest_ensure_response( $resultado );
	}
}
