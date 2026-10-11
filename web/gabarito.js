// Revisão do gabarito: seleção de intervalos de questões e faixa de disciplinas.
// Sem JavaScript a tela continua funcionando, questão por questão.
(function () {
  'use strict';

  const form = document.getElementById('revisao');
  if (!form) return;

  const cores = window.CORES_DISCIPLINAS || {};
  const cartoes = Array.from(form.querySelectorAll('.cartao'));
  const faixa = Array.from(form.querySelectorAll('.faixa li'));
  const legenda = form.querySelector('.legenda');
  const barra = form.querySelector('.selecao');
  const textoSelecao = barra.querySelector('.selecionadas');
  const escolhaDisciplina = barra.querySelector('[data-campo="disciplina"]');

  let ancora = null;           // índice do primeiro clique
  let selecionadas = new Set(); // índices dos cartões selecionados

  barra.hidden = false;

  /* ------------------------------------------------------- Disciplinas */

  function disciplinaDe(cartao) {
    return cartao.querySelector('select[name$="[disciplina]"]').value;
  }

  function atualizarFaixa() {
    const contagem = new Map();
    let semDisciplina = 0;
    cartoes.forEach(function (cartao, i) {
      const d = disciplinaDe(cartao);
      const cor = d ? (cores[d] || '#4b5563') : '';
      cartao.style.setProperty('--cor', cor || 'transparent');
      faixa[i].style.setProperty('--cor', cor || 'transparent');
      faixa[i].classList.toggle('vazia', !d);
      faixa[i].title = 'Questão ' + cartao.dataset.numero + ': ' + (d || 'sem disciplina');
      if (d) contagem.set(d, (contagem.get(d) || 0) + 1);
      else semDisciplina++;
    });
    legenda.replaceChildren();
    contagem.forEach(function (qtd, d) {
      legenda.append(itemLegenda(d + ' (' + qtd + ')', cores[d] || '#4b5563'));
    });
    if (semDisciplina) {
      legenda.append(itemLegenda('Sem disciplina (' + semDisciplina + ')', null));
    }
  }

  function itemLegenda(texto, cor) {
    const li = document.createElement('li');
    const amostra = document.createElement('span');
    amostra.className = 'amostra' + (cor ? '' : ' vazia');
    if (cor) amostra.style.setProperty('--cor', cor);
    li.append(amostra, document.createTextNode(texto));
    return li;
  }

  /* ---------------------------------------------------------- Seleção */

  function nomeIntervalo(indices) {
    const nums = indices.map(function (i) { return Number(cartoes[i].dataset.numero); }).sort(function (a, b) { return a - b; });
    if (!nums.length) return 'Nenhuma questão selecionada.';
    if (nums.length === 1) return 'Questão ' + nums[0] + ' selecionada. Clique na última questão do intervalo.';
    return 'Questões ' + nums[0] + ' a ' + nums[nums.length - 1] + ' selecionadas (' + nums.length + ').';
  }

  function mostrarSelecao() {
    cartoes.forEach(function (cartao, i) {
      const sel = selecionadas.has(i);
      cartao.classList.toggle('selecionada', sel);
      cartao.querySelector('.numero').setAttribute('aria-pressed', String(sel));
      faixa[i].classList.toggle('selecionada', sel);
    });
    textoSelecao.textContent = nomeIntervalo(Array.from(selecionadas));
  }

  function clicarNumero(i) {
    if (ancora === null) {
      ancora = i;
      selecionadas = new Set([i]);
    } else {
      const [de, ate] = ancora <= i ? [ancora, i] : [i, ancora];
      selecionadas = new Set();
      for (let k = de; k <= ate; k++) selecionadas.add(k);
      ancora = null;
    }
    mostrarSelecao();
  }

  cartoes.forEach(function (cartao, i) {
    cartao.querySelector('.numero').addEventListener('click', function () { clicarNumero(i); });
  });
  faixa.forEach(function (li, i) {
    li.addEventListener('click', function () { clicarNumero(i); });
  });

  function aplicar(funcao) {
    if (!selecionadas.size) {
      textoSelecao.textContent = 'Selecione as questões primeiro: clique no número da primeira e no da última.';
      return;
    }
    selecionadas.forEach(function (i) { funcao(cartoes[i]); });
    atualizarFaixa();
  }

  barra.addEventListener('click', function (ev) {
    const botao = ev.target.closest('button');
    if (!botao) return;
    if (botao.dataset.acao === 'todas') {
      selecionadas = new Set(cartoes.map(function (_, i) { return i; }));
      ancora = null;
      mostrarSelecao();
    } else if (botao.dataset.acao === 'limpar') {
      selecionadas = new Set();
      ancora = null;
      mostrarSelecao();
    } else if (botao.dataset.acao === 'disciplina') {
      aplicar(function (cartao) {
        cartao.querySelector('select[name$="[disciplina]"]').value = escolhaDisciplina.value;
      });
    } else if (botao.dataset.dificuldade) {
      aplicar(function (cartao) {
        const radio = cartao.querySelector('input[name$="[dificuldade]"][value="' + botao.dataset.dificuldade + '"]');
        if (radio) radio.checked = true;
      });
    }
  });

  /* ------------------------------------------------ Detalhes dos cartões */

  form.addEventListener('change', function (ev) {
    if (ev.target.matches('select[name$="[disciplina]"]')) atualizarFaixa();
  });

  // Clicar de novo na dificuldade marcada desmarca (radio sozinho não deixa voltar a "sem dificuldade").
  form.querySelectorAll('.dificuldade input').forEach(function (radio) {
    radio.closest('label').addEventListener('mousedown', function () { radio.dataset.estava = String(radio.checked); });
    radio.addEventListener('click', function () {
      if (radio.dataset.estava === 'true') radio.checked = false;
      radio.dataset.estava = 'false';
    });
  });

  document.querySelectorAll('form[data-confirmar]').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
      if (!window.confirm(f.dataset.confirmar)) ev.preventDefault();
    });
  });

  atualizarFaixa();
  mostrarSelecao();
})();
