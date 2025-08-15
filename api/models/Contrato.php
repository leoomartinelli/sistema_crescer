<?php
// api/models/Contrato.php

require_once __DIR__ . '/../../config/Database.php';

class Contrato {
    private $conn;
    private $table_name = "contratos";

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    public function create($data) {
        $query = "INSERT INTO " . $this->table_name . " (id_aluno, caminho_pdf, status) VALUES (:id_aluno, :caminho_pdf, 'pendente')";
        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(":id_aluno", $data['id_aluno']);
        $stmt->bindParam(":caminho_pdf", $data['caminho_pdf']);

        return $stmt->execute();
    }

    public function findPendingByAlunoId($id_aluno) {
        $query = "SELECT * FROM " . $this->table_name . " WHERE id_aluno = :id_aluno AND status = 'pendente' LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_aluno', $id_aluno, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function sign($id_contrato, $ip_address) {
        $query = "UPDATE " . $this->table_name . " SET status = 'assinado', data_assinatura = NOW(), ip_assinatura = :ip_assinatura WHERE id_contrato = :id_contrato AND status = 'pendente'";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_contrato', $id_contrato, PDO::PARAM_INT);
        $stmt->bindParam(':ip_assinatura', $ip_address);
        
        if ($stmt->execute()) {
            return $stmt->rowCount() > 0;
        }
        return false;
    }
     public function findById($id_contrato) {
        $query = "SELECT c.*, a.ra FROM " . $this->table_name . " c JOIN alunos a ON c.id_aluno = a.id_aluno WHERE c.id_contrato = :id_contrato LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_contrato', $id_contrato, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}