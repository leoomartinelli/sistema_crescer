<?php
// api/controllers/AuthController.php

require_once __DIR__ . '/../models/Usuario.php';
require_once __DIR__ . '/../../config/Auth.php'; // Inclui as configurações de autenticação

use Firebase\JWT\JWT;

class AuthController {
    private $usuarioModel;

    public function __construct() {
        $this->usuarioModel = new Usuario();
    }

    /**
     * Lida com a requisição de login.
     * Recebe username, password e o papel desejado para login.
     */
    public function login() {
        $json_data = file_get_contents('php://input');
        $data = json_decode($json_data, true);

        if (!isset($data['username']) || !isset($data['password']) || !isset($data['role'])) {
            http_response_code(400); // Bad Request
            echo json_encode(['success' => false, 'message' => 'Campos obrigatórios (username, password, role) ausentes.']);
            return;
        }

        $username = $data['username'];
        $password = $data['password'];
        $requestedRole = $data['role'];

        $usuario = $this->usuarioModel->findByUsername($username);

        if (!$usuario || !$this->usuarioModel->verifyPassword($password, $usuario['password_hash'])) {
            http_response_code(401); // Unauthorized
            echo json_encode(['success' => false, 'message' => 'Credenciais inválidas.']);
            return;
        }

        // Verifica o papel (role)
        // Um 'admin' pode logar como 'professor' para acessar funcionalidades de professor.
        // Um 'professor' só pode logar como 'professor'.
        // Um 'aluno' só pode logar como 'aluno'.
        if ($usuario['role'] === 'admin') {
            if ($requestedRole === 'aluno') {
                http_response_code(403); // Forbidden
                echo json_encode(['success' => false, 'message' => 'Administradores não podem logar como alunos.']);
                return;
            }
            // Admin pode logar como 'professor' ou 'admin' (se houver uma interface admin)
            // Se o requestedRole for 'professor', o admin pode prosseguir.
            // Se o requestedRole for 'admin', o admin pode prosseguir.
            // Qualquer outro requestedRole para admin é inválido para este contexto.
            if ($requestedRole !== 'professor' && $requestedRole !== 'admin') {
                 http_response_code(403); // Forbidden
                 echo json_encode(['success' => false, 'message' => 'Papel de login inválido para administrador.']);
                 return;
            }

        } elseif ($usuario['role'] === 'professor') {
            if ($requestedRole !== 'professor') {
                http_response_code(403); // Forbidden
                echo json_encode(['success' => false, 'message' => 'Professores só podem logar como professor.']);
                return;
            }
        } elseif ($usuario['role'] === 'aluno') {
            if ($requestedRole !== 'aluno') {
                http_response_code(403); // Forbidden
                echo json_encode(['success' => false, 'message' => 'Alunos só podem logar como aluno.']);
                return;
            }
        } else {
            // Papel desconhecido no banco de dados
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Erro interno: Papel de usuário desconhecido.']);
            return;
        }

        // Payload do JWT
        $payload = [
            'iss' => 'your_domain.com', // Emissor do token
            'aud' => 'your_app.com',    // Audiência do token
            'iat' => time(),            // Tempo em que o token foi emitido
            'exp' => time() + JWT_EXPIRATION_TIME, // Tempo de expiração
            'data' => [
                'id_usuario' => $usuario['id_usuario'],
                'username' => $usuario['username'],
                'role' => $usuario['role'] // Papel real do usuário no banco
            ]
        ];

        $jwt = JWT::encode($payload, JWT_SECRET_KEY, JWT_ALGORITHM);

        http_response_code(200); // OK
        echo json_encode([
            'success' => true,
            'message' => 'Login bem-sucedido!',
            'jwt' => $jwt,
            'user_role' => $usuario['role'] // Retorna o papel real para o frontend
        ]);
    }
}
