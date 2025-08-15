<?php
// api/models/Responsavel.php

require_once __DIR__ . '/../../config/Database.php';

class Responsavel {
    private $conn;
    private $table_name = "responsaveis";

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    public function findByCpf($cpf) {
        $query = "SELECT * FROM " . $this->table_name . " WHERE cpf = :cpf LIMIT 0,1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':cpf', $cpf);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    public function findById($id_responsavel) {
        $query = "SELECT * FROM " . $this->table_name . " WHERE id_responsavel = :id_responsavel LIMIT 0,1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_responsavel', $id_responsavel);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function create($data) {
        $query = "INSERT INTO " . $this->table_name . " (nome, cpf, cep, id_escola) VALUES (:nome, :cpf, :cep, :id_escola)";
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(":nome", $data['nome']);
        $stmt->bindParam(":cpf", $data['cpf']);
        $stmt->bindParam(":cep", $data['cep']);
        $stmt->bindParam(":id_escola", $data['id_escola'], PDO::PARAM_INT);

        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        error_log("Erro ao criar responsável: " . implode(" ", $stmt->errorInfo()));
        return false;
    }
}