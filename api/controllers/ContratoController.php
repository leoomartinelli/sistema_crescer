<?php
// api/controllers/ContratoController.php

require_once __DIR__ . '/../models/Contrato.php';
require_once __DIR__ . '/../models/Usuario.php';
require_once __DIR__ . '/../../config/Auth.php';

use Firebase\JWT\JWT;

class ContratoController {
    private $contratoModel;

    public function __construct() {
        $this->contratoModel = new Contrato();
    }

    private function sendResponse($statusCode, $data) {
        header('Content-Type: application/json');
        http_response_code($statusCode);
        echo json_encode($data);
    }

    public function assinar($id_contrato) {
        $userRa = $GLOBALS['user_data']['username'] ?? null;
        $userRole = $GLOBALS['user_data']['role'] ?? null;

        if ($userRole !== 'aluno_pendente') {
            $this->sendResponse(403, ['success' => false, 'message' => 'Ação não permitida para este usuário.']);
            return;
        }

        $contrato = $this->contratoModel->findById($id_contrato);

        if (!$contrato || $contrato['ra'] !== $userRa) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Você não tem permissão para assinar este contrato.']);
            return;
        }
        
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        if ($this->contratoModel->sign($id_contrato, $ip_address)) {
            // Sucesso! Gerar um novo token de acesso completo.
            $usuarioModel = new Usuario();
            $usuario = $usuarioModel->findByUsername($userRa);

            $payload = [
                'iss' => 'your_domain.com',
                'aud' => 'your_app.com',
                'iat' => time(),
                'exp' => time() + JWT_EXPIRATION_TIME,
                'data' => [
                    'id_usuario' => $usuario['id_usuario'],
                    'username' => $usuario['username'],
                    'role' => $usuario['role'] // Papel real 'aluno'
                ]
            ];

            $jwt = JWT::encode($payload, JWT_SECRET_KEY, JWT_ALGORITHM);

            $this->sendResponse(200, [
                'success' => true,
                'message' => 'Contrato assinado com sucesso! Acesso liberado.',
                'jwt' => $jwt,
                'user_role' => $usuario['role']
            ]);

        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Falha ao registrar a assinatura. O contrato pode já ter sido assinado.']);
        }
    }
}