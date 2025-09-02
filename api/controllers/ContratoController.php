<?php
// api/controllers/ContratoController.php

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

require_once __DIR__ . '/../models/Contrato.php';
require_once __DIR__ . '/../models/Usuario.php';
require_once __DIR__ . '/../../config/Auth.php';


class ContratoController
{
    private $contratoModel;

    public function __construct()
    {
        $this->contratoModel = new Contrato();
    }

    private function sendResponse($statusCode, $data)
    {
        header('Content-Type: application/json');
        http_response_code($statusCode);
        echo json_encode($data);
    }

    public function getAllContratos()
    {
        header('Content-Type: application/json');
        $nomeAluno = $_GET['nome'] ?? null;
        $contratos = $this->contratoModel->getAllContratosComAlunos($nomeAluno);
        echo json_encode(['success' => true, 'data' => $contratos]);
    }

     public function validarContrato($idContrato)
    {
        header('Content-Type: application/json');

        // Passo 1: Buscar dados do contrato para obter o id_aluno
        $contrato = $this->contratoModel->findById($idContrato);
        if (!$contrato) {
            $this->sendResponse(404, ['success' => false, 'message' => 'Contrato não encontrado.']);
            return;
        }

        // Passo 2: Marcar o contrato como validado no banco de dados
        $successValidation = $this->contratoModel->marcarComoValidado($idContrato);

        if ($successValidation) {
            // Passo 3: Atualizar a role do usuário para 'aluno', liberando o acesso
            $usuarioModel = new Usuario();
            $idAluno = $contrato['id_aluno'];

            $successRoleUpdate = $usuarioModel->liberarAcessoAluno($idAluno);

            if ($successRoleUpdate) {
                $this->sendResponse(200, ['success' => true, 'message' => 'Contrato validado e acesso do aluno liberado com sucesso.']);
            } else {
                $this->sendResponse(500, ['success' => false, 'message' => 'O contrato foi validado, mas falhou ao liberar o acesso do aluno. Verifique o cadastro do usuário.']);
            }
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Falha ao validar o contrato.']);
        }
    }


   public function uploadAssinado()
    {
        header('Content-Type: application/json');

        $token = getAuthToken();
        if (!$token) {
            $this->sendResponse(401, ['success' => false, 'message' => 'Token JWT ausente.']);
            return;
        }

        try {
            $decoded = JWT::decode($token, new Key(JWT_SECRET_KEY, JWT_ALGORITHM));
            $tokenData = (array) $decoded->data;

            if (($tokenData['role'] ?? '') !== 'aluno_pendente') {
                $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado. Token inválido para esta ação.']);
                return;
            }

            if (!isset($_POST['contract_id']) || !isset($_FILES['signed_pdf'])) {
                $this->sendResponse(400, ['success' => false, 'message' => 'Campos obrigatórios ausentes.']);
                return;
            }

            $contratoId = $_POST['contract_id'];
            $file = $_FILES['signed_pdf'];

            if ($file['error'] !== UPLOAD_ERR_OK || $file['type'] !== 'application/pdf') {
                $this->sendResponse(400, ['success' => false, 'message' => 'Erro no upload ou formato de arquivo inválido.']);
                return;
            }

            $uploadDir = __DIR__ . '/../../uploads/contratos_assinados/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $fileName = uniqid('signed_') . '_' . basename($file['name']);
            $filePath = $uploadDir . $fileName;
            $relativePath = 'uploads/contratos_assinados/' . $fileName;

            if (!move_uploaded_file($file['tmp_name'], $filePath)) {
                $this->sendResponse(500, ['success' => false, 'message' => 'Falha ao salvar o arquivo no servidor.']);
                return;
            }

            $success = $this->contratoModel->updateAssinatura($contratoId, $relativePath);

            if (!$success) {
                $this->sendResponse(500, ['success' => false, 'message' => 'Falha ao atualizar o registro do contrato.']);
                return;
            }

            // VERSÃO CORRETA: Apenas envia uma mensagem de sucesso, SEM gerar um novo token.
            $this->sendResponse(200, [
                'success' => true,
                'message' => 'Seu contrato foi recebido com sucesso! A secretaria fará a análise e, assim que for validado, seu acesso ao portal será liberado.'
            ]);

        } catch (Exception $e) {
            $this->sendResponse(401, ['success' => false, 'message' => 'Token inválido ou expirado: ' . $e->getMessage()]);
        }
    }



    public function downloadContrato($idContrato)
    {
        header('Content-Type: application/json');
        $token = getAuthToken();
        if (!$token) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Acesso negado. Token JWT ausente.']);
            exit();
        }

        try {
            $decoded = JWT::decode($token, new Key(JWT_SECRET_KEY, JWT_ALGORITHM));
            $userData = (array) $decoded->data;
        } catch (Exception $e) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Token JWT inválido ou expirado.']);
            exit();
        }

        $contrato = $this->contratoModel->getContratoById($idContrato);

        if (!$contrato) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Contrato não encontrado.']);
            exit();
        }

        $isAuthorized = ($userData['role'] === 'admin') || (isset($userData['id_aluno']) && $contrato['id_aluno'] === $userData['id_aluno']);

        if (!$isAuthorized) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Acesso negado.']);
            exit();
        }

        $fileType = $_GET['file_type'] ?? 'original';
        $filePath = ($fileType === 'assinado' && $contrato['caminho_pdf_assinado'])
            ? __DIR__ . '/../../' . $contrato['caminho_pdf_assinado']
            : __DIR__ . '/../../' . $contrato['caminho_pdf'];

        if (!file_exists($filePath)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Arquivo PDF não encontrado no servidor.']);
            exit();
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit();
    }
}