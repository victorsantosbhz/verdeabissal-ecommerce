=== Verde Abissal - Calculadora de Aquário ===
Contributors: verdeabissal
Tags: aquário, aquarismo, calculadora, vidro, aquaflux, fish tank
Requires at least: 5.6
Tested up to: 6.5
Requires PHP: 7.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Calculadora completa para construção de aquários — cortes do vidro, espessura recomendada, volume em litros e peso total.

== Descrição ==

Plugin que entrega uma página pronta com uma **calculadora de construção de aquários** no estilo da clássica calculadora do extinto site **AquaFlux**, com:

* Volume do aquário em litros (a partir das medidas externas);
* Espessura recomendada do vidro (mm) calculada com base na altura, comprimento e Fator de Segurança;
* Cortes exatos das 5 peças (Fundo, Frontal, Traseiro e 2 Laterais), com os encaixes correspondentes à espessura;
* Peso individual de cada peça de vidro;
* Peso total do vidro, peso da água e **peso total do aquário cheio**;
* Slider de Fator de Segurança classificado por faixas (Risco Alto → Mínimo → Recomendado → Muito Seguro → Extra Seguro);
* Layout responsivo, sem dependência de jQuery.

Ao ativar o plugin, uma página chamada **"Calculadora de Aquário"** é criada automaticamente em `/calculadora-de-aquario/`. Basta adicioná-la ao menu desejado em **Aparência → Menus**. Ou use o shortcode `[verdeabissal_calc_aquario]` em qualquer página/post.

== Shortcode ==

`[verdeabissal_calc_aquario]`

Atributos opcionais (em cm; o "fator" vai de 1 a 10):

`[verdeabissal_calc_aquario comprimento="120" largura="50" altura="60" fator="5.5"]`

== Fórmulas usadas ==

* **Volume (L)** = (C × L × A) / 1000, com C, L, A em cm.
* **Espessura (mm)** = arredondada para a próxima espessura comercial (4, 5, 6, 8, 10, 12, 15, 18, 19 ou 25 mm) a partir de:
  `t = A × SF × (0.035 + 0.005 × √(C/100))`
* **Cortes**:
   * Fundo: C × L
   * Frontal/Traseiro: C × A
   * Laterais (×2): (L − 2t) × A, com t = espessura/10 (cm)
* **Peso de cada peça (kg)** = área (cm²) × t (cm) × 2.5 g/cm³ × qtd ÷ 1000.
* **Peso da água (kg)** = volume útil (L) × 1.0 (densidade da água doce); volume útil desconta a espessura das laterais e 2 cm de folga até a borda.
* **Peso total cheio** = peso do vidro + peso da água.

== Nota Honrosa ==

Esta calculadora foi inspirada na **Calculadora para Construção de Aquários** do extinto site **AquaFlux**, referência no aquarismo brasileiro nos anos 2000. Reconstruímos o layout, atualizamos as fórmulas e disponibilizamos aqui no Verde Abissal em homenagem ao trabalho original.

== Changelog ==

= 1.0.0 =
* Versão inicial.
* Calculadora completa, shortcode, criação automática de página, página administrativa.
