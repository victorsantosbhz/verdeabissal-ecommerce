<?php
/**
 * Shortcode da Calculadora de Aquário.
 *
 * Uso: [verdeabissal_calc_aquario]
 *
 * @package VerdeAbissal\CalcAquario
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VA_Calc_Aquario_Shortcode {

	public static function init() {
		add_shortcode( 'verdeabissal_calc_aquario', array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	/**
	 * Registra (sem enfileirar) os assets. Eles serão enfileirados sob demanda
	 * no método render() para evitar carregamento desnecessário em outras páginas.
	 */
	public static function register_assets() {
		wp_register_style(
			'va-calc-aquario',
			VA_CALC_AQUARIO_URL . 'assets/calc-aquario.css',
			array(),
			VA_CALC_AQUARIO_VERSION
		);

		wp_register_script(
			'va-calc-aquario',
			VA_CALC_AQUARIO_URL . 'assets/calc-aquario.js',
			array(),
			VA_CALC_AQUARIO_VERSION,
			true
		);
	}

	/**
	 * Renderiza o shortcode.
	 */
	public static function render( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'comprimento' => 100,
				'largura'     => 45,
				'altura'      => 75,
				'fator'       => 6.0,
			),
			$atts,
			'verdeabissal_calc_aquario'
		);

		// Garante que assets estão disponíveis.
		wp_enqueue_style( 'va-calc-aquario' );
		wp_enqueue_script( 'va-calc-aquario' );

		wp_localize_script(
			'va-calc-aquario',
			'vaCalcAquarioAPI',
			array(
				'rest_url' => esc_url_raw( rest_url( 'verdeabissal/v1/calculadora' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
			)
		);

		// Cálculo inicial (server-side) para que a página já apareça preenchida
		// mesmo antes do JS rodar.
		$resultado = VA_Calc_Aquario::calcular_tudo(
			$atts['comprimento'],
			$atts['largura'],
			$atts['altura'],
			$atts['fator']
		);

		ob_start();
		self::render_template( $resultado );
		return ob_get_clean();
	}

	/**
	 * Template visual da calculadora — replica o layout do extinto AquaFlux
	 * com 4 sliders coloridos, dois indicadores resumidos e um diagrama
	 * dos cortes do vidro.
	 */
	protected static function render_template( $r ) {
		$classe = $r['classe_sf'];
		?>
		<div class="va-calc-aquario" id="va-calc-aquario"
			data-comprimento="<?php echo esc_attr( $r['comprimento_cm'] ); ?>"
			data-largura="<?php echo esc_attr( $r['largura_cm'] ); ?>"
			data-altura="<?php echo esc_attr( $r['altura_cm'] ); ?>"
			data-fator="<?php echo esc_attr( $r['sf'] ); ?>">

			<div class="va-calc-header">
				FERRAMENTAS &mdash; CALCULADORA PARA CONSTRUÇÃO DE AQUÁRIOS
			</div>

			<div class="va-calc-sliders">
				<?php
				self::render_slider( 'comprimento', 'Comprimento', $r['comprimento_cm'], 20, 300, 1, 'cm', 'va-color-blue' );
				self::render_slider( 'largura', 'Largura', $r['largura_cm'], 15, 200, 1, 'cm', 'va-color-green' );
				self::render_slider( 'altura', 'Altura', $r['altura_cm'], 15, 150, 1, 'cm', 'va-color-yellow' );
				?>

				<div class="va-slider-box va-color-red">
					<div class="va-slider-title">Fator de Segurança</div>
					<input type="range" id="va-input-fator"
						min="1.0" max="10.0" step="0.1"
						value="<?php echo esc_attr( $r['sf'] ); ?>"
						class="va-slider va-slider-fator" />
					<div class="va-slider-fator-label">
						<span id="va-out-fator-valor"><?php echo esc_html( number_format( $r['sf'], 1, '.', '' ) ); ?></span>
						<span id="va-out-fator-rotulo" style="color: <?php echo esc_attr( $classe['cor'] ); ?>;">
							<?php echo esc_html( $classe['label'] ); ?>
						</span>
					</div>
				</div>
			</div>

			<div class="va-calc-resumo">
				<div class="va-resumo-item">
					Volume do Aquário:
					<strong><span id="va-out-volume"><?php echo esc_html( number_format( $r['volume_l'], 2, '.', '' ) ); ?></span> litros</strong>
				</div>
				<div class="va-resumo-item">
					Espessura do Vidro:
					<strong><span id="va-out-espessura"><?php echo esc_html( $r['espessura_mm'] ); ?></span> mm</strong>
				</div>
			</div>

			<div class="va-calc-diagrama">
				<div class="va-row va-row-frontal">
					<div class="va-pane va-pane-frontal">
						<span class="va-pane-rotulo">Vidro Frontal</span>
						<span class="va-pane-medida-h" id="va-pane-frontal-h"><?php echo esc_html( number_format( $r['altura_cm'], 1, '.', '' ) ); ?> cm</span>
						<span class="va-pane-medida-l" id="va-pane-frontal-l"><?php echo esc_html( number_format( $r['comprimento_cm'], 1, '.', '' ) ); ?> cm</span>
					</div>
				</div>

				<div class="va-row va-row-meio">
					<div class="va-pane va-pane-lateral">
						<span class="va-pane-rotulo">Vidro<br />Lateral</span>
						<span class="va-pane-medida-l" id="va-pane-lateral-l-esq"><?php echo esc_html( number_format( $r['pecas']['lateral']['largura'], 1, '.', '' ) ); ?> cm</span>
						<span class="va-pane-medida-h" id="va-pane-lateral-h-esq"><?php echo esc_html( number_format( $r['altura_cm'], 1, '.', '' ) ); ?> cm</span>
					</div>

					<div class="va-pane va-pane-fundo">
						<span class="va-pane-rotulo">Vidro Fundo</span>
						<span class="va-pane-medida-h" id="va-pane-fundo-h"><?php echo esc_html( number_format( $r['largura_cm'], 1, '.', '' ) ); ?> cm</span>
						<span class="va-pane-medida-l" id="va-pane-fundo-l"><?php echo esc_html( number_format( $r['comprimento_cm'], 1, '.', '' ) ); ?> cm</span>
					</div>

					<div class="va-pane va-pane-lateral">
						<span class="va-pane-rotulo">Vidro<br />Lateral</span>
						<span class="va-pane-medida-l" id="va-pane-lateral-l-dir"><?php echo esc_html( number_format( $r['pecas']['lateral']['largura'], 1, '.', '' ) ); ?> cm</span>
						<span class="va-pane-medida-h" id="va-pane-lateral-h-dir"><?php echo esc_html( number_format( $r['altura_cm'], 1, '.', '' ) ); ?> cm</span>
					</div>
				</div>

				<div class="va-row va-row-traseiro">
					<div class="va-pane va-pane-traseiro">
						<span class="va-pane-rotulo">Vidro Traseiro</span>
						<span class="va-pane-medida-h" id="va-pane-traseiro-h"><?php echo esc_html( number_format( $r['altura_cm'], 1, '.', '' ) ); ?> cm</span>
						<span class="va-pane-medida-l" id="va-pane-traseiro-l"><?php echo esc_html( number_format( $r['comprimento_cm'], 1, '.', '' ) ); ?> cm</span>
					</div>
				</div>
			</div>

			<div class="va-calc-pesos">
				<h3 class="va-pesos-titulo">Resumo de Pesos &amp; Cortes</h3>

				<table class="va-pesos-tabela">
					<thead>
						<tr>
							<th>Peça</th>
							<th>Qtd.</th>
							<th>Largura (cm)</th>
							<th>Altura (cm)</th>
							<th>Peso (kg)</th>
						</tr>
					</thead>
					<tbody id="va-tabela-cortes">
						<?php foreach ( $r['pecas'] as $chave => $peca ) : ?>
							<tr data-peca="<?php echo esc_attr( $chave ); ?>">
								<td><?php echo esc_html( $peca['rotulo'] ); ?></td>
								<td><?php echo esc_html( $peca['qtd'] ); ?></td>
								<td class="va-cell-largura"><?php echo esc_html( number_format( $peca['largura'], 1, '.', '' ) ); ?></td>
								<td class="va-cell-altura"><?php echo esc_html( number_format( $peca['altura'], 1, '.', '' ) ); ?></td>
								<td class="va-cell-peso"><?php echo esc_html( number_format( $peca['peso_kg'], 2, '.', '' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<div class="va-pesos-totais">
					<div class="va-total-card va-total-vidro">
						<span class="va-total-rotulo">Peso Total do Vidro</span>
						<span class="va-total-valor"><span id="va-out-peso-vidro"><?php echo esc_html( number_format( $r['peso_vidro_kg'], 2, '.', '' ) ); ?></span> kg</span>
					</div>
					<div class="va-total-card va-total-agua">
						<span class="va-total-rotulo">Peso da Água</span>
						<span class="va-total-valor"><span id="va-out-peso-agua"><?php echo esc_html( number_format( $r['peso_agua_kg'], 2, '.', '' ) ); ?></span> kg</span>
					</div>
					<div class="va-total-card va-total-cheio">
						<span class="va-total-rotulo">Peso do Aquário Cheio</span>
						<span class="va-total-valor"><span id="va-out-peso-total"><?php echo esc_html( number_format( $r['peso_total_kg'], 2, '.', '' ) ); ?></span> kg</span>
					</div>
				</div>

				<p class="va-pesos-nota">
					Densidade considerada: vidro <?php echo esc_html( VA_Calc_Aquario::DENSIDADE_VIDRO ); ?> g/cm³ &nbsp;|&nbsp;
					água doce <?php echo esc_html( VA_Calc_Aquario::DENSIDADE_AGUA ); ?> g/cm³.
					O peso da água considera o volume útil (descontando a espessura
					das laterais e 2 cm de folga até a borda).
				</p>
			</div>

			<div class="va-calc-rodape">
				<p>
					<strong>Nota honrosa:</strong> esta calculadora foi inspirada na clássica
					<em>Calculadora para Construção de Aquários</em> do extinto site
					<strong>AquaFlux</strong>, referência no aquarismo brasileiro nos anos 2000.
					Reconstruímos com carinho o layout, atualizamos as fórmulas com base nas
					normas atuais de espessura e peso, e disponibilizamos aqui no Verde Abissal
					em homenagem ao trabalho original. 🌊
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Helper para renderizar um slider numérico padrão (Comprimento/Largura/Altura).
	 */
	protected static function render_slider( $id, $rotulo, $valor, $min, $max, $step, $unidade, $classe_cor ) {
		?>
		<div class="va-slider-box <?php echo esc_attr( $classe_cor ); ?>">
			<div class="va-slider-title"><?php echo esc_html( $rotulo ); ?></div>
			<input type="range"
				id="va-input-<?php echo esc_attr( $id ); ?>"
				min="<?php echo esc_attr( $min ); ?>"
				max="<?php echo esc_attr( $max ); ?>"
				step="<?php echo esc_attr( $step ); ?>"
				value="<?php echo esc_attr( $valor ); ?>"
				class="va-slider va-slider-<?php echo esc_attr( $id ); ?>" />
			<div class="va-slider-num">
				<input type="number"
					id="va-num-<?php echo esc_attr( $id ); ?>"
					min="<?php echo esc_attr( $min ); ?>"
					max="<?php echo esc_attr( $max ); ?>"
					step="<?php echo esc_attr( $step ); ?>"
					value="<?php echo esc_attr( $valor ); ?>" />
				<span class="va-slider-unidade"><?php echo esc_html( $unidade ); ?></span>
			</div>
		</div>
		<?php
	}
}
