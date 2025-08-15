<?php
// api/controllers/MensalidadeController.php

date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/../models/Mensalidade.php';
require_once __DIR__ . '/../models/Aluno.php';

class MensalidadeController {
    private $mensalidadeModel;

    public function __construct() {
        $this->mensalidadeModel = new Mensalidade();
    }

    private function sendResponse($statusCode, $data) {
        header('Content-Type: application/json');
        http_response_code($statusCode);
        echo json_encode($data);
    }

    public function create() {
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['id_aluno']) || empty($data['valor_mensalidade']) || empty($data['data_vencimento'])) {
            $this->sendResponse(400, ['success' => false, 'message' => 'Dados incompletos. Por favor, forneça o aluno, o valor e a data de vencimento.']);
            return;
        }
        try {
            $startDate = new DateTime($data['data_vencimento']);
            $startMonth = (int)$startDate->format('m');
            $startYear = (int)$startDate->format('Y');
            $dueDay = (int)$startDate->format('d');
            $successCount = 0; $failCount = 0; $totalMonths = 0;
            for ($month = $startMonth; $month <= 12; $month++) {
                $totalMonths++;
                $currentDueDate = clone $startDate;
                $currentDueDate->setDate($startYear, $month, $dueDay);
                $monthlyData = ['id_aluno' => $data['id_aluno'], 'valor_mensalidade' => $data['valor_mensalidade'], 'data_vencimento' => $currentDueDate->format('Y-m-d')];
                if ($this->mensalidadeModel->create($monthlyData)) {
                    $successCount++;
                } else {
                    $failCount++;
                }
            }
            if ($successCount > 0) {
                $message = "{$successCount} de {$totalMonths} mensalidades foram criadas com sucesso.";
                if ($failCount > 0) $message .= " {$failCount} falharam (possivelmente por já existirem).";
                $this->sendResponse(201, ['success' => true, 'message' => $message]);
            } else {
                $this->sendResponse(500, ['success' => false, 'message' => 'Nenhuma mensalidade pôde ser criada.']);
            }
        } catch (Exception $e) {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro no servidor: ' . $e->getMessage()]);
        }
    }

    public function getAll() {
        try {
            $searchTerm = isset($_GET['search']) ? $_GET['search'] : null;
            $mensalidades = $this->mensalidadeModel->readAll($searchTerm);
            
            $mensalidades = $this->processarEncargos($mensalidades);

            $this->sendResponse(200, ['success' => true, 'data' => $mensalidades]);
        } catch (Exception $e) {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro no servidor ao buscar mensalidades: ' . $e->getMessage()]);
        }
    }
    
    public function getByAluno($ra) {
        try {
            $mensalidades = $this->mensalidadeModel->readByAlunoRa($ra);
            if ($mensalidades === false) {
                $this->sendResponse(404, ['success' => false, 'message' => 'Aluno não encontrado com o RA fornecido.']);
                return;
            }

            $mensalidades = $this->processarEncargos($mensalidades);

            $this->sendResponse(200, ['success' => true, 'data' => $mensalidades]);
        } catch (Exception $e) {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro no servidor ao buscar mensalidades do aluno: ' . $e->getMessage()]);
        }
    }

    /**
     * Função centralizada para calcular os encargos de uma lista de mensalidades.
     * @param array $mensalidades - A lista de mensalidades a ser processada.
     * @return array - A lista de mensalidades com os encargos calculados.
     */
    private function processarEncargos($mensalidades) {
        $hoje = new DateTime();
        // **CORREÇÃO**: Usando os status do ENUM que indicam que a cobrança não deve prosseguir.
        $paidStatuses = ['approved', 'refunded', 'charged_back', 'cancelled'];

        foreach ($mensalidades as &$mensalidade) {
            $status = strtolower($mensalidade['status']);

            // Se o status indica que já foi pago ou cancelado, não calcula juros.
            if (in_array($status, $paidStatuses)) {
                $mensalidade['valor_total_devido'] = (float)($mensalidade['valor_pago'] ?? $mensalidade['valor_mensalidade']);
                continue;
            }

            $dataVencimento = new DateTime($mensalidade['data_vencimento']);

            // **CORREÇÃO**: Verifica se a data de vencimento passou e se o status não é um dos que encerram a cobrança.
            // A tentativa de mudar o status para 'atrasado' foi removida para evitar o erro do ENUM.
            if ($hoje > $dataVencimento && !in_array($status, $paidStatuses)) {
                
                // Calcula os encargos pois está comprovadamente atrasado.
                // A função calcularEncargosAtraso já atualiza os valores no banco de dados.
                $dadosCalculados = $this->mensalidadeModel->calcularEncargosAtraso($mensalidade['id_mensalidade']);
                if ($dadosCalculados) {
                    $mensalidade = array_merge($mensalidade, $dadosCalculados);
                }

            } else {
                // Se não estiver atrasado, o valor total é o valor base.
                $mensalidade['valor_total_devido'] = (float)$mensalidade['valor_mensalidade'];
            }
        }
        return $mensalidades;
    }

    // O resto dos métodos (registerPayment, delete, getMensalidadeDetails, etc.) permanece o mesmo...

    public function registerPayment($id) {
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['valor_pago']) || empty($data['data_pagamento'])) {
            $this->sendResponse(400, ['success' => false, 'message' => 'Dados de pagamento incompletos.']);
            return;
        }
        $data['id_mensalidade'] = $id;
        try {
            if ($this->mensalidadeModel->registerPayment($data)) {
                $this->sendResponse(200, ['success' => true, 'message' => 'Pagamento registrado com sucesso.']);
            } else {
                $this->sendResponse(500, ['success' => false, 'message' => 'Falha ao registrar o pagamento.']);
            }
        } catch (Exception $e) {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro no servidor: ' . $e->getMessage()]);
        }
    }

    public function delete($id) {
        try {
            if ($this->mensalidadeModel->delete($id)) {
                $this->sendResponse(200, ['success' => true, 'message' => 'Mensalidade deletada com sucesso.']);
            } else {
                $this->sendResponse(404, ['success' => false, 'message' => 'Mensalidade não encontrada.']);
            }
        } catch (Exception $e) {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro no servidor: ' . $e->getMessage()]);
        }
    }

    public function getMensalidadeDetails($id) {
        $userRole = $GLOBALS['user_data']['role'] ?? null;
        $username = $GLOBALS['user_data']['username'] ?? null;
        $mensalidade = $this->mensalidadeModel->readById((int)$id);
        if (!$mensalidade) {
            $this->sendResponse(404, ['success' => false, 'message' => 'Mensalidade não encontrada.']);
            return;
        }
        if ($userRole === 'aluno') {
            $alunoModel = new Aluno();
            $alunoDaMensalidade = $alunoModel->getById($mensalidade['id_aluno']);
            if (!$alunoDaMensalidade || $alunoDaMensalidade['ra'] !== $username) {
                $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
                return;
            }
        }
        $data = ['status' => $mensalidade['status'], 'pix_qr_code_base64' => $mensalidade['pix_qr_code_base64'], 'pix_copia_e_cola' => $mensalidade['pix_copia_e_cola'], 'pix_expiration_time' => $mensalidade['pix_expiration_time']];
        $this->sendResponse(200, ['success' => true, 'data' => $data]);
    }

    public function updateStudentMensalidadeStatus($id) {
        $alunoRa = $GLOBALS['user_data']['username'] ?? null;
        if (!$alunoRa) {
            $this->sendResponse(401, ['success' => false, 'message' => 'Não foi possível identificar o aluno a partir do token.']);
            return;
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $newStatus = $data['status'] ?? null;
        if (empty($newStatus)) {
            $this->sendResponse(400, ['success' => false, 'message' => 'O novo status não foi fornecido.']);
            return;
        }
        $mensalidade = $this->mensalidadeModel->readById((int)$id);
        if (!$mensalidade) {
            $this->sendResponse(404, ['success' => false, 'message' => 'Mensalidade não encontrada.']);
            return;
        }
        $alunoModel = new Aluno();
        $alunoDaMensalidade = $alunoModel->getById($mensalidade['id_aluno']);
        if (!$alunoDaMensalidade || $alunoDaMensalidade['ra'] !== $alunoRa) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado. Esta mensalidade não pertence a você.']);
            return;
        }
        if ($this->mensalidadeModel->updateStatus((int)$id, $newStatus)) {
            $this->sendResponse(200, ['success' => true, 'message' => 'Status da mensalidade atualizado com sucesso.']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Falha ao atualizar o status da mensalidade.']);
        }
    }
}