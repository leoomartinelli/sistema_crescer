<?php
// api/controllers/AlunoController.php

require_once __DIR__ . '/../models/Aluno.php';

class AlunoController {
    private $model;

    // Construtor que inicializa o modelo Aluno
    public function __construct() {
        $this->model = new Aluno();
    }

    /**
     * Obtém todos os alunos ou filtra por parâmetros de busca.
     * Métodos HTTP: GET
     * Parâmetros GET opcionais: nome, ra, turma (agora nome da turma)
     */
    public function getAll() {
        $searchName = isset($_GET['nome']) ? $_GET['nome'] : '';
        $searchRa = isset($_GET['ra']) ? $_GET['ra'] : '';
        $searchTurmaName = isset($_GET['turma']) ? $_GET['turma'] : '';

        $alunos = $this->model->getAll($searchName, $searchRa, $searchTurmaName);
        http_response_code(200); // OK
        echo json_encode(['success' => true, 'data' => $alunos]);
    }

    /**
     * Obtém um único aluno por ID.
     * Métodos HTTP: GET
     * Parâmetro de URL: id_aluno
     */
    public function getById($id) {
        if (!isset($id)) {
            http_response_code(400); // Bad Request
            echo json_encode(['success' => false, 'message' => 'ID do aluno ausente.']);
            return;
        }

        $aluno = $this->model->getById($id);
        if ($aluno) {
            http_response_code(200); // OK
            echo json_encode(['success' => true, 'data' => $aluno]);
        } else {
            http_response_code(404); // Not Found
            echo json_encode(['success' => false, 'message' => 'Aluno não encontrado.']);
        }
    }

    /**
     * Cria um novo aluno ou múltiplos alunos.
     * Métodos HTTP: POST
     * Corpo da requisição (JSON):
     * - Um único objeto JSON para um aluno.
     * - OU um array de objetos JSON para múltiplos alunos.
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

        // Verifica se é um array de alunos (criação em lote)
        if (is_array($data) && !empty($data) && isset($data[0]) && is_array($data[0])) {
            $isBatch = true;
            foreach ($data as $index => $alunoData) {
                // Validação básica para cada aluno no lote
                if (empty($alunoData['nome_aluno']) || empty($alunoData['ra'])) {
                    $results[] = ['success' => false, 'message' => "Aluno na posição {$index} falhou: Campos obrigatórios (nome_aluno, ra) ausentes.", 'aluno_data' => $alunoData];
                    continue; // Pula para o próximo aluno no lote
                }

                // Garante que id_turma seja int ou null para cada aluno
                $alunoData['id_turma'] = isset($alunoData['id_turma']) && $alunoData['id_turma'] !== '' ? (int)$alunoData['id_turma'] : null;

                $result = $this->model->create($alunoData);
                if ($result === true) {
                    $results[] = ['success' => true, 'message' => "Aluno '{$alunoData['nome_aluno']}' importado com sucesso."];
                } elseif ($result === 'ra_exists') {
                    $results[] = ['success' => false, 'message' => "Aluno '{$alunoData['nome_aluno']}' falhou: RA '{$alunoData['ra']}' já existe.", 'aluno_data' => $alunoData];
                } elseif ($result === 'parent_info_missing') {
                    $results[] = ['success' => false, 'message' => "Aluno '{$alunoData['nome_aluno']}' falhou: Pelo menos o nome e CPF do pai OU da mãe devem ser fornecidos.", 'aluno_data' => $alunoData];
                } else {
                    $results[] = ['success' => false, 'message' => "Aluno '{$alunoData['nome_aluno']}' falhou: Erro desconhecido ao criar.", 'aluno_data' => $alunoData];
                }
            }
        } else { // Criação de um único aluno
            // Validação dos campos obrigatórios para um único aluno
            if (empty($data['nome_aluno']) || empty($data['ra'])) {
                http_response_code(400); // Bad Request
                echo json_encode(['success' => false, 'message' => 'Campos obrigatórios (nome_aluno, ra) ausentes.']);
                return;
            }

            // Garante que id_turma seja int ou null para um único aluno
            $data['id_turma'] = isset($data['id_turma']) && $data['id_turma'] !== '' ? (int)$data['id_turma'] : null;

            $result = $this->model->create($data);
            if ($result === true) {
                http_response_code(201); // Created
                echo json_encode(['success' => true, 'message' => 'Aluno criado com sucesso!']);
                return;
            } elseif ($result === 'ra_exists') {
                http_response_code(409); // Conflict
                echo json_encode(['success' => false, 'message' => 'Já existe um aluno cadastrado com este RA.']);
                return;
            } elseif ($result === 'parent_info_missing') {
                http_response_code(400); // Bad Request
                echo json_encode(['success' => false, 'message' => 'Pelo menos o nome e CPF do pai OU da mãe devem ser fornecidos.']);
                return;
            } else {
                http_response_code(500); // Internal Server Error
                echo json_encode(['success' => false, 'message' => 'Erro ao criar aluno.']);
                return;
            }
        }

        // Se for um lote, retorna o resumo dos resultados
        if ($isBatch) {
            $totalSuccess = array_sum(array_column($results, 'success'));
            $totalFailed = count($results) - $totalSuccess;
            $overallSuccess = $totalSuccess > 0; // Considera sucesso se pelo menos um foi importado

            http_response_code($overallSuccess ? 200 : 400); // 200 OK se houver sucesso, 400 Bad Request se todos falharem
            echo json_encode([
                'success' => $overallSuccess,
                'message' => "Processamento de lote concluído. Total: " . count($data) . ", Sucessos: {$totalSuccess}, Falhas: {$totalFailed}.",
                'results' => $results
            ]);
        }
    }

    /**
     * Atualiza um aluno existente.
     * Métodos HTTP: PUT
     * Parâmetro de URL: id_aluno
     * Corpo da requisição (JSON): nome_aluno, ra (obrigatórios), e outros campos da tabela alunos,
     * incluindo id_turma, nome_pai, cpf_pai, nome_mae, cpf_mae (pelo menos um par nome/cpf deve ser preenchido),
     * e estado.
     */
    public function update($id) {
        $json_data = file_get_contents('php://input');
        $data = json_decode($json_data, true);

        // Validação do ID e campos obrigatórios
        if (!isset($id) || empty($data['nome_aluno']) || empty($data['ra'])) {
            http_response_code(400); // Bad Request
            echo json_encode(['success' => false, 'message' => 'Campos obrigatórios (id_aluno, nome_aluno, ra) ausentes.']);
            return;
        }
        $data['id_aluno'] = $id; // Garante que o ID da URL seja usado

        // Garante que id_turma seja int ou null
        $data['id_turma'] = isset($data['id_turma']) && $data['id_turma'] !== '' ? (int)$data['id_turma'] : null;

        $result = $this->model->update($data);
        if ($result === true) {
            http_response_code(200); // OK
            echo json_encode(['success' => true, 'message' => 'Aluno atualizado com sucesso!']);
        } elseif ($result === 'ra_exists') {
            http_response_code(409); // Conflict
            echo json_encode(['success' => false, 'message' => 'Já existe outro aluno cadastrado com este RA.']);
        } elseif ($result === 'parent_info_missing') { // Tratamento para validação de pais
            http_response_code(400); // Bad Request
            echo json_encode(['success' => false, 'message' => 'Pelo menos o nome e CPF do pai OU da mãe devem ser fornecidos.']);
        } else {
            http_response_code(500); // Internal Server Error
            echo json_encode(['success' => false, 'message' => 'Erro ao atualizar aluno.']);
        }
    }

    /**
     * Deleta um aluno.
     * Métodos HTTP: DELETE
     * Parâmetro de URL: id_aluno
     */
    public function delete($id) {
        if (!isset($id)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ID do aluno ausente.']);
            return;
        }

        if ($this->model->delete($id)) {
            http_response_code(200); // OK
            echo json_encode(['success' => true, 'message' => 'Aluno excluído com sucesso!']);
        } else {
            http_response_code(500); // Internal Server Error
            echo json_encode(['success' => false, 'message' => 'Erro ao excluir aluno.']);
        }
    }

    /**
     * Obtém alunos de uma turma específica.
     * Métodos HTTP: GET
     * Parâmetro de URL: id_turma
     */
    public function getAlunosByTurma($idTurma) {
        if (!isset($idTurma)) {
            http_response_code(400); // Bad Request
            echo json_encode(['success' => false, 'message' => 'ID da turma ausente.']);
            return;
        }

        $alunos = $this->model->getAlunosByTurmaId($idTurma);
        
        if ($alunos !== null) { // Verifica se a busca retornou um array (mesmo que vazio)
            http_response_code(200); // OK
            echo json_encode(['success' => true, 'data' => $alunos]);
        } else {
            http_response_code(500); // Internal Server Error, se houver erro na consulta do modelo
            echo json_encode(['success' => false, 'message' => 'Erro ao buscar alunos para esta turma.']);
        }
    }
}
