<?php
/**
 * Núcleo de cálculo do plugin Verde Abissal - Calculadora de Aquário.
 *
 * Toda a matemática (volume, espessura, cortes, peso) vive aqui, em PHP,
 * para que possa ser reutilizada via shortcode, REST e também enviada como
 * "estado inicial" para o JavaScript que faz o recálculo em tempo real.
 *
 * @package VerdeAbissal\CalcAquario
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VA_Calc_Aquario {

	/**
	 * Densidades padrão (g/cm³).
	 */
	const DENSIDADE_VIDRO = 2.5;   // Vidro float comum.
	const DENSIDADE_AGUA  = 1.0;   // Água doce.

	/**
	 * Espessuras comerciais de vidro disponíveis (mm).
	 *
	 * Após o cálculo o sistema arredonda PARA CIMA escolhendo a próxima
	 * espessura comercial.
	 */
	public static function espessuras_comerciais() {
		// 18 mm está aqui para casar com a calculadora original do AquaFlux
		// e com fornecedores que comercializam essa medida (sob encomenda).
		return array( 4, 5, 6, 8, 10, 12, 15, 18, 19, 25 );
	}

	/**
	 * Tabela de classificação do Fator de Segurança (SF).
	 *
	 * Cada faixa retorna um rótulo amigável + cor em CSS.
	 */
	public static function classificar_fator_seguranca( $sf ) {
		$sf = floatval( $sf );

		$faixas = array(
			array(
				'min'   => 1.0,
				'max'   => 2.5,
				'label' => 'Risco Alto',
				'cor'   => '#c0392b',
			),
			array(
				'min'   => 2.5,
				'max'   => 4.0,
				'label' => 'Mínimo',
				'cor'   => '#e67e22',
			),
			array(
				'min'   => 4.0,
				'max'   => 5.5,
				'label' => 'Recomendado',
				'cor'   => '#f1c40f',
			),
			array(
				'min'   => 5.5,
				'max'   => 7.5,
				'label' => 'Muito Seguro',
				'cor'   => '#27ae60',
			),
			array(
				'min'   => 7.5,
				'max'   => 10.01,
				'label' => 'Extra Seguro',
				'cor'   => '#16a085',
			),
		);

		foreach ( $faixas as $faixa ) {
			if ( $sf >= $faixa['min'] && $sf < $faixa['max'] ) {
				return $faixa;
			}
		}

		return $faixas[2]; // fallback "Recomendado"
	}

	/**
	 * Cálculo do volume bruto (litros) considerando as dimensões externas.
	 *
	 * Volume(L) = (C * L * A) / 1000  — com C, L, A em centímetros.
	 */
	public static function calcular_volume_litros( $comprimento_cm, $largura_cm, $altura_cm ) {
		return ( $comprimento_cm * $largura_cm * $altura_cm ) / 1000.0;
	}

	/**
	 * Cálculo da espessura recomendada do vidro (mm).
	 *
	 * Fórmula calibrada para baterer com a referência clássica do AquaFlux:
	 *   t = H * SF * (0.035 + 0.005 * sqrt(L/100))
	 *
	 * - H em cm (altura -> determina pressão hidrostática no fundo)
	 * - L em cm (comprimento -> determina o vão livre da maior face)
	 * - SF = fator de segurança
	 *
	 * Validação: 75cm × 6.0 × (0.035 + 0.005 × √1) = 18,00 mm  ✔︎
	 *
	 * O valor calculado é então arredondado para cima até a próxima
	 * espessura comercial disponível (4, 6, 8, 10, 12, 15, 19, 25 mm).
	 */
	public static function calcular_espessura_mm( $comprimento_cm, $altura_cm, $sf ) {
		$base = floatval( $altura_cm ) * floatval( $sf )
			* ( 0.035 + 0.005 * sqrt( floatval( $comprimento_cm ) / 100.0 ) );

		// Se a altura for muito pequena impomos um mínimo prático de 4 mm.
		if ( $base < 4 ) {
			$base = 4;
		}

		return self::arredondar_espessura_comercial( $base );
	}

	/**
	 * Arredonda a espessura calculada para cima dentre as comerciais.
	 */
	public static function arredondar_espessura_comercial( $valor_mm ) {
		foreach ( self::espessuras_comerciais() as $padrao ) {
			if ( $padrao >= $valor_mm ) {
				return $padrao;
			}
		}
		return end( self::espessuras_comerciais() );
	}

	/**
	 * Calcula as dimensões dos cortes (cm) e o peso de cada peça (kg).
	 *
	 * Modelo construtivo (padrão usado também pelo AquaFlux):
	 *   - Fundo:    C  ×  L
	 *   - Frontal:  C  ×  A
	 *   - Traseiro: C  ×  A
	 *   - Lateral:  (L − 2t) × A   (a lateral encaixa entre frontal e traseiro)
	 *
	 * Onde t é a espessura em centímetros (mm/10).
	 */
	public static function calcular_cortes( $comprimento_cm, $largura_cm, $altura_cm, $espessura_mm ) {
		$t_cm = floatval( $espessura_mm ) / 10.0;

		$lateral_largura = max( 0, floatval( $largura_cm ) - 2 * $t_cm );

		$pecas = array(
			'fundo'    => array(
				'rotulo'    => 'Vidro Fundo',
				'largura'   => floatval( $comprimento_cm ),
				'altura'    => floatval( $largura_cm ),
				'qtd'       => 1,
			),
			'frontal'  => array(
				'rotulo'    => 'Vidro Frontal',
				'largura'   => floatval( $comprimento_cm ),
				'altura'    => floatval( $altura_cm ),
				'qtd'       => 1,
			),
			'traseiro' => array(
				'rotulo'    => 'Vidro Traseiro',
				'largura'   => floatval( $comprimento_cm ),
				'altura'    => floatval( $altura_cm ),
				'qtd'       => 1,
			),
			'lateral'  => array(
				'rotulo'    => 'Vidro Lateral',
				'largura'   => $lateral_largura,
				'altura'    => floatval( $altura_cm ),
				'qtd'       => 2,
			),
		);

		// Peso de cada peça em kg.
		foreach ( $pecas as $chave => $peca ) {
			$area_cm2  = $peca['largura'] * $peca['altura'];
			$volume_g  = $area_cm2 * $t_cm * self::DENSIDADE_VIDRO; // gramas (por peça)
			$peso_kg   = ( $volume_g * $peca['qtd'] ) / 1000.0;

			$pecas[ $chave ]['peso_kg'] = $peso_kg;
		}

		return $pecas;
	}

	/**
	 * Soma o peso de todas as peças de vidro (kg).
	 */
	public static function calcular_peso_vidro_total( $pecas ) {
		$total = 0.0;
		foreach ( $pecas as $peca ) {
			$total += $peca['peso_kg'];
		}
		return $total;
	}

	/**
	 * Estima o peso da água (kg) considerando o volume útil.
	 *
	 * Volume útil = (C) × (L − 2t) × (A − folga_borda),
	 * onde "folga_borda" representa o desnível entre a água e a borda
	 * superior do aquário (default 2 cm).
	 */
	public static function calcular_peso_agua( $comprimento_cm, $largura_cm, $altura_cm, $espessura_mm, $folga_borda_cm = 2.0 ) {
		$t_cm = floatval( $espessura_mm ) / 10.0;

		$largura_util = max( 0, floatval( $largura_cm ) - 2 * $t_cm );
		$altura_util  = max( 0, floatval( $altura_cm ) - floatval( $folga_borda_cm ) );

		$volume_util_cm3 = floatval( $comprimento_cm ) * $largura_util * $altura_util;
		$volume_util_l   = $volume_util_cm3 / 1000.0;

		// 1 litro de água = 1 kg.
		return $volume_util_l * self::DENSIDADE_AGUA;
	}

	/**
	 * Faz a conta completa e devolve um array com todos os números prontos
	 * para serem renderizados pelo template.
	 */
	public static function calcular_tudo( $comprimento_cm, $largura_cm, $altura_cm, $sf ) {
		$volume_l    = self::calcular_volume_litros( $comprimento_cm, $largura_cm, $altura_cm );
		$espessura   = self::calcular_espessura_mm( $comprimento_cm, $altura_cm, $sf );
		$pecas       = self::calcular_cortes( $comprimento_cm, $largura_cm, $altura_cm, $espessura );
		$peso_vidro  = self::calcular_peso_vidro_total( $pecas );
		$peso_agua   = self::calcular_peso_agua( $comprimento_cm, $largura_cm, $altura_cm, $espessura );
		$peso_total  = $peso_vidro + $peso_agua;
		$classe_sf   = self::classificar_fator_seguranca( $sf );

		return array(
			'comprimento_cm' => floatval( $comprimento_cm ),
			'largura_cm'     => floatval( $largura_cm ),
			'altura_cm'      => floatval( $altura_cm ),
			'sf'             => floatval( $sf ),
			'classe_sf'      => $classe_sf,
			'volume_l'       => $volume_l,
			'espessura_mm'   => $espessura,
			'pecas'          => $pecas,
			'peso_vidro_kg'  => $peso_vidro,
			'peso_agua_kg'   => $peso_agua,
			'peso_total_kg'  => $peso_total,
		);
	}
}
