<?php
// api/models/Professor.php

require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../models/Usuario.php'; // Para gerenciar o usuário de login do professor

class Professor {
    private $conn;
    private $table_name = "professores";
    private $usuarios_table_name = "usuarios";
    private $professor_turma_table_name = "professor_turma"; // Nova tabela pivô
    private $turmas_table_name = "turmas"; // Tabela de turmas
    private $usuarioModel;

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->usuarioModel = new Usuario();
    }

    /**
     * Limpa uma string, removendo todos os caracteres não numéricos.
     * @param string|null $number O número a ser limpo.
     * @return string|null O número contendo apenas dígitos.
     */
    private function cleanNumber($number) {
        return $number ? preg_replace('/\D/', '', $number) : null;
    }

    /**
     * Verifica se um professor com o CPF fornecido já existe.
     * @param string $cpf O CPF a ser verificado.
     * @param int|null $excludeId O ID do professor a ser excluído da verificação (para atualizações).
     * @return bool Retorna true se o CPF já existe, false caso contrário.
     */
    public function existsByCpf($cpf, $excludeId = null) {
        $query = "SELECT COUNT(*) FROM " . $this->table_name . " WHERE cpf = :cpf";
        
        if ($excludeId !== null) {
            $query .= " AND id_professor != :exclude_id";
        }

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":cpf", $cpf);
        
        if ($excludeId !== null) {
            $stmt->bindParam(":exclude_id", $excludeId, PDO::PARAM_INT);
        }

        $stmt->execute();
        return $stmt->fetchColumn() > 0;
    }

    /**
     * Cria um novo professor no banco de dados, incluindo a criação de um usuário de login.
     * @param array $data Os dados do professor a serem inseridos.
     * @return bool|string Retorna true em caso de sucesso, 'cpf_exists' se o CPF já existir, ou false em caso de erro.
     */
    public function create($data) {
        // Validação básica
        if (empty($data['nome_professor']) || empty($data['cpf'])) {
            return false;
        }

        $cleanedCpf = $this->cleanNumber($data['cpf']);
        if ($this->existsByCpf($cleanedCpf)) {
            error_log("Tentativa de criar professor com CPF existente: " . $cleanedCpf);
            return 'cpf_exists';
        }

        try {
            $this->conn->beginTransaction();

            // 1. Cria o usuário de login
            $username = $cleanedCpf; // CPF como username inicial
            $password = substr($cleanedCpf, 0, 6); // Senha inicial: 6 primeiros dígitos do CPF
            $role = 'professor';

            $userCreated = $this->usuarioModel->create([
                'username' => $username,
                'password' => $password,
                'role' => $role
            ]);

            if (!$userCreated) {
                // Se o usuário já existia (retornou 'username_exists') ou houve outro erro no Usuario::create()
                throw new Exception("Falha ao criar usuário para o professor. Motivo: " . ($userCreated === 'username_exists' ? 'Username já existe.' : 'Erro desconhecido.'));
            }

            $usuario = $this->usuarioModel->findByUsername($username);
            if (!$usuario) {
                 throw new Exception("Erro interno: Usuário criado mas não encontrado para obter ID.");
            }
            $id_usuario = $usuario['id_usuario'];

            // 2. Insere os dados do professor
            $query = "INSERT INTO " . $this->table_name . " (nome_professor, email, telefone, cpf, data_contratacao, id_usuario) VALUES (:nome_professor, :email, :telefone, :cpf, :data_contratacao, :id_usuario)";
            $stmt = $this->conn->prepare($query);

            $stmt->bindParam(":nome_professor", $data['nome_professor']);
            $stmt->bindParam(":email", $data['email']);
            $stmt->bindParam(":telefone", $this->cleanNumber($data['telefone']));
            $stmt->bindParam(":cpf", $cleanedCpf);
            $stmt->bindParam(":data_contratacao", $data['data_contratacao']);
            $stmt->bindParam(":id_usuario", $id_usuario, PDO::PARAM_INT);

            if (!$stmt->execute()) {
                $errorInfo = $stmt->errorInfo(); // Armazena em uma variável
                throw new Exception("Erro ao inserir dados do professor: " . implode(" ", $errorInfo));
            }

            $this->conn->commit();
            return true;

        } catch (Exception $e) {
            $this->conn->rollBack();
            error_log("Erro ao criar professor e usuário: " . $e->getMessage());
            // Se o usuário foi criado mas o professor não, tentar limpar
            if (isset($username) && $this->usuarioModel->findByUsername($username)) { // Verifica se o usuário realmente existe antes de tentar deletar
                 $this->usuarioModel->deleteByUsername($username);
            }
            return false;
        }
    }

    /**
     * Obtém todos os professores do banco de dados, com turmas associadas.
     * @return array Retorna um array de professores.
     */
    public function getAll() {
        $query = "SELECT p.id_professor, p.nome_professor, p.email, p.telefone, p.cpf, p.data_contratacao, u.username
                  FROM " . $this->table_name . " p
                  LEFT JOIN " . $this->usuarios_table_name . " u ON p.id_usuario = u.id_usuario
                  ORDER BY p.nome_professor ASC";
        $stmt = $this->conn->prepare($query);
        try {
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Erro ao buscar professores: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Obtém um único professor por ID.
     * @param int $id O ID do professor.
     * @return array|false Retorna os dados do professor ou false se não encontrado.
     */
    public function getById($id) {
        $query = "SELECT p.*, u.username FROM " . $this->table_name . " p
                  LEFT JOIN " . $this->usuarios_table_name . " u ON p.id_usuario = u.id_usuario
                  WHERE p.id_professor = :id LIMIT 0,1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Atualiza um professor existente no banco de dados.
     * @param array $data Os dados do professor a serem atualizados.
     * @return bool|string Retorna true em caso de sucesso, 'cpf_exists' se o CPF já existir em outro professor, ou false em caso de erro.
     */
    public function update($data) {
        if (empty($data['id_professor']) || empty($data['nome_professor']) || empty($data['cpf'])) {
            return false;
        }

        $cleanedCpf = $this->cleanNumber($data['cpf']);
        // Verifica a unicidade do CPF (excluindo o próprio professor)
        if ($this->existsByCpf($cleanedCpf, $data['id_professor'])) {
            error_log("Tentativa de atualizar professor com CPF já existente em outro professor: " . $cleanedCpf);
            return 'cpf_exists';
        }

        try {
            $this->conn->beginTransaction();

            // Atualiza os dados do professor
            $query = "UPDATE " . $this->table_name . " SET nome_professor = :nome_professor, email = :email, telefone = :telefone, cpf = :cpf, data_contratacao = :data_contratacao WHERE id_professor = :id_professor";
            $stmt = $this->conn->prepare($query);

            $stmt->bindParam(":nome_professor", $data['nome_professor']);
            $stmt->bindParam(":email", $data['email']);
            $stmt->bindParam(":telefone", $this->cleanNumber($data['telefone']));
            $stmt->bindParam(":cpf", $cleanedCpf);
            $stmt->bindParam(":data_contratacao", $data['data_contratacao']);
            $stmt->bindParam(":id_professor", $data['id_professor'], PDO::PARAM_INT);

            if (!$stmt->execute()) {
                $errorInfo = $stmt->errorInfo(); // Armazena em uma variável
                throw new Exception("Erro ao atualizar dados do professor: " . implode(" ", $errorInfo));
            }

            // Opcional: Atualizar o username do usuário se o CPF mudou
            // Primeiro, obtenha o id_usuario do professor
            $professorAtual = $this->getById($data['id_professor']);
            if ($professorAtual && $professorAtual['id_usuario']) {
                $usuarioAntigo = $this->usuarioModel->findByUsername($professorAtual['username']); // Busca pelo username atual
                 if ($usuarioAntigo && $usuarioAntigo['username'] !== $cleanedCpf) {
                     // Atualiza o username na tabela de usuários
                     $updateUserQuery = "UPDATE " . $this->usuarios_table_name . " SET username = :new_username WHERE id_usuario = :id_usuario";
                     $updateUserStmt = $this->conn->prepare($updateUserQuery);
                     $updateUserStmt->bindParam(":new_username", $cleanedCpf);
                     $updateUserStmt->bindParam(":id_usuario", $professorAtual['id_usuario'], PDO::PARAM_INT);
                     if (!$updateUserStmt->execute()) {
                         $updateError = $updateUserStmt->errorInfo(); // Armazena em uma variável
                         throw new Exception("Erro ao atualizar username do usuário: " . implode(" ", $updateError));
                     }
                 }
            }

            $this->conn->commit();
            return true;

        } catch (Exception $e) {
            $this->conn->rollBack();
            error_log("Erro ao atualizar professor: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Deleta um professor e seu usuário associado.
     * @param int $id O ID do professor a ser deletado.
     * @return bool Retorna true em caso de sucesso, false em caso de erro.
     */
    public function delete($id) {
        try {
            $this->conn->beginTransaction();

            // 1. Obtém os dados do professor para pegar o id_usuario e username
            $professor = $this->getById($id);
            if (!$professor) {
                throw new Exception("Professor não encontrado para deleção.");
            }

            // 2. Remove as associações professor-turma
            $deleteProfessorTurmaQuery = "DELETE FROM " . $this->professor_turma_table_name . " WHERE id_professor = :id_professor";
            $deleteProfessorTurmaStmt = $this->conn->prepare($deleteProfessorTurmaQuery);
            $deleteProfessorTurmaStmt->bindParam(":id_professor", $id, PDO::PARAM_INT);
            if (!$deleteProfessorTurmaStmt->execute()) {
                $errorInfo = $deleteProfessorTurmaStmt->errorInfo(); // Armazena em uma variável
                throw new Exception("Erro ao deletar associações professor_turma: " . implode(" ", $errorInfo));
            }

            // 3. Deleta o professor
            $query = "DELETE FROM " . $this->table_name . " WHERE id_professor = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":id", $id, PDO::PARAM_INT);
            if (!$stmt->execute()) {
                $errorInfo = $stmt->errorInfo(); // Armazena em uma variável
                throw new Exception("Erro ao deletar professor: " . implode(" ", $errorInfo));
            }

            // 4. Deleta o usuário associado (se existir)
            if ($professor['username']) {
                $this->usuarioModel->deleteByUsername($professor['username']);
            }

            $this->conn->commit();
            return true;

        } catch (Exception $e) {
            $this->conn->rollBack();
            error_log("Erro ao deletar professor e associações: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Obtém as turmas associadas a um professor.
     * @param int $idProfessor O ID do professor.
     * @return array Retorna um array de turmas.
     */
    public function getTurmasByProfessorId($idProfessor) {
        $query = "SELECT t.id_turma, t.nome_turma, pt.data_inicio_lecionar, pt.data_fim_lecionar
                  FROM " . $this->professor_turma_table_name . " pt
                  JOIN " . $this->turmas_table_name . " t ON pt.id_turma = t.id_turma
                  WHERE pt.id_professor = :id_professor
                  ORDER BY t.nome_turma ASC";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_professor', $idProfessor, PDO::PARAM_INT);
        try {
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Erro ao buscar turmas do professor: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Associa um professor a uma turma.
     * @param int $idProfessor
     * @param int $idTurma
     * @param string $dataInicioLecionar
     * @param string|null $dataFimLecionar
     * @return bool True em sucesso, false em falha (ou se já associado).
     */
    public function addTurmaToProfessor($idProfessor, $idTurma, $dataInicioLecionar, $dataFimLecionar = null) {
        // Verifica se a associação já existe
        $checkQuery = "SELECT COUNT(*) FROM " . $this->professor_turma_table_name . " WHERE id_professor = :id_professor AND id_turma = :id_turma";
        $checkStmt = $this->conn->prepare($checkQuery);
        $checkStmt->bindParam(":id_professor", $idProfessor, PDO::PARAM_INT);
        $checkStmt->bindParam(":id_turma", $idTurma, PDO::PARAM_INT);
        $checkStmt->execute();
        if ($checkStmt->fetchColumn() > 0) {
            return 'already_exists'; // Já existe
        }

        $query = "INSERT INTO " . $this->professor_turma_table_name . " (id_professor, id_turma, data_inicio_lecionar, data_fim_lecionar) VALUES (:id_professor, :id_turma, :data_inicio_lecionar, :data_fim_lecionar)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id_professor", $idProfessor, PDO::PARAM_INT);
        $stmt->bindParam(":id_turma", $idTurma, PDO::PARAM_INT);
        $stmt->bindParam(":data_inicio_lecionar", $dataInicioLecionar);
        $stmt->bindParam(":data_fim_lecionar", $dataFimLecionar);

        if ($stmt->execute()) {
            return true;
        }
        error_log("Erro ao adicionar turma ao professor: " . implode(" ", $stmt->errorInfo()));
        return false;
    }

    /**
     * Remove a associação de uma turma a um professor.
     * @param int $idProfessor
     * @param int $idTurma
     * @return bool True em sucesso, false em falha.
     */
    public function removeTurmaFromProfessor($idProfessor, $idTurma) {
        $query = "DELETE FROM " . $this->professor_turma_table_name . " WHERE id_professor = :id_professor AND id_turma = :id_turma";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id_professor", $idProfessor, PDO::PARAM_INT);
        $stmt->bindParam(":id_turma", $idTurma, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return true;
        }
        error_log("Erro ao remover turma do professor: " . implode(" ", $stmt->errorInfo()));
        return false;
    }

    /**
     * Busca um professor pelo seu id_usuario.
     * Útil para o login do professor para obter seus dados.
     * @param int $idUsuario
     * @return array|false
     */
    public function getProfessorByUserId($idUsuario) {
        $query = "SELECT * FROM " . $this->table_name . " WHERE id_usuario = :id_usuario LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_usuario', $idUsuario, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}