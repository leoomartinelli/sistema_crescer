<?php
// api/controllers/BoletimController.php

require_once __DIR__ . '/../models/Boletim.php';
require_once __DIR__ . '/../models/Aluno.php';
require_once __DIR__ . '/../models/Disciplina.php';

class BoletimController {
    private $boletimModel;
    private $alunoModel;
    private $disciplinaModel;

    public function __construct() {
        $this->boletimModel = new Boletim();
        $this->alunoModel = new Aluno();
        $this->disciplinaModel = new Disciplina();
    }

    private function sendResponse($statusCode, $data) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
            http_response_code($statusCode);
        }
        echo json_encode($data);
    }

    /**
     * Obtém o boletim de um aluno para um ano específico.
     * Requer id_aluno e ano_letivo como parâmetros GET.
     */
    public function getBoletimAluno() {
        $idAluno = isset($_GET['id_aluno']) ? (int)$_GET['id_aluno'] : null;
        $anoLetivo = isset($_GET['ano_letivo']) ? (int)$_GET['ano_letivo'] : null;

        if (!$idAluno || !$anoLetivo) {
            $this->sendResponse(400, ['success' => false, 'message' => 'ID do aluno e ano letivo são obrigatórios.']);
            return;
        }

        $boletim = $this->boletimModel->getBoletimByAlunoAndAno($idAluno, $anoLetivo);
        $this->sendResponse(200, ['success' => true, 'data' => $boletim]);
    }

    /**
     * Salva (cria ou atualiza) uma entrada de boletim.
     * Espera um JSON com os dados do boletim.
     */
    public function saveBoletimEntry() {
        $data = json_decode(file_get_contents('php://input'), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->sendResponse(400, ['success' => false, 'message' => 'JSON inválido fornecido.']);
            return;
        }

        // Validação básica dos campos obrigatórios
        if (empty($data['id_aluno']) || empty($data['id_turma']) || empty($data['ano_letivo']) || empty($data['id_disciplina'])) {
            $this->sendResponse(400, ['success' => false, 'message' => 'Campos obrigatórios (id_aluno, id_turma, ano_letivo, id_disciplina) ausentes.']);
            return;
        }

        // Converter notas e faltas para float/int ou null se vazias
        foreach (['nota_1b', 'nota_2b', 'nota_3b', 'nota_4b', 'media_final', 'frequencia_final', 'recuperacao_final'] as $field) {
            $data[$field] = isset($data[$field]) && $data[$field] !== '' ? (float)$data[$field] : null;
        }
        foreach (['faltas_1b', 'faltas_2b', 'faltas_3b', 'faltas_4b'] as $field) {
            $data[$field] = isset($data[$field]) && $data[$field] !== '' ? (int)$data[$field] : null;
        }

        // Garantir que outros campos textuais sejam null se vazios
        $data['resultado_final'] = $data['resultado_final'] ?? null;
        $data['observacoes'] = $data['observacoes'] ?? null;

        if ($this->boletimModel->saveBoletimEntry($data)) {
            $this->sendResponse(200, ['success' => true, 'message' => 'Boletim salvo com sucesso!']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao salvar boletim.']);
        }
    }

    /**
     * Deleta uma entrada de boletim.
     * @param int $idBoletim
     */
    public function deleteBoletimEntry($idBoletim) {
        if (!isset($idBoletim) || !is_numeric($idBoletim)) {
            $this->sendResponse(400, ['success' => false, 'message' => 'ID do boletim inválido.']);
            return;
        }
        if ($this->boletimModel->delete((int)$idBoletim)) {
            $this->sendResponse(200, ['success' => true, 'message' => 'Entrada de boletim excluída com sucesso!']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao excluir entrada de boletim.']);
        }
    }

    /**
     * Prepara os dados para a tela de gerenciamento de boletim.
     * Retorna a lista de alunos de uma turma e as disciplinas associadas àquela turma.
     * @param int $idTurma
     */
    public function getBoletimManagementData($idTurma) {
        if (!isset($idTurma) || !is_numeric($idTurma)) {
            $this->sendResponse(400, ['success' => false, 'message' => 'ID da turma inválido.']);
            return;
        }

        $alunos = $this->alunoModel->getAlunosByTurmaId((int)$idTurma);
        $disciplinas = $this->disciplinaModel->getDisciplinasByTurmaId((int)$idTurma);
        
        if ($alunos !== null && $disciplinas !== null) {
            $this->sendResponse(200, [
                'success' => true,
                'data' => [
                    'alunos' => $alunos,
                    'disciplinas' => $disciplinas
                ]
            ]);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao carregar dados para gerenciamento de boletim.']);
        }
    }
}