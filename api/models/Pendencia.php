<?php
// api/models/Pendencia.php

require_once __DIR__ . '/../../config/Database.php';

class Pendencia {
    private $conn;
    private $table_name = "pendencias";

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    public function findPendenciasByResponsavelId($id_responsavel) {
        $query = "SELECT * FROM " . $this->table_name . " WHERE id_responsavel = :id_responsavel";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_responsavel', $id_responsavel);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function create($data) {
        // Primeiro, verifique se a pendência já existe para evitar duplicatas
        $checkQuery = "SELECT COUNT(*) FROM " . $this->table_name . " WHERE id_responsavel = :id_responsavel AND credor = :credor AND valor = :valor AND data_ocorrencia = :data_ocorrencia";
        $checkStmt = $this->conn->prepare($checkQuery);
        $checkStmt->bindParam(":id_responsavel", $data['id_responsavel']);
        $checkStmt->bindParam(":credor", $data['creditorName']);
        $checkStmt->bindParam(":valor", $data['amount']);
        $checkStmt->bindParam(":data_ocorrencia", $data['occurrenceDate']);
        $checkStmt->execute();
        
        if ($checkStmt->fetchColumn() > 0) {
            return 'exists'; // Já existe
        }
        
        $query = "INSERT INTO " . $this->table_name . " (id_responsavel, id_escola, credor, valor, data_ocorrencia, cadus, natureza_juridica) VALUES (:id_responsavel, :id_escola, :credor, :valor, :data_ocorrencia, :cadus, :natureza_juridica)";
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(":id_responsavel", $data['id_responsavel']);
        $stmt->bindParam(":id_escola", $data['id_escola']);
        $stmt->bindParam(":credor", $data['creditorName']);
        $stmt->bindParam(":valor", $data['amount']);
        $stmt->bindParam(":data_ocorrencia", $data['occurrenceDate']);
        $stmt->bindParam(":cadus", $data['cadus']);
        $stmt->bindParam(":natureza_juridica", $data['legalNature']);

        if ($stmt->execute()) {
            return true;
        }
        error_log("Erro ao criar pendência: " . implode(" ", $stmt->errorInfo()));
        return false;
    }
}