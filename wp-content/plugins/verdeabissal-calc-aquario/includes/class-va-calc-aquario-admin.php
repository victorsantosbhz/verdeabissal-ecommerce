<?php
/**
 * Página administrativa do plugin.
 *
 * Cria um item no menu lateral do WP-Admin com:
 *   - Link direto para a página pública da calculadora
 *   - Shortcode pronto para colar em qualquer página/post
 *   - Botão para (re)criar a página caso o usuário tenha apagado
 *
 * @package VerdeAbissal\CalcAquario
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VA_Calc_Aquario_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_va_calc_aquario_recriar_pagina', array( __CLASS__, 'handle_recriar_pagina' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( VA_CALC_AQUARIO_FILE ), array( __CLASS__, 'plugin_action_links' ) );
	}

	public static function register_menu() {
		add_menu_page(
			'Calculadora de Aquário',
			'Calc. Aquário',
			'manage_options',
			'va-calc-aquario',
			array( __CLASS__, 'render_admin_page' ),
			'dashicons-calculator',
			58
		);
	}

	public static function plugin_action_links( $links ) {
		$url = admin_url( 'admin.php?page=va-calc-aquario' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">Configurar</a>' );
		return $links;
	}

	public static function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$page_id  = (int) get_option( VA_CALC_AQUARIO_OPTION_PAGE_ID, 0 );
		$page     = $page_id ? get_post( $page_id ) : null;
		$page_url = $page ? get_permalink( $page ) : '';
		$edit_url = $page ? get_edit_post_link( $page->ID ) : '';
		?>
		<div class="wrap">
			<h1>Calculadora de Aquário <span style="font-size:13px; color:#666;">v<?php echo esc_html( VA_CALC_AQUARIO_VERSION ); ?></span></h1>

			<p>Plugin que disponibiliza uma calculadora completa para construção de aquários,
			com cortes do vidro, espessura recomendada (com fator de segurança), volume em
			litros e peso total cheio. Inspirado na clássica calculadora do extinto
			<strong>AquaFlux</strong>.</p>

			<h2>Como adicionar ao seu menu</h2>

			<?php if ( $page ) : ?>
				<p>
					Foi criada automaticamente a página
					<strong><?php echo esc_html( $page->post_title ); ?></strong>
					(<a href="<?php echo esc_url( $page_url ); ?>" target="_blank">ver página</a>
					&nbsp;|&nbsp;
					<a href="<?php echo esc_url( $edit_url ); ?>">editar página</a>).
				</p>
				<p>
					Para colocá-la no seu menu, vá em
					<strong>Aparência &rarr; Menus</strong> e adicione a página
					"<?php echo esc_html( $page->post_title ); ?>" ao menu desejado.
				</p>
			<?php else : ?>
				<p style="color:#a00;">A página da calculadora não foi encontrada.</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'va_calc_aquario_recriar' ); ?>
					<input type="hidden" name="action" value="va_calc_aquario_recriar_pagina" />
					<button type="submit" class="button button-primary">
						Recriar página automaticamente
					</button>
				</form>
			<?php endif; ?>

			<h2>Usar via Shortcode</h2>
			<p>Você também pode embutir a calculadora em qualquer página, post ou widget usando o shortcode:</p>
			<p>
				<code style="font-size:14px; padding:6px 10px; background:#f0f0f1; border:1px solid #c8c8c8; border-radius:3px;">
					[verdeabissal_calc_aquario]
				</code>
			</p>

			<p>Parâmetros opcionais (todos em centímetros, exceto fator):</p>
			<ul style="list-style: disc; margin-left: 22px;">
				<li><code>comprimento</code> &mdash; valor inicial do comprimento (cm). Default: 100.</li>
				<li><code>largura</code> &mdash; valor inicial da largura (cm). Default: 45.</li>
				<li><code>altura</code> &mdash; valor inicial da altura (cm). Default: 75.</li>
				<li><code>fator</code> &mdash; valor inicial do fator de segurança (1 a 10). Default: 6.0.</li>
			</ul>

			<p>Exemplo:</p>
			<p>
				<code style="font-size:14px; padding:6px 10px; background:#f0f0f1; border:1px solid #c8c8c8; border-radius:3px;">
					[verdeabissal_calc_aquario comprimento="120" largura="50" altura="60" fator="5.5"]
				</code>
			</p>

			<h2>Pré-visualização</h2>
			<div style="border:1px solid #ccd0d4; padding: 12px; background: #fff; max-width: 980px;">
				<?php echo do_shortcode( '[verdeabissal_calc_aquario]' ); // phpcs:ignore ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Recria a página da calculadora caso tenha sido apagada.
	 */
	public static function handle_recriar_pagina() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sem permissão.' );
		}
		check_admin_referer( 'va_calc_aquario_recriar' );

		$page_id = wp_insert_post(
			array(
				'post_title'   => 'Calculadora de Aquário',
				'post_name'    => VA_CALC_AQUARIO_SLUG,
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => '<!-- wp:shortcode -->[verdeabissal_calc_aquario]<!-- /wp:shortcode -->',
			)
		);

		if ( ! is_wp_error( $page_id ) && $page_id > 0 ) {
			update_option( VA_CALC_AQUARIO_OPTION_PAGE_ID, (int) $page_id );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=va-calc-aquario' ) );
		exit;
	}
}
