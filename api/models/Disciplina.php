<?php
// api/models/Disciplina.php

require_once __DIR__ . '/../../config/Database.php';

class Disciplina {
    private $conn;
    private $table_name = "disciplinas";
    private $turma_disciplina_table_name = "turma_disciplina"; // Nova tabela pivô
    private $turmas_table_name = "turmas"; // Adicionado para a consulta de turmas por disciplina

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    /**
     * Verifica se uma disciplina com o nome fornecido já existe.
     * @param string $nomeDisciplina O nome da disciplina a ser verificado.
     * @param int|null $excludeId O ID da disciplina a ser excluída da verificação (para atualizações).
     * @return bool Retorna true se a disciplina já existe, false caso contrário.
     */
    public function existsByNomeDisciplina($nomeDisciplina, $excludeId = null) {
        $query = "SELECT COUNT(*) FROM " . $this->table_name . " WHERE nome_disciplina = :nome_disciplina";
        
        if ($excludeId !== null) {
            $query .= " AND id_disciplina != :exclude_id";
        }

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":nome_disciplina", $nomeDisciplina);
        
        if ($excludeId !== null) {
            $stmt->bindParam(":exclude_id", $excludeId, PDO::PARAM_INT);
        }

        $stmt->execute();
        return $stmt->fetchColumn() > 0;
    }

    /**
     * Cria uma nova disciplina.
     * @param array $data Os dados da disciplina.
     * @return bool|string Retorna true em caso de sucesso, 'name_exists' se o nome já existir, ou false em caso de erro.
     */
    public function create($data) {
        if (empty($data['nome_disciplina'])) {
            return false;
        }

        if ($this->existsByNomeDisciplina($data['nome_disciplina'])) {
            return 'name_exists';
        }

        $query = "INSERT INTO " . $this->table_name . " (nome_disciplina) VALUES (:nome_disciplina)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":nome_disciplina", $data['nome_disciplina']);

        if ($stmt->execute()) {
            return true;
        }
        error_log("Erro ao criar disciplina: " . implode(" ", $stmt->errorInfo()));
        return false;
    }

    /**
     * Obtém todas as disciplinas.
     * @return array
     */
    public function getAll() {
        $query = "SELECT id_disciplina, nome_disciplina FROM " . $this->table_name . " ORDER BY nome_disciplina ASC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Obtém uma disciplina por ID.
     * @param int $id
     * @return array|false
     */
    public function getById($id) {
        $query = "SELECT id_disciplina, nome_disciplina FROM " . $this->table_name . " WHERE id_disciplina = :id LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id", $id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Atualiza uma disciplina.
     * @param array $data
     * @return bool|string
     */
    public function update($data) {
        if (empty($data['id_disciplina']) || empty($data['nome_disciplina'])) {
            return false;
        }

        if ($this->existsByNomeDisciplina($data['nome_disciplina'], $data['id_disciplina'])) {
            return 'name_exists';
        }

        $query = "UPDATE " . $this->table_name . " SET nome_disciplina = :nome_disciplina WHERE id_disciplina = :id_disciplina";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":nome_disciplina", $data['nome_disciplina']);
        $stmt->bindParam(":id_disciplina", $data['id_disciplina'], PDO::PARAM_INT);

        if ($stmt->execute()) {
            return true;
        }
        error_log("Erro ao atualizar disciplina: " . implode(" ", $stmt->errorInfo()));
        return false;
    }

    /**
     * Deleta uma disciplina.
     * @param int $id
     * @return bool
     */
    public function delete($id) {
        // Remover associações na turma_disciplina primeiro
        $deleteTurmaDisciplinaQuery = "DELETE FROM " . $this->turma_disciplina_table_name . " WHERE id_disciplina = :id_disciplina";
        $deleteTurmaDisciplinaStmt = $this->conn->prepare($deleteTurmaDisciplinaQuery);
        $deleteTurmaDisciplinaStmt->bindParam(":id_disciplina", $id, PDO::PARAM_INT);
        $deleteTurmaDisciplinaStmt->execute();

        $query = "DELETE FROM " . $this->table_name . " WHERE id_disciplina = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id", $id, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return true;
        }
        error_log("Erro ao deletar disciplina: " . implode(" ", $stmt->errorInfo()));
        return false;
    }

    /**
     * Obtém disciplinas associadas a uma turma.
     * @param int $idTurma
     * @return array
     */
    public function getDisciplinasByTurmaId($idTurma) {
        $query = "SELECT d.id_disciplina, d.nome_disciplina
                  FROM " . $this->turma_disciplina_table_name . " td
                  JOIN " . $this->table_name . " d ON td.id_disciplina = d.id_disciplina
                  WHERE td.id_turma = :id_turma
                  ORDER BY d.nome_disciplina ASC";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_turma', $idTurma, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Associa uma disciplina a uma turma.
     * @param int $idTurma
     * @param int $idDisciplina
     * @return bool|string True em sucesso, 'already_exists' se já associada, false em falha.
     */
    public function addDisciplinaToTurma($idTurma, $idDisciplina) {
        $checkQuery = "SELECT COUNT(*) FROM " . $this->turma_disciplina_table_name . " WHERE id_turma = :id_turma AND id_disciplina = :id_disciplina";
        $checkStmt = $this->conn->prepare($checkQuery);
        $checkStmt->bindParam(":id_turma", $idTurma, PDO::PARAM_INT);
        $checkStmt->bindParam(":id_disciplina", $idDisciplina, PDO::PARAM_INT);
        $checkStmt->execute();
        if ($checkStmt->fetchColumn() > 0) {
            return 'already_exists';
        }

        $query = "INSERT INTO " . $this->turma_disciplina_table_name . " (id_turma, id_disciplina) VALUES (:id_turma, :id_disciplina)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id_turma", $idTurma, PDO::PARAM_INT);
        $stmt->bindParam(":id_disciplina", $idDisciplina, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return true;
        }
        error_log("Erro ao associar disciplina à turma: " . implode(" ", $stmt->errorInfo()));
        return false;
    }

    /**
     * Remove a associação de uma disciplina a uma turma.
     * @param int $idTurma
     * @param int $idDisciplina
     * @return bool True em sucesso, false em falha.
     */
    public function removeDisciplinaFromTurma($idTurma, $idDisciplina) {
        $query = "DELETE FROM " . $this->turma_disciplina_table_name . " WHERE id_turma = :id_turma AND id_disciplina = :id_disciplina";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id_turma", $idTurma, PDO::PARAM_INT);
        $stmt->bindParam(":id_disciplina", $idDisciplina, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return true;
        }
        error_log("Erro ao remover disciplina da turma: " . implode(" ", $stmt->errorInfo()));
        return false;
    }

    /**
     * Obtém as turmas associadas a uma disciplina.
     * @param int $idDisciplina O ID da disciplina.
     * @return array Retorna um array de turmas.
     */
    public function getTurmasByDisciplinaId($idDisciplina) {
        $query = "SELECT t.id_turma, t.nome_turma
                  FROM " . $this->turma_disciplina_table_name . " td
                  JOIN " . $this->turmas_table_name . " t ON td.id_turma = t.id_turma
                  WHERE td.id_disciplina = :id_disciplina
                  ORDER BY t.nome_turma ASC";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_disciplina', $idDisciplina, PDO::PARAM_INT);
        try {
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Erro ao buscar turmas da disciplina: " . $e->getMessage());
            return [];
        }
    }
}