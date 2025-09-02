<?php
// api/controllers/AuthController.php

require_once __DIR__ . '/../models/Usuario.php';
require_once __DIR__ . '/../models/Aluno.php';
require_once __DIR__ . '/../models/Contrato.php';
require_once __DIR__ . '/../../config/Auth.php';

use Firebase\JWT\JWT;

class AuthController
{
    private $usuarioModel;
    private $alunoModel;
    private $contratoModel;

    /**
     * MÉTODO ORIGINAL MANTIDO
     */
    public function __construct()
    {
        $this->usuarioModel = new Usuario();
        $this->alunoModel = new Aluno();
        $this->contratoModel = new Contrato();
    }

    /**
     * MÉTODO ADICIONADO PARA AUXILIAR
     */
    private function sendResponse($statusCode, $data)
    {
        header('Content-Type: application/json');
        http_response_code($statusCode);
        echo json_encode($data);
    }

    /**
     * MÉTODO DE LOGIN CORRIGIDO E FINAL
     */
    public function login()
    {
        $data = json_decode(file_get_contents('php://input'));

        if (!isset($data->username) || !isset($data->password)) {
            $this->sendResponse(400, ['success' => false, 'message' => 'Usuário e senha são obrigatórios.']);
            return;
        }

        $usuario = $this->usuarioModel->findByUsername($data->username);

        if (!$usuario || !password_verify($data->password, $usuario['password_hash'])) {
            $this->sendResponse(401, ['success' => false, 'message' => 'Credenciais inválidas.']);
            return;
        }
        
        if ($usuario['role'] === 'aluno_pendente') {
            
            $aluno = $this->alunoModel->findByRa($usuario['username']);

            if (!$aluno) {
                $this->sendResponse(404, [
                    'success' => false, 
                    'message' => 'DEBUG: Login falhou. Usuário pendente encontrado, mas não há registo de aluno com o RA correspondente.'
                ]);
                return;
            }

            $pendingContract = $this->contratoModel->findPendingByAlunoId($aluno['id_aluno']);

            if (!$pendingContract) {
                $this->sendResponse(404, [
                    'success' => false, 
                    'message' => 'DEBUG: Login falhou. Aluno encontrado, mas nenhum contrato pendente associado a ele.'
                ]);
                return;
            }

            $payload_temporary = [
                'iss' => 'your_domain.com', 'aud' => 'your_app.com', 'iat' => time(),
                'exp' => time() + 3600,
                'data' => [
                    'id_usuario' => $usuario['id_usuario'],
                    'username' => $usuario['username'],
                    'role' => 'aluno_pendente'
                ]
            ];
            $jwt_temporary = JWT::encode($payload_temporary, JWT_SECRET_KEY, JWT_ALGORITHM);

            $response = [
                'success' => true,
                'message' => 'Contrato pendente encontrado. A redirecionar...',
                'contract_pending' => true,
                'jwt_temporary' => $jwt_temporary,
                'contract_id' => $pendingContract['id_contrato'],
                'contract_path' => $pendingContract['caminho_pdf']
            ];

            $this->sendResponse(200, $response);
            return;
        }
        
        $payload = [
            'iss' => 'your_domain.com', 'aud' => 'your_app.com', 'iat' => time(),
            'exp' => time() + JWT_EXPIRATION_TIME,
            'data' => [
                'id_usuario' => $usuario['id_usuario'],
                'username' => $usuario['username'],
                'role' => $usuario['role']
            ]
        ];

        $jwt = JWT::encode($payload, JWT_SECRET_KEY, JWT_ALGORITHM);

        $this->sendResponse(200, [
            'success' => true,
            'message' => 'Login bem-sucedido!',
            'jwt' => $jwt,
            'user_role' => $usuario['role']
        ]);
    }

    /**
     * MÉTODO ORIGINAL MANTIDO
     * Permite que um usuário autenticado altere sua senha.
     */
    public function changePassword()
    {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['current_password']) || !isset($data['new_password'])) {
            $this->sendResponse(400, ['success' => false, 'message' => 'Campos obrigatórios ausentes.']);
            return;
        }
        
        $userData = getAuthenticatedUserData();
        if (!$userData || !isset($userData['username'])) {
            $this->sendResponse(401, ['success' => false, 'message' => 'Usuário não autenticado ou token inválido.']);
            return;
        }

        $usuario = $this->usuarioModel->findByUsername($userData['username']);
        if (!$usuario || !$this->usuarioModel->verifyPassword($data['current_password'], $usuario['password_hash'])) {
            $this->sendResponse(401, ['success' => false, 'message' => 'Senha atual incorreta.']);
            return;
        }

        if ($this->usuarioModel->updatePassword($usuario['id_usuario'], $data['new_password'])) {
            $this->sendResponse(200, ['success' => true, 'message' => 'Senha alterada com sucesso.']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao atualizar a senha.']);
        }
    }

    // O método 'uploadContratoAssinado' foi removido daqui porque a sua lógica
    // correta e final já está no ContratoController.php, para ser usada após a validação do admin.
}