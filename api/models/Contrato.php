<?php
// api/models/Contrato.php

require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../config/Auth.php';

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

     public function getContratoById($id) {
        $query = "SELECT id_aluno, caminho_pdf, caminho_pdf_assinado FROM " . $this->table_name . " WHERE id_contrato = :id LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getAllContratosComAlunos($nomeAluno = null) {
         $query = "
            SELECT 
                c.id_contrato, 
                c.caminho_pdf, 
                c.caminho_pdf_assinado,
                c.assinado_validado,
                c.status,
                a.nome_aluno,
                a.id_aluno 
            FROM " . $this->table_name . " c
            JOIN alunos a ON c.id_aluno = a.id_aluno
        ";
        
        if ($nomeAluno) {
            $query .= " WHERE a.nome_aluno LIKE :nomeAluno";
        }
        
        // A ordem agora é por status de validação (pendente primeiro) e depois por nome do aluno
        $query .= " ORDER BY c.assinado_validado ASC, a.nome_aluno ASC";
        
        $stmt = $this->conn->prepare($query);
        
        if ($nomeAluno) {
            $likeNome = "%" . $nomeAluno . "%";
            $stmt->bindParam(':nomeAluno', $likeNome);
        }
        
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function marcarComoValidado($idContrato) {
        // Agora, também atualiza o status para 'validado' para maior clareza.
        $query = "UPDATE " . $this->table_name . " SET assinado_validado = 1, status = 'validado' WHERE id_contrato = :id_contrato";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_contrato', $idContrato, PDO::PARAM_INT);
        return $stmt->execute();
    }


    public function findById($id_contrato) {
        // Garante que o id_aluno seja selecionado para uso no controller
        $query = "SELECT c.*, a.ra, a.id_aluno FROM " . $this->table_name . " c JOIN alunos a ON c.id_aluno = a.id_aluno WHERE c.id_contrato = :id_contrato LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_contrato', $id_contrato, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function updateAssinatura($idContrato, $caminhoAssinado) {
        // Altera o status para 'em_analise', indicando que aguarda a validação do admin.
        $query = "UPDATE " . $this->table_name . " SET caminho_pdf_assinado = :caminho, status = 'em_analise', data_assinatura = NOW() WHERE id_contrato = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':caminho', $caminhoAssinado);
        $stmt->bindParam(':id', $idContrato, PDO::PARAM_INT);
        return $stmt->execute();
    }
}