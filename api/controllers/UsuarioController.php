<?php
// api/controllers/UsuarioController.php

require_once __DIR__ . '/../models/Usuario.php';

class UsuarioController {
    private $usuarioModel;

    public function __construct() {
        $this->usuarioModel = new Usuario();
    }

    private function sendResponse($statusCode, $data) {
        header('Content-Type: application/json');
        http_response_code($statusCode);
        echo json_encode($data);
    }

    // GET /api/usuarios - Listar todos os usuários
    public function getAll() {
        // Adicionaremos um método 'getAllUsers' ao nosso modelo
        $usuarios = $this->usuarioModel->getAllUsers();
        $this->sendResponse(200, ['success' => true, 'data' => $usuarios]);
    }

    // GET /api/usuarios/{id} - Obter um usuário por ID
    public function getById($id) {
        $usuario = $this->usuarioModel->findById($id);
        if ($usuario) {
            $this->sendResponse(200, ['success' => true, 'data' => $usuario]);
        } else {
            $this->sendResponse(404, ['success' => false, 'message' => 'Usuário não encontrado.']);
        }
    }

    // POST /api/usuarios - Criar um novo usuário
    public function create() {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!isset($data['username']) || !isset($data['password']) || !isset($data['role'])) {
            $this->sendResponse(400, ['success' => false, 'message' => 'Username, password e role são obrigatórios.']);
            return;
        }

        // O método 'create' do modelo Usuario.php já existe e serve perfeitamente
        if ($this->usuarioModel->create($data)) {
            $this->sendResponse(201, ['success' => true, 'message' => 'Usuário criado com sucesso.']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Falha ao criar usuário. O username já pode existir.']);
        }
    }

    // PUT /api/usuarios/{id} - Atualizar um usuário
    public function update($id) {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!isset($data['username']) || !isset($data['role'])) {
            $this->sendResponse(400, ['success' => false, 'message' => 'Username e role são obrigatórios.']);
            return;
        }
        
        // Vamos precisar de um método 'updateUser' no modelo
        if ($this->usuarioModel->updateUser($id, $data)) {
            $this->sendResponse(200, ['success' => true, 'message' => 'Usuário atualizado com sucesso.']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Falha ao atualizar usuário.']);
        }
    }

    // DELETE /api/usuarios/{id} - Deletar um usuário
    public function delete($id) {
        // Vamos precisar de um método 'deleteById' no modelo
        if ($this->usuarioModel->deleteById($id)) {
            $this->sendResponse(200, ['success' => true, 'message' => 'Usuário deletado com sucesso.']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Falha ao deletar usuário.']);
        }
    }
    
    // PUT /api/usuarios/{id}/reset-password - Resetar a senha
    public function resetPassword($id) {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!isset($data['new_password'])) {
            $this->sendResponse(400, ['success' => false, 'message' => 'A nova senha é obrigatória.']);
            return;
        }

        // O método 'updatePassword' do modelo Usuario.php já existe e serve
        if ($this->usuarioModel->updatePassword($id, $data['new_password'])) {
            $this->sendResponse(200, ['success' => true, 'message' => 'Senha do usuário redefinida com sucesso.']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Falha ao redefinir a senha.']);
        }
    }
}