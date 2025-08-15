// Conteúdo CORRIGIDO e FINAL para public/js/loader.js

console.log('Arquivo loader.js foi carregado com SUCESSO!'); // Pode manter ou remover esta linha de teste.

// As funções agora buscam o elemento do loader toda vez que são chamadas.
// Isso garante que elas sempre funcionem, não importa a ordem de carregamento.

window.showLoader = function() {
    const loader = document.getElementById('global-loader');
    if (loader) {
        loader.style.display = 'flex';
    }
}

window.hideLoader = function() {
    const loader = document.getElementById('global-loader');
    if (loader) {
        loader.style.display = 'none';
    }
}