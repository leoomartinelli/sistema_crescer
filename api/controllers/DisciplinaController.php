<?php
// api/controllers/DisciplinaController.php

require_once __DIR__ . '/../models/Disciplina.php';
require_once __DIR__ . '/../models/Turma.php'; // Para carregar turmas no select

class DisciplinaController {
    private $model;
    private $turmaModel;

    public function __construct() {
        $this->model = new Disciplina();
        $this->turmaModel = new Turma();
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
            $disciplinas = $this->model->getAll();
            $this->sendResponse(200, ['success' => true, 'data' => $disciplinas]);
        } catch (Exception $e) {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao buscar disciplinas: ' . $e->getMessage()]);
        }
    }

    public function getById($id) {
        if (!isset($id) || !is_numeric($id)) {
            $this->sendResponse(400, ['success' => false, 'message' => 'ID da disciplina inválido.']);
            return;
        }
        $disciplina = $this->model->getById((int)$id);
        if ($disciplina) {
            $this->sendResponse(200, ['success' => true, 'data' => $disciplina]);
        } else {
            $this->sendResponse(404, ['success' => false, 'message' => 'Disciplina não encontrada.']);
        }
    }

    public function create() {
        $data = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->sendResponse(400, ['success' => false, 'message' => 'JSON inválido fornecido.']);
            return;
        }

        if (empty($data['nome_disciplina'])) {
            $this->sendResponse(400, ['success' => false, 'message' => 'Campo obrigatório (nome_disciplina) ausente.']);
            return;
        }
        
        $result = $this->model->create($data);

        if ($result === true) {
            $this->sendResponse(201, ['success' => true, 'message' => 'Disciplina criada com sucesso!']);
        } elseif ($result === 'name_exists') {
            $this->sendResponse(409, ['success' => false, 'message' => 'Já existe uma disciplina com este nome.']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao criar disciplina.']);
        }
    }

    public function update($id) {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($id) || !is_numeric($id) || empty($data['nome_disciplina'])) {
            $this->sendResponse(400, ['success' => false, 'message' => 'Campos obrigatórios (ID, nome_disciplina) ausentes.']);
            return;
        }
        $data['id_disciplina'] = (int)$id;

        $result = $this->model->update($data);

        if ($result === true) {
            $this->sendResponse(200, ['success' => true, 'message' => 'Disciplina atualizada com sucesso!']);
        } elseif ($result === 'name_exists') {
            $this->sendResponse(409, ['success' => false, 'message' => 'Já existe outra disciplina com este nome.']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao atualizar disciplina.']);
        }
    }

    public function delete($id) {
        if (!isset($id) || !is_numeric($id)) {
            $this->sendResponse(400, ['success' => false, 'message' => 'ID da disciplina inválido.']);
            return;
        }
        if ($this->model->delete((int)$id)) {
            $this->sendResponse(200, ['success' => true, 'message' => 'Disciplina excluída com sucesso!']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao excluir disciplina.']);
        }
    }

    // Métodos para gerenciar associações turma-disciplina

    public function getTurmaDisciplinas($idTurma) {
        if (!isset($idTurma) || !is_numeric($idTurma)) {
            $this->sendResponse(400, ['success' => false, 'message' => 'ID da turma inválido.']);
            return;
        }
        $disciplinas = $this->model->getDisciplinasByTurmaId((int)$idTurma);
        $this->sendResponse(200, ['success' => true, 'data' => $disciplinas]);
    }

    public function addDisciplinaToTurma($idTurma) {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($idTurma) || !is_numeric($idTurma) || empty($data['id_disciplina'])) {
            $this->sendResponse(400, ['success' => false, 'message' => 'Dados de associação (ID Turma, ID Disciplina) ausentes.']);
            return;
        }
        
        $result = $this->model->addDisciplinaToTurma(
            (int)$idTurma,
            (int)$data['id_disciplina']
        );

        if ($result === true) {
            $this->sendResponse(201, ['success' => true, 'message' => 'Disciplina associada à turma com sucesso!']);
        } elseif ($result === 'already_exists') {
             $this->sendResponse(409, ['success' => false, 'message' => 'Esta disciplina já está associada a esta turma.']);
        }
        else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao associar disciplina à turma.']);
        }
    }

    public function removeDisciplinaFromTurma($idTurma, $idDisciplina) {
        if (!isset($idTurma) || !is_numeric($idTurma) || !isset($idDisciplina) || !is_numeric($idDisciplina)) {
            $this->sendResponse(400, ['success' => false, 'message' => 'IDs (Turma, Disciplina) inválidos.']);
            return;
        }
        if ($this->model->removeDisciplinaFromTurma((int)$idTurma, (int)$idDisciplina)) {
            $this->sendResponse(200, ['success' => true, 'message' => 'Associação de disciplina removida com sucesso!']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao remover associação de disciplina.']);
        }
    }

    /**
     * Obtém as turmas associadas a uma disciplina específica.
     * @param int $idDisciplina
     */
    public function getDisciplinasAssociatedTurmas($idDisciplina) {
        if (!isset($idDisciplina) || !is_numeric($idDisciplina)) {
            $this->sendResponse(400, ['success' => false, 'message' => 'ID da disciplina inválido.']);
            return;
        }
        $turmas = $this->model->getTurmasByDisciplinaId((int)$idDisciplina);
        $this->sendResponse(200, ['success' => true, 'data' => $turmas]);
    }
}