<?php
// api/controllers/TurmaController.php

require_once __DIR__ . '/../models/Turma.php';

class TurmaController {
    private $model;

    public function __construct() {
        $this->model = new Turma();
    }

    /**
     * Obtém todas as turmas.
     * Métodos HTTP: GET
     */
    public function getAll() {
        $turmas = $this->model->getAll();
        http_response_code(200); // OK
        echo json_encode(['success' => true, 'data' => $turmas]);
    }

    /**
     * Obtém uma única turma por ID.
     * Métodos HTTP: GET
     * Parâmetro de URL: id_turma
     */
    public function getById($id) {
        if (!isset($id)) {
            http_response_code(400); // Bad Request
            echo json_encode(['success' => false, 'message' => 'ID da turma ausente.']);
            return;
        }

        $turma = $this->model->getById($id);
        if ($turma) {
            http_response_code(200); // OK
            echo json_encode(['success' => true, 'data' => $turma]);
        } else {
            http_response_code(404); // Not Found
            echo json_encode(['success' => false, 'message' => 'Turma não encontrada.']);
        }
    }

    /**
     * Cria uma nova turma ou múltiplas turmas.
     * Métodos HTTP: POST
     * Corpo da requisição (JSON):
     * - Um único objeto JSON para uma turma.
     * - OU um array de objetos JSON para múltiplas turmas.
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

        // Verifica se é um array de turmas (criação em lote)
        if (is_array($data) && !empty($data) && isset($data[0]) && is_array($data[0])) {
            $isBatch = true;
            foreach ($data as $index => $turmaData) {
                // Validação básica para cada turma no lote
                if (empty($turmaData['nome_turma'])) {
                    $results[] = ['success' => false, 'message' => "Turma na posição {$index} falhou: Campo obrigatório (nome_turma) ausente.", 'turma_data' => $turmaData];
                    continue; // Pula para a próxima turma no lote
                }

                // Define valores padrão para campos opcionais se não forem fornecidos
                $turmaData['periodo'] = $turmaData['periodo'] ?? null;
                $turmaData['ano_letivo'] = $turmaData['ano_letivo'] ?? null;
                $turmaData['descricao'] = $turmaData['descricao'] ?? null;

                $result = $this->model->create($turmaData);
                if ($result === true) {
                    $results[] = ['success' => true, 'message' => "Turma '{$turmaData['nome_turma']}' criada com sucesso."];
                } elseif ($result === 'nome_exists') {
                    $results[] = ['success' => false, 'message' => "Turma '{$turmaData['nome_turma']}' falhou: Nome de turma já existe.", 'turma_data' => $turmaData];
                } else {
                    $results[] = ['success' => false, 'message' => "Turma '{$turmaData['nome_turma']}' falhou: Erro desconhecido ao criar.", 'turma_data' => $turmaData];
                }
            }
        } else { // Criação de uma única turma
            // Validação dos campos obrigatórios para uma única turma
            if (empty($data['nome_turma'])) {
                http_response_code(400); // Bad Request
                echo json_encode(['success' => false, 'message' => 'Campo obrigatório (nome_turma) ausente.']);
                return;
            }

            // Define valores padrão para campos opcionais se não forem fornecidos
            $data['periodo'] = $data['periodo'] ?? null;
            $data['ano_letivo'] = $data['ano_letivo'] ?? null;
            $data['descricao'] = $data['descricao'] ?? null;

            $result = $this->model->create($data);
            if ($result === true) {
                http_response_code(201); // Created
                echo json_encode(['success' => true, 'message' => 'Turma criada com sucesso!']);
                return;
            } elseif ($result === 'nome_exists') {
                http_response_code(409); // Conflict
                echo json_encode(['success' => false, 'message' => 'Já existe uma turma com este nome.']);
                return;
            } else {
                http_response_code(500); // Internal Server Error
                echo json_encode(['success' => false, 'message' => 'Erro ao criar turma.']);
                return;
            }
        }

        // Se for um lote, retorna o resumo dos resultados
        if ($isBatch) {
            $totalSuccess = array_sum(array_column($results, 'success'));
            $totalFailed = count($results) - $totalSuccess;
            $overallSuccess = $totalSuccess > 0; // Considera sucesso se pelo menos uma foi criada

            http_response_code($overallSuccess ? 200 : 400); // 200 OK se houver sucesso, 400 Bad Request se todas falharem
            echo json_encode([
                'success' => $overallSuccess,
                'message' => "Processamento de lote concluído. Total: " . count($data) . ", Sucessos: {$totalSuccess}, Falhas: {$totalFailed}.",
                'results' => $results
            ]);
        }
    }

    /**
     * Atualiza uma turma existente.
     * Métodos HTTP: PUT
     * Parâmetro de URL: id_turma
     * Corpo da requisição (JSON): nome_turma (obrigatório), periodo, ano_letivo, descricao.
     */
    public function update($id) {
        $json_data = file_get_contents('php://input');
        $data = json_decode($json_data, true);

        if (!isset($id) || !isset($data['nome_turma'])) {
            http_response_code(400); // Bad Request
            echo json_encode(['success' => false, 'message' => 'Campos obrigatórios (id_turma, nome_turma) ausentes.']);
            return;
        }
        $data['id_turma'] = $id; // Garante que o ID da URL seja usado

        // Define valores padrão para campos opcionais se não forem fornecidos
        $data['periodo'] = $data['periodo'] ?? null;
        $data['ano_letivo'] = $data['ano_letivo'] ?? null;
        $data['descricao'] = $data['descricao'] ?? null;

        $result = $this->model->update($data);
        if ($result === true) {
            http_response_code(200); // OK
            echo json_encode(['success' => true, 'message' => 'Turma atualizada com sucesso!']);
        } elseif ($result === 'nome_exists') {
            http_response_code(409); // Conflict
            echo json_encode(['success' => false, 'message' => 'Já existe outra turma com este nome.']);
        } else {
            http_response_code(500); // Internal Server Error
            echo json_encode(['success' => false, 'message' => 'Erro ao atualizar turma.']);
        }
    }

    /**
     * Deleta uma turma.
     * Métodos HTTP: DELETE
     * Parâmetro de URL: id_turma
     */
    public function delete($id) {
        if (!isset($id)) {
            http_response_code(400); // Bad Request
            echo json_encode(['success' => false, 'message' => 'ID da turma ausente.']);
            return;
        }

        if ($this->model->delete($id)) {
            http_response_code(200); // OK
            echo json_encode(['success' => true, 'message' => 'Turma excluída com sucesso!']);
        } else {
            http_response_code(500); // Internal Server Error
            echo json_encode(['success' => false, 'message' => 'Erro ao excluir turma.']);
        }
    }
}
