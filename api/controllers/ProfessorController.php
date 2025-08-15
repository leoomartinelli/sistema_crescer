<?php
// api/controllers/ProfessorController.php

require_once __DIR__ . '/../models/Professor.php';
require_once __DIR__ . '/../models/Turma.php'; // Para buscar a lista de turmas

class ProfessorController {
    private $professorModel;
    private $turmaModel;

    public function __construct() {
        $this->professorModel = new Professor();
        $this->turmaModel = new Turma(); // Instancia o modelo de Turma
    }

    private function sendResponse($statusCode, $data) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
            http_response_code($statusCode);
        }
        echo json_encode($data);
    }

    public function getAll() {
        try {
            $professores = $this->professorModel->getAll();
            $this->sendResponse(200, ['success' => true, 'data' => $professores]);
        } catch (Exception $e) {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro no servidor ao buscar professores: ' . $e->getMessage()]);
        }
    }

    public function getById($id) {
        if (!isset($id) || !is_numeric($id)) {
            $this->sendResponse(400, ['success' => false, 'message' => 'ID do professor inválido.']);
            return;
        }
        $professor = $this->professorModel->getById((int)$id);
        if ($professor) {
            $this->sendResponse(200, ['success' => true, 'data' => $professor]);
        } else {
            $this->sendResponse(404, ['success' => false, 'message' => 'Professor não encontrado.']);
        }
    }

    public function create() {
        $data = json_decode(file_get_contents('php://input'), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->sendResponse(400, ['success' => false, 'message' => 'JSON inválido fornecido.']);
            return;
        }

        if (empty($data['nome_professor']) || empty($data['cpf'])) {
            $this->sendResponse(400, ['success' => false, 'message' => 'Campos obrigatórios (Nome do Professor, CPF) ausentes.']);
            return;
        }
        
        $result = $this->professorModel->create($data);

        if ($result === true) {
            $this->sendResponse(201, ['success' => true, 'message' => 'Professor criado com sucesso!']);
        } elseif ($result === 'cpf_exists') {
            $this->sendResponse(409, ['success' => false, 'message' => 'Já existe um professor cadastrado com este CPF.']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao criar professor.']);
        }
    }

    public function update($id) {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($id) || !is_numeric($id) || empty($data['nome_professor']) || empty($data['cpf'])) {
            $this->sendResponse(400, ['success' => false, 'message' => 'Campos obrigatórios (ID, Nome do Professor, CPF) ausentes.']);
            return;
        }
        $data['id_professor'] = (int)$id;

        $result = $this->professorModel->update($data);

        if ($result === true) {
            $this->sendResponse(200, ['success' => true, 'message' => 'Professor atualizado com sucesso!']);
        } elseif ($result === 'cpf_exists') {
            $this->sendResponse(409, ['success' => false, 'message' => 'Já existe outro professor cadastrado com este CPF.']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao atualizar professor.']);
        }
    }

    public function delete($id) {
        if (!isset($id) || !is_numeric($id)) {
            $this->sendResponse(400, ['success' => false, 'message' => 'ID do professor inválido.']);
            return;
        }
        if ($this->professorModel->delete((int)$id)) {
            $this->sendResponse(200, ['success' => true, 'message' => 'Professor excluído com sucesso!']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao excluir professor.']);
        }
    }

    // Métodos para gerenciar associações professor-turma

    public function getProfessorTurmas($idProfessor) {
        if (!isset($idProfessor) || !is_numeric($idProfessor)) {
            $this->sendResponse(400, ['success' => false, 'message' => 'ID do professor inválido.']);
            return;
        }
        $turmas = $this->professorModel->getTurmasByProfessorId((int)$idProfessor);
        $this->sendResponse(200, ['success' => true, 'data' => $turmas]);
    }

    public function addTurmaToProfessor($idProfessor) {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($idProfessor) || !is_numeric($idProfessor) || empty($data['id_turma']) || empty($data['data_inicio_lecionar'])) {
            $this->sendResponse(400, ['success' => false, 'message' => 'Dados de associação (ID Professor, ID Turma, Data Início) ausentes.']);
            return;
        }
        
        $result = $this->professorModel->addTurmaToProfessor(
            (int)$idProfessor,
            (int)$data['id_turma'],
            $data['data_inicio_lecionar'],
            $data['data_fim_lecionar'] ?? null
        );

        if ($result === true) {
            $this->sendResponse(201, ['success' => true, 'message' => 'Turma associada ao professor com sucesso!']);
        } elseif ($result === 'already_exists') {
             $this->sendResponse(409, ['success' => false, 'message' => 'Esta turma já está associada a este professor.']);
        }
        else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao associar turma ao professor.']);
        }
    }

    public function removeTurmaFromProfessor($idProfessor, $idTurma) {
        if (!isset($idProfessor) || !is_numeric($idProfessor) || !isset($idTurma) || !is_numeric($idTurma)) {
            $this->sendResponse(400, ['success' => false, 'message' => 'IDs (Professor, Turma) inválidos.']);
            return;
        }
        if ($this->professorModel->removeTurmaFromProfessor((int)$idProfessor, (int)$idTurma)) {
            $this->sendResponse(200, ['success' => true, 'message' => 'Associação de turma removida com sucesso!']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao remover associação de turma.']);
        }
    }

    /**
     * Obtém os detalhes do professor logado e suas turmas.
     * Esta função será chamada pela dashboard do professor.
     */
    public function getDashboardData($userId) {
        $professor = $this->professorModel->getProfessorByUserId($userId);
        if (!$professor) {
            $this->sendResponse(404, ['success' => false, 'message' => 'Dados do professor não encontrados.']);
            return;
        }

        $turmas = $this->professorModel->getTurmasByProfessorId($professor['id_professor']);
        
        $this->sendResponse(200, [
            'success' => true,
            'data' => [
                'professor' => $professor,
                'turmas' => $turmas
            ]
        ]);
    }
}