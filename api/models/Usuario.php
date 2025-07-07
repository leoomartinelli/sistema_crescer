<?php
// api/models/Usuario.php

require_once __DIR__ . '/../../config/Database.php';

class Usuario {
    private $conn;
    private $table_name = "usuarios";

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    /**
     * Encontra um usuário pelo nome de usuário.
     * @param string $username O nome de usuário.
     * @return array|false Os dados do usuário ou false se não encontrado.
     */
    public function findByUsername($username) {
        $query = "SELECT id_usuario, username, password_hash, role FROM " . $this->table_name . " WHERE username = :username LIMIT 0,1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':username', $username);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Verifica se a senha fornecida corresponde ao hash.
     * @param string $password A senha em texto puro.
     * @param string $hashedPassword O hash da senha armazenado no banco.
     * @return bool True se a senha for válida, false caso contrário.
     */
    public function verifyPassword($password, $hashedPassword) {
        return password_verify($password, $hashedPassword);
    }

    /**
     * Cria um novo usuário no banco de dados.
     * @param array $data Dados do usuário (username, password, role).
     * @return bool True em caso de sucesso, false em caso de falha (ou se username já existe).
     */
    public function create($data) {
        // Verifica se o username já existe
        if ($this->findByUsername($data['username'])) {
            error_log("Tentativa de criar usuário com username existente: " . $data['username']);
            return false; // Retorna false se o usuário já existe
        }

        // Hash da senha antes de salvar
        $hashedPassword = password_hash($data['password'], PASSWORD_BCRYPT);

        $query = "INSERT INTO " . $this->table_name . " (username, password_hash, role) VALUES (:username, :password_hash, :role)";
        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(":username", $data['username']);
        $stmt->bindParam(":password_hash", $hashedPassword);
        $stmt->bindParam(":role", $data['role']);

        if ($stmt->execute()) {
            return true;
        }
        error_log("Erro ao criar usuário: " . implode(" ", $stmt->errorInfo()));
        return false;
    }

    /**
     * Deleta um usuário pelo nome de usuário.
     * @param string $username O nome de usuário a ser deletado.
     * @return bool True em caso de sucesso, false em caso de falha.
     */
    public function deleteByUsername($username) {
        $query = "DELETE FROM " . $this->table_name . " WHERE username = :username";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":username", $username);

        if ($stmt->execute()) {
            return true;
        }
        error_log("Erro ao deletar usuário: " . implode(" ", $stmt->errorInfo()));
        return false;
    }
}
