<?php
// api/controllers/MensalidadeController.php

require_once __DIR__ . '/../models/Mensalidade.php';

class MensalidadeController {
    private $model;

    public function __construct() {
        $this->model = new Mensalidade();
    }

    /**
     * Cria uma nova mensalidade ou múltiplas mensalidades.
     * Métodos HTTP: POST
     * Corpo da requisição (JSON):
     * - Um único objeto JSON para uma mensalidade: { "id_aluno": 1, "valor_mensalidade": 1000.00, "data_vencimento": "2025-01-10" }
     * - OU um array de objetos JSON para múltiplas mensalidades.
     */
    public function create() {
        $json_data = file_get_contents('php://input');
        $data = json_decode($json_data, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'JSON inválido fornecido.']);
            return;
        }

        $results = [];
        $isBatch = false;

        if (is_array($data) && !empty($data) && isset($data[0]) && is_array($data[0])) {
            $isBatch = true;
            foreach ($data as $index => $mensalidadeData) {
                if (empty($mensalidadeData['id_aluno']) || empty($mensalidadeData['valor_mensalidade']) || empty($mensalidadeData['data_vencimento'])) {
                    $results[] = ['success' => false, 'message' => "Mensalidade na posição {$index} falhou: Campos obrigatórios ausentes.", 'data' => $mensalidadeData];
                    continue;
                }
                $result = $this->model->create($mensalidadeData);
                if ($result) {
                    $results[] = ['success' => true, 'message' => "Mensalidade para aluno {$mensalidadeData['id_aluno']} criada com sucesso."];
                } else {
                    $results[] = ['success' => false, 'message' => "Mensalidade para aluno {$mensalidadeData['id_aluno']} falhou.", 'data' => $mensalidadeData];
                }
            }
        } else {
            if (empty($data['id_aluno']) || empty($data['valor_mensalidade']) || empty($data['data_vencimento'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Campos obrigatórios (id_aluno, valor_mensalidade, data_vencimento) ausentes.']);
                return;
            }
            $result = $this->model->create($data);
            if ($result) {
                http_response_code(201);
                echo json_encode(['success' => true, 'message' => 'Mensalidade criada com sucesso!']);
                return;
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Erro ao criar mensalidade.']);
                return;
            }
        }

        if ($isBatch) {
            $totalSuccess = array_sum(array_column($results, 'success'));
            $totalFailed = count($results) - $totalSuccess;
            $overallSuccess = $totalSuccess > 0;

            http_response_code($overallSuccess ? 200 : 400);
            echo json_encode([
                'success' => $overallSuccess,
                'message' => "Processamento de lote concluído. Total: " . count($data) . ", Sucessos: {$totalSuccess}, Falhas: {$totalFailed}.",
                'results' => $results
            ]);
        }
    }

    /**
     * Obtém todas as mensalidades ou filtra por parâmetros de busca.
     * Métodos HTTP: GET
     * Parâmetros GET opcionais: id_aluno, status, data_inicio, data_fim
     */
    public function getAll() {
        $filters = [
            'id_aluno' => isset($_GET['id_aluno']) ? $_GET['id_aluno'] : null,
            'status' => isset($_GET['status']) ? $_GET['status'] : null,
            'data_inicio' => isset($_GET['data_inicio']) ? $_GET['data_inicio'] : null,
            'data_fim' => isset($_GET['data_fim']) ? $_GET['data_fim'] : null,
        ];

        $mensalidades = $this->model->getAll($filters);
        http_response_code(200);
        echo json_encode(['success' => true, 'data' => $mensalidades]);
    }

    /**
     * Obtém uma única mensalidade por ID.
     * Métodos HTTP: GET
     * Parâmetro de URL: id_mensalidade
     */
    public function getById($id) {
        if (!isset($id)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ID da mensalidade ausente.']);
            return;
        }

        $mensalidade = $this->model->getById($id);
        if ($mensalidade) {
            http_response_code(200);
            echo json_encode(['success' => true, 'data' => $mensalidade]);
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Mensalidade não encontrada.']);
        }
    }

    /**
     * Marca uma mensalidade como paga.
     * Métodos HTTP: PUT
     * Parâmetro de URL: id_mensalidade
     * Corpo da requisição (JSON): { "data_pagamento": "YYYY-MM-DD", "valor_pago": 1020.00 }
     */
    public function markAsPaid($id) {
        $json_data = file_get_contents('php://input');
        $data = json_decode($json_data, true);

        if (!isset($id)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ID da mensalidade ausente.']);
            return;
        }

        if ($this->model->markAsPaid($id, $data)) {
            http_response_code(200);
            echo json_encode(['success' => true, 'message' => 'Mensalidade marcada como paga com sucesso!']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Erro ao marcar mensalidade como paga.']);
        }
    }

    /**
     * Obtém todas as mensalidades em atraso.
     * Métodos HTTP: GET
     * Parâmetro GET opcional: id_aluno (para filtrar mensalidades em atraso de um aluno específico)
     */
    public function getOverdue() {
        $filters = [
            'status' => 'atrasado',
            'id_aluno' => isset($_GET['id_aluno']) ? $_GET['id_aluno'] : null,
        ];
        // Força a reavaliação de status para 'atrasado' no getAll
        $mensalidades = $this->model->getAll($filters);
        
        // Filtra novamente para garantir apenas os que estão de fato atrasados após o cálculo
        $overdueMensalidades = array_filter($mensalidades, function($m) {
            return $m['status'] === 'atrasado';
        });

        http_response_code(200);
        echo json_encode(['success' => true, 'data' => array_values($overdueMensalidades)]);
    }
}