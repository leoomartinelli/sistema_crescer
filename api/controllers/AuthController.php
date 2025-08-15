<?php
// api/controllers/AuthController.php

require_once __DIR__ . '/../models/Usuario.php';
require_once __DIR__ . '/../models/Aluno.php';
require_once __DIR__ . '/../models/Contrato.php';
require_once __DIR__ . '/../../config/Auth.php';

use Firebase\JWT\JWT;

class AuthController {
    private $usuarioModel;
    private $alunoModel;
    private $contratoModel;

    public function __construct() {
        $this->usuarioModel = new Usuario();
        $this->alunoModel = new Aluno();
        $this->contratoModel = new Contrato();
    }

    public function login() {
        $json_data = file_get_contents('php://input');
        $data = json_decode($json_data, true);

        if (!isset($data['username']) || !isset($data['password']) || !isset($data['role'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Campos obrigatórios (username, password, role) ausentes.']);
            return;
        }

        $username = $data['username'];
        $password = $data['password'];
        $requestedRole = $data['role'];

        $usuario = $this->usuarioModel->findByUsername($username);

       

        if (!$usuario || !$this->usuarioModel->verifyPassword($password, $usuario['password_hash'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Credenciais inválidas.']);
            return;
        }

        // --- INÍCIO DA VERIFICAÇÃO DE CONTRATO (LÓGICA CORRIGIDA) ---
        if ($usuario['role'] === 'aluno') {
            // Busca o aluno diretamente pelo RA (que é o username)
            $alunoData = $this->alunoModel->findByRa($username);

            if ($alunoData) {
                $id_aluno = $alunoData['id_aluno'];
                $contratoPendente = $this->contratoModel->findPendingByAlunoId($id_aluno);

                if ($contratoPendente) {
                    // Contrato pendente encontrado. Gerar token temporário.
                    $payload = [
                        'iss' => 'your_domain.com',
                        'aud' => 'your_app.com',
                        'iat' => time(),
                        'exp' => time() + 3600, // Token temporário de 1 hora
                        'data' => [
                            'username' => $usuario['username'],
                            'role' => 'aluno_pendente' // Papel especial para assinatura
                        ]
                    ];
                    $jwt = JWT::encode($payload, JWT_SECRET_KEY, JWT_ALGORITHM);

                    http_response_code(200);
                    echo json_encode([
                        'success' => true,
                        'contract_pending' => true,
                        'message' => 'Você possui um contrato pendente para assinar.',
                        'jwt_temporary' => $jwt,
                        'contract_id' => $contratoPendente['id_contrato'],
                        'contract_path' => $contratoPendente['caminho_pdf']
                    ]);
                    return; // Interrompe a execução para não dar o login normal
                }
            }
        }
        // --- FIM DA VERIFICAÇÃO ---
        
        // Se passou pela verificação (não é aluno ou não tem contrato), continua com o login normal...
        // ... (O resto da lógica de verificação de papéis e geração de token completo permanece o mesmo) ...
        
        // Payload do JWT de acesso completo
        $payload = [
            'iss' => 'your_domain.com', 
            'aud' => 'your_app.com',
            'iat' => time(),
            'exp' => time() + JWT_EXPIRATION_TIME,
            'data' => [
                'id_usuario' => $usuario['id_usuario'],
                'username' => $usuario['username'],
                'role' => $usuario['role']
            ]
        ];

        $jwt = JWT::encode($payload, JWT_SECRET_KEY, JWT_ALGORITHM);

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Login bem-sucedido!',
            'jwt' => $jwt,
            'user_role' => $usuario['role']
        ]);
    }
}