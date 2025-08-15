document.addEventListener('DOMContentLoaded', () => {
    // URLs e Elementos Globais
    const API_URL_MENSALIDADES = 'http://localhost/Sistema/api/mensalidades';
    const API_URL_ALUNOS = 'http://localhost/Sistema/api/alunos';
    const LOGIN_URL = 'login.html';

    const tabelaMensalidades = document.getElementById('tabela-mensalidades');
    const searchAlunoInput = document.getElementById('search-aluno-input');
    const messageArea = document.getElementById('message-area');

    // Elementos do Formulário de Adição
    const formAddMensalidade = document.getElementById('form-add-mensalidade');
    const addAlunoSearchInput = document.getElementById('add-aluno-search');
    const addAlunoIdInput = document.getElementById('add-aluno-id');
    const addAlunoResultsContainer = document.getElementById('add-aluno-results');

    // Elementos do Modal de Pagamento
    const modalPagamento = document.getElementById('modal-pagamento');
    const formPagamento = document.getElementById('form-pagamento');
    const closeModalPagamento = document.getElementById('close-modal-pagamento');
    const inputIdMensalidade = document.getElementById('pagamento-id-mensalidade');
    const inputValorPago = document.getElementById('pagamento-valor');
    const inputDataPagamento = document.getElementById('pagamento-data');

    window.showLoader = function () {
        const loader = document.getElementById('global-loader');
        if (loader) {
            loader.style.display = 'flex';
        }
    }

    window.hideLoader = function () {
        const loader = document.getElementById('global-loader');
        if (loader) {
            loader.style.display = 'none';
        }
    }

    // Funções Utilitárias (debounce, formatadores, etc.)
    const debounce = (func, delay) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => func.apply(this, a), delay); } };
    const getAuthData = () => ({ token: localStorage.getItem('jwt_token'), role: localStorage.getItem('user_role') });
    window.logout = () => { localStorage.clear(); window.location.href = LOGIN_URL; };
    const displayMessage = (message, type) => { messageArea.textContent = message; messageArea.className = `message ${type}`; messageArea.classList.remove('hidden'); setTimeout(() => messageArea.classList.add('hidden'), 5000); };
    const handleAuthError = (response) => { if (response.status === 401 || response.status === 403) logout(); };
    const formatCurrency = (v) => { const n = parseFloat(v); return isNaN(n) ? "R$ 0,00" : n.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); };
    const formatDate = (d) => { if (!d) return 'N/A'; const [y, m, day] = d.split('-'); return `${day}/${m}/${y}`; };
    const getStatusBadge = (s) => {
        switch (s.toLowerCase()) {
            case 'approved': return { text: 'Pago', color: 'bg-green-100 text-green-800' };
            case 'pending': return { text: 'Pendente', color: 'bg-yellow-100 text-yellow-800' };
            case 'atrasado': return { text: 'Atrasado', color: 'bg-red-100 text-red-800' };
            default: return { text: 'Desconhecido', color: 'bg-gray-100 text-gray-800' };
        }
    };

    // --- LÓGICA DO AUTOCOMPLETAR PARA ADICIONAR MENSALIDADE ---

    addAlunoSearchInput.addEventListener('input', debounce(async () => {
        showLoader();
        const searchTerm = addAlunoSearchInput.value.trim();
        addAlunoResultsContainer.innerHTML = '';
        addAlunoResultsContainer.classList.add('hidden');
        addAlunoIdInput.value = ''; // Limpa o ID se o usuário mudar a busca

        if (searchTerm.length < 2) return;

        try {
            const response = await fetch(`${API_URL_ALUNOS}?search=${encodeURIComponent(searchTerm)}`, {
                headers: { 'Authorization': `Bearer ${getAuthData().token}` }
            });
            handleAuthError(response);
            const result = await response.json();

            if (result.success && result.data.length > 0) {
                result.data.forEach(aluno => {
                    const resultItem = document.createElement('div');
                    resultItem.className = 'p-2 hover:bg-indigo-100 cursor-pointer';
                    resultItem.textContent = `${aluno.nome_aluno} (RA: ${aluno.ra})`;
                    resultItem.addEventListener('click', () => {
                        addAlunoSearchInput.value = aluno.nome_aluno; // Preenche o campo de texto
                        addAlunoIdInput.value = aluno.id_aluno;       // Armazena o ID no campo oculto
                        addAlunoResultsContainer.classList.add('hidden'); // Esconde os resultados
                    });
                    addAlunoResultsContainer.appendChild(resultItem);
                });
                addAlunoResultsContainer.classList.remove('hidden');
            }
        } catch (error) {
            console.error('Erro na busca de alunos:', error);
        } finally {
                hideLoader();
            }
    }, 300));

    // Esconde os resultados se o usuário clicar fora
    document.addEventListener('click', (e) => {
        if (!addAlunoSearchInput.contains(e.target)) {
            addAlunoResultsContainer.classList.add('hidden');
        }
    });

    // --- LÓGICA PRINCIPAL DA PÁGINA ---

    const carregarMensalidades = async (searchTerm = '') => {
        showLoader();
        tabelaMensalidades.innerHTML = `<tr><td colspan="8" class="text-center py-4 text-gray-500">Buscando...</td></tr>`;
        const url = searchTerm ? `${API_URL_MENSALIDADES}?search=${encodeURIComponent(searchTerm)}` : API_URL_MENSALIDADES;

        try {
            const response = await fetch(url, { headers: { 'Authorization': `Bearer ${getAuthData().token}` } });
            handleAuthError(response);
            const result = await response.json();

            if (result.success) {
                tabelaMensalidades.innerHTML = '';
                if (result.data.length === 0) {
                    tabelaMensalidades.innerHTML = `<tr><td colspan="8" class="text-center py-4 text-gray-500">Nenhuma mensalidade encontrada.</td></tr>`;
                    return;
                }

                result.data.forEach(m => {
                    const statusInfo = getStatusBadge(m.status);
                    const valorTotalDevido = m.valor_total_devido || m.valor_mensalidade;
                    const juroDoMes = m.multa_aplicada || 0;
                    const juroDeMora = m.juros_aplicados || 0;

                    const row = `
                        <tr class="hover:bg-gray-50 transition-colors text-sm">
                            <td class="px-6 py-4 whitespace-nowrap font-medium text-gray-900">${m.nome_aluno}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-gray-600">${formatDate(m.data_vencimento)} ${m.dias_atraso > 0 ? `<span class="text-red-500 font-semibold ml-2">(${m.dias_atraso}d)</span>` : ''}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-gray-600">${formatCurrency(m.valor_mensalidade)}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-red-500">${formatCurrency(juroDoMes)}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-red-500">${formatCurrency(juroDeMora)}</td>
                            <td class="px-6 py-4 whitespace-nowrap"><span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${statusInfo.color}">${statusInfo.text}</span></td>
                            <td class="px-6 py-4 whitespace-nowrap font-bold ${m.status === 'atrasado' ? 'text-red-600' : 'text-gray-800'}">${formatCurrency(valorTotalDevido)}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-center font-medium space-x-2">${m.status !== 'pago' ? `<button data-id="${m.id_mensalidade}" data-valor="${valorTotalDevido}" class="btn-pagar text-green-600 hover:text-green-900">Pagar</button>` : `<span class="text-gray-400 cursor-not-allowed">Pago</span>`} <button data-id="${m.id_mensalidade}" class="btn-excluir text-red-600 hover:text-red-900">Excluir</button></td>
                        </tr>
                    `;
                    tabelaMensalidades.insertAdjacentHTML('beforeend', row);
                });
            } else { displayMessage(`Erro ao carregar mensalidades: ${result.message}`, 'error'); }
        } catch (error) { displayMessage('Não foi possível conectar ao servidor para carregar as mensalidades.', 'error'); 

        }finally {
                hideLoader();
            }
    };

    // Event listener para o campo de busca da tabela principal
    searchAlunoInput.addEventListener('input', debounce(() => {
        carregarMensalidades(searchAlunoInput.value);
    }, 300));

    // Event listener para o submit do formulário de adição
    formAddMensalidade.addEventListener('submit', async (e) => {
        showLoader();
        e.preventDefault();

        const alunoId = addAlunoIdInput.value;
        if (!alunoId) {
            alert('Por favor, busque e selecione um aluno da lista.');
            return;
        }

        const data = {
            id_aluno: alunoId,
            valor_mensalidade: document.getElementById('valor').value,
            data_vencimento: document.getElementById('vencimento').value,
        };

        try {
            const response = await fetch(API_URL_MENSALIDADES, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${getAuthData().token}` }, body: JSON.stringify(data) });
            handleAuthError(response);
            const result = await response.json();
            displayMessage(result.message, result.success ? 'success' : 'error');
            if (result.success) {
                formAddMensalidade.reset();
                addAlunoIdInput.value = '';
                carregarMensalidades();
            }
        } catch (error) {
            displayMessage('Não foi possível conectar ao servidor.', 'error');
        } finally {
                hideLoader();
            }
    });

    // Lógica para Pagar e Excluir
    tabelaMensalidades.addEventListener('click', (e) => {
        if (e.target.classList.contains('btn-pagar')) abrirModalPagamento(e.target.dataset.id, e.target.dataset.valor);
        if (e.target.classList.contains('btn-excluir')) if (confirm('Tem certeza que deseja excluir esta mensalidade?')) excluirMensalidade(e.target.dataset.id);
    });

    const excluirMensalidade = async (id) => {
        showLoader();
        try {
            const response = await fetch(`${API_URL_MENSALIDADES}/${id}`, { method: 'DELETE', headers: { 'Authorization': `Bearer ${getAuthData().token}` } });
            handleAuthError(response);
            const result = await response.json();
            displayMessage(result.message, result.success ? 'success' : 'error');
            if (result.success) carregarMensalidades(searchAlunoInput.value);
        } catch (error) {
            displayMessage('Não foi possível conectar ao servidor.', 'error');
        } finally {
                hideLoader();
            }
    };

    const abrirModalPagamento = (id, valor) => {
        inputIdMensalidade.value = id;
        inputValorPago.value = parseFloat(valor).toFixed(2);
        inputDataPagamento.value = new Date().toISOString().split('T')[0];
        modalPagamento.classList.remove('hidden');
        modalPagamento.classList.add('visible');
    };

    const fecharModalPagamento = () => {
        modalPagamento.classList.remove('visible');
        modalPagamento.classList.add('hidden');
        formPagamento.reset();
    };

    closeModalPagamento.addEventListener('click', fecharModalPagamento);
    window.addEventListener('click', (e) => {
        if (e.target === modalPagamento) fecharModalPagamento();
    });

    formPagamento.addEventListener('submit', async (e) => {
        showLoader();
        e.preventDefault();
        const id = inputIdMensalidade.value;
        const data = { valor_pago: inputValorPago.value, data_pagamento: inputDataPagamento.value };
        try {
            const response = await fetch(`${API_URL_MENSALIDADES}/${id}/pagar`, { method: 'PUT', headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${getAuthData().token}` }, body: JSON.stringify(data) });
            handleAuthError(response);
            const result = await response.json();
            if (result.success) {
                displayMessage('Pagamento registrado com sucesso!', 'success');
                fecharModalPagamento();
                carregarMensalidades(searchAlunoInput.value);
            } else {
                alert(`Erro: ${result.message}`);
            }
        } catch (error) {
            alert('Não foi possível conectar ao servidor.');
        } finally {
                hideLoader();
            }
    });

    const init = () => {
        const { token, role } = getAuthData();
        if (!token || (role !== 'admin' && role !== 'professor')) { logout(); return; }
        carregarMensalidades(); // Carga inicial
    };

    init();
});
