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
        if ($this->findByUsername($data['username'])) {
            error_log("Tentativa de criar usuário com username existente: " . $data['username']);
            return false;
        }

        $hashedPassword = password_hash($data['password'], PASSWORD_BCRYPT);

        // Query SQL que agora inclui a coluna 'id_aluno'
        $query = "INSERT INTO " . $this->table_name . " (username, password_hash, role, id_aluno) VALUES (:username, :password_hash, :role, :id_aluno)";
        
        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(":username", $data['username']);
        $stmt->bindParam(":password_hash", $hashedPassword);
        $stmt->bindParam(":role", $data['role']);
        
        // Garante que o id_aluno seja tratado como um inteiro
        $id_aluno_param = isset($data['id_aluno']) ? $data['id_aluno'] : null;
        $stmt->bindParam(":id_aluno", $id_aluno_param, PDO::PARAM_INT);

        return $stmt->execute();
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
    
    /**
     * Atualiza a senha de um usuário identificado pelo ID.
     *
     * @param int    $idUsuario   ID do usuário que terá a senha alterada.
     * @param string $newPassword Nova senha em texto puro.
     *
     * @return bool Retorna true em caso de sucesso, false em caso de falha.
     */
    public function updatePassword($idUsuario, $newPassword) {
        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);

        $query = "UPDATE " . $this->table_name . " SET password_hash = :password_hash WHERE id_usuario = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':password_hash', $hashedPassword);
        $stmt->bindParam(':id', $idUsuario, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return true;
        }

        error_log("Erro ao atualizar senha: " . implode(" ", $stmt->errorInfo()));
        return false;
    }

    /**
     * Libera o acesso de um aluno alterando sua role para 'aluno'.
     * A busca é feita pelo id_aluno, que deve ser uma chave na tabela de usuários.
     *
     * @param int $idAluno O ID do aluno (da tabela 'alunos').
     * @return bool True se a role foi atualizada com sucesso, false caso contrário.
     */
    public function liberarAcessoAluno($idAluno) {
        // A role só é alterada se o estado atual for 'aluno_pendente', como uma salvaguarda.
        $query = "UPDATE " . $this->table_name . " SET role = 'aluno' WHERE id_aluno = :id_aluno AND role = 'aluno_pendente'";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_aluno', $idAluno, PDO::PARAM_INT);

        if ($stmt->execute()) {
            // Retorna true se pelo menos uma linha foi afetada.
            // Isso evita retornar sucesso se o aluno já estava ativo ou não foi encontrado.
            return $stmt->rowCount() > 0;
        }

        error_log("Erro ao liberar acesso do aluno (ID Aluno: {$idAluno}): " . implode(" ", $stmt->errorInfo()));
        return false;
    }


    // Dentro da classe Usuario em api/models/Usuario.php

public function getAllUsers() {
    $query = "SELECT id_usuario, username, role FROM " . $this->table_name;
    $stmt = $this->conn->prepare($query);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

public function findById($id) {
    $query = "SELECT id_usuario, username, role FROM " . $this->table_name . " WHERE id_usuario = :id";
    $stmt = $this->conn->prepare($query);
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

public function updateUser($id, $data) {
    $query = "UPDATE " . $this->table_name . " SET username = :username, role = :role WHERE id_usuario = :id";
    $stmt = $this->conn->prepare($query);
    $stmt->bindParam(':username', $data['username']);
    $stmt->bindParam(':role', $data['role']);
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    return $stmt->execute();
}

public function deleteById($id) {
    // Para evitar que o admin principal seja deletado, adicione uma verificação
    if ($id == 1) { // Supondo que o primeiro admin tenha ID 1
        return false;
    }
    $query = "DELETE FROM " . $this->table_name . " WHERE id_usuario = :id";
    $stmt = $this->conn->prepare($query);
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    return $stmt->execute();
}
}
