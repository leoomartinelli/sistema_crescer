<?php
// api/models/Turma.php

require_once __DIR__ . '/../../config/Database.php';

class Turma {
    private $conn;
    private $table_name = "turmas";
    private $alunos_table_name = "alunos"; // Adicionado nome da tabela de alunos

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    /**
     * Verifica se uma turma com o nome fornecido já existe.
     * @param string $nomeTurma O nome da turma a ser verificado.
     * @param int|null $excludeId O ID da turma a ser excluída da verificação (para atualizações).
     * @return bool Retorna true se a turma já existe, false caso contrário.
     */
    public function existsByNomeTurma($nomeTurma, $excludeId = null) {
        $query = "SELECT COUNT(*) FROM " . $this->table_name . " WHERE nome_turma = :nome_turma";
        
        if ($excludeId !== null) {
            $query .= " AND id_turma != :exclude_id";
        }

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":nome_turma", $nomeTurma);
        
        if ($excludeId !== null) {
            $stmt->bindParam(":exclude_id", $excludeId, PDO::PARAM_INT);
        }

        $stmt->execute();
        return $stmt->fetchColumn() > 0;
    }

    /**
     * Cria uma nova turma no banco de dados.
     * @param array $data Os dados da turma a serem inseridos.
     * @return bool|string Retorna true em caso de sucesso, 'nome_exists' se o nome da turma já existir, ou false em caso de erro.
     */
    public function create($data) {
        // Validação básica
        if (empty($data['nome_turma'])) {
            return false;
        }

        // Verifica a unicidade do nome da turma
        if ($this->existsByNomeTurma($data['nome_turma'])) {
            error_log("Tentativa de criar turma com nome existente: " . $data['nome_turma']);
            return 'nome_exists';
        }

        $query = "INSERT INTO " . $this->table_name . " (nome_turma, periodo, ano_letivo, descricao) VALUES (:nome_turma, :periodo, :ano_letivo, :descricao)";
        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(":nome_turma", $data['nome_turma']);
        $stmt->bindParam(":periodo", $data['periodo']);
        $stmt->bindParam(":ano_letivo", $data['ano_letivo'], PDO::PARAM_INT);
        $stmt->bindParam(":descricao", $data['descricao']);

        if ($stmt->execute()) {
            return true;
        }
        error_log("Erro ao criar turma: " . implode(" ", $stmt->errorInfo()));
        return false;
    }

    /**
     * Obtém todas as turmas do banco de dados, incluindo a contagem de alunos.
     * @return array Retorna um array de turmas.
     */
    public function getAll() {
        $query = "SELECT 
                    t.id_turma, 
                    t.nome_turma, 
                    t.periodo, 
                    t.ano_letivo, 
                    t.descricao,
                    (SELECT COUNT(*) FROM " . $this->alunos_table_name . " a WHERE a.id_turma = t.id_turma) AS quantidade_alunos
                  FROM " . $this->table_name . " t 
                  ORDER BY t.nome_turma ASC";
        $stmt = $this->conn->prepare($query);
        try {
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Erro ao buscar turmas: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Obtém uma única turma por ID, incluindo a contagem de alunos.
     * @param int $id O ID da turma.
     * @return array|false Retorna os dados da turma ou false se não encontrada.
     */
    public function getById($id) {
        $query = "SELECT 
                    t.id_turma, 
                    t.nome_turma, 
                    t.periodo, 
                    t.ano_letivo, 
                    t.descricao,
                    (SELECT COUNT(*) FROM " . $this->alunos_table_name . " a WHERE a.id_turma = t.id_turma) AS quantidade_alunos
                  FROM " . $this->table_name . " t 
                  WHERE t.id_turma = :id LIMIT 0,1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Atualiza uma turma existente no banco de dados.
     * @param array $data Os dados da turma a serem atualizados.
     * @return bool|string Retorna true em caso de sucesso, 'nome_exists' se o nome da turma já existir em outra turma, ou false em caso de erro.
     */
    public function update($data) {
        if (empty($data['id_turma']) || empty($data['nome_turma'])) {
            return false;
        }

        // Verifica a unicidade do nome da turma (excluindo a própria turma)
        if ($this->existsByNomeTurma($data['nome_turma'], $data['id_turma'])) {
            error_log("Tentativa de atualizar turma com nome já existente em outra turma: " . $data['nome_turma']);
            return 'nome_exists';
        }

        $query = "UPDATE " . $this->table_name . " SET nome_turma = :nome_turma, periodo = :periodo, ano_letivo = :ano_letivo, descricao = :descricao WHERE id_turma = :id_turma";
        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(":nome_turma", $data['nome_turma']);
        $stmt->bindParam(":periodo", $data['periodo']);
        $stmt->bindParam(":ano_letivo", $data['ano_letivo'], PDO::PARAM_INT);
        $stmt->bindParam(":descricao", $data['descricao']);
        $stmt->bindParam(":id_turma", $data['id_turma'], PDO::PARAM_INT);

        if ($stmt->execute()) {
            return true;
        }
        error_log("Erro ao atualizar turma: " . implode(" ", $stmt->errorInfo()));
        return false;
    }

    /**
     * Deleta uma turma do banco de dados.
     * @param int $id O ID da turma a ser deletada.
     * @return bool Retorna true em caso de sucesso, false em caso de erro.
     */
    public function delete($id) {
        // Antes de deletar a turma, defina id_turma como NULL para os alunos associados
        $updateAlunosQuery = "UPDATE " . $this->alunos_table_name . " SET id_turma = NULL WHERE id_turma = :id_turma";
        $updateAlunosStmt = $this->conn->prepare($updateAlunosQuery);
        $updateAlunosStmt->bindParam(":id_turma", $id, PDO::PARAM_INT);
        $updateAlunosStmt->execute(); // Execute mesmo se não houver alunos, para não travar

        $query = "DELETE FROM " . $this->table_name . " WHERE id_turma = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id", $id, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return true;
        }
        error_log("Erro ao deletar turma: " . implode(" ", $stmt->errorInfo()));
        return false;
    }
}
