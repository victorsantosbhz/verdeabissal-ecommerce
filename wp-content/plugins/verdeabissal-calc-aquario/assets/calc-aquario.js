/**
 * Verde Abissal — Calculadora de Aquário
 * Recálculo em tempo real espelhando a lógica PHP do plugin.
 */
(function () {
	"use strict";

	function fmt(num, casas) {
		if (typeof casas !== "number") casas = 1;
		return Number(num).toFixed(casas);
	}

	function $(id) { return document.getElementById(id); }

	function atualizarUI(r) {
		if (!r) return;

		// Resumos
		$("va-out-volume").textContent    = fmt(r.volume_l, 2);
		$("va-out-espessura").textContent = r.espessura_mm;

		// Fator de Segurança
		$("va-out-fator-valor").textContent  = fmt(r.sf, 1);
		const rotuloEl = $("va-out-fator-rotulo");
		rotuloEl.textContent = r.classe_sf.label;
		rotuloEl.style.color = r.classe_sf.cor;

		// Diagrama — frontal/traseiro
		$("va-pane-frontal-l").textContent  = fmt(r.comprimento_cm, 1) + " cm";
		$("va-pane-frontal-h").textContent  = fmt(r.altura_cm, 1) + " cm";
		$("va-pane-traseiro-l").textContent = fmt(r.comprimento_cm, 1) + " cm";
		$("va-pane-traseiro-h").textContent = fmt(r.altura_cm, 1) + " cm";

		// Diagrama — fundo
		$("va-pane-fundo-l").textContent = fmt(r.comprimento_cm, 1) + " cm";
		$("va-pane-fundo-h").textContent = fmt(r.largura_cm, 1) + " cm";

		// Diagrama — laterais (mostramos largura útil = L - 2t)
		const latW = fmt(r.pecas.lateral.largura, 1) + " cm";
		const latH = fmt(r.altura_cm, 1) + " cm";
		$("va-pane-lateral-l-esq").textContent = latW;
		$("va-pane-lateral-h-esq").textContent = latH;
		$("va-pane-lateral-l-dir").textContent = latW;
		$("va-pane-lateral-h-dir").textContent = latH;

		// Tabela de cortes
		const tbody = $("va-tabela-cortes");
		if (tbody) {
			Array.prototype.forEach.call(tbody.querySelectorAll("tr"), function (tr) {
				const chave = tr.getAttribute("data-peca");
				const peca  = r.pecas[chave];
				if (!peca) return;
				tr.querySelector(".va-cell-largura").textContent = fmt(peca.largura, 1);
				tr.querySelector(".va-cell-altura").textContent  = fmt(peca.altura, 1);
				tr.querySelector(".va-cell-peso").textContent    = fmt(peca.peso_kg, 2);
			});
		}

		// Cards de peso
		$("va-out-peso-vidro").textContent = fmt(r.peso_vidro_kg, 2);
		$("va-out-peso-agua").textContent  = fmt(r.peso_agua_kg, 2);
		$("va-out-peso-total").textContent = fmt(r.peso_total_kg, 2);
	}

	let timeoutId;

	function solicitarCalculo() {
		const C  = parseFloat($("va-input-comprimento").value) || 0;
		const L  = parseFloat($("va-input-largura").value)     || 0;
		const A  = parseFloat($("va-input-altura").value)      || 0;
		const SF = parseFloat($("va-input-fator").value)       || 1;

		clearTimeout(timeoutId);
		timeoutId = setTimeout(function () {
			if (typeof vaCalcAquarioAPI === 'undefined') {
				console.warn('API não configurada (vaCalcAquarioAPI está undefinido).');
				return;
			}

			fetch(vaCalcAquarioAPI.rest_url, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': vaCalcAquarioAPI.nonce
				},
				body: JSON.stringify({
					comprimento: C,
					largura: L,
					altura: A,
					fator_seguranca: SF
				})
			})
			.then(function(response) {
				if (!response.ok) {
					throw new Error('Erro na resposta da rede: ' + response.statusText);
				}
				return response.json();
			})
			.then(function(data) {
				atualizarUI(data);
			})
			.catch(function(error) {
				console.error('Erro ao calcular aquário:', error);
			});

		}, 300); // 300ms debounce
	}

	function bindPair(idRange, idNumber, onChange) {
		const r = $(idRange);
		const n = $(idNumber);
		if (!r || !n) return;

		r.addEventListener("input", function () {
			n.value = r.value;
			onChange();
		});

		n.addEventListener("input", function () {
			r.value = n.value;
			onChange();
		});
	}

	function init() {
		const root = document.getElementById("va-calc-aquario");
		if (!root) return;

		bindPair("va-input-comprimento", "va-num-comprimento", solicitarCalculo);
		bindPair("va-input-largura",     "va-num-largura",     solicitarCalculo);
		bindPair("va-input-altura",      "va-num-altura",      solicitarCalculo);

		const fator = $("va-input-fator");
		if (fator) {
			fator.addEventListener("input", solicitarCalculo);
		}
	}

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", init);
	} else {
		init();
	}
})();
