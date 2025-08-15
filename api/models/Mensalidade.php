<?php
// api/models/Mensalidade.php

date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/../../config/Database.php';

class Mensalidade {
    private $conn;
    private $table_name = "mensalidades";

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    public function readAll($searchTerm = null) {
        $query = "SELECT 
                    m.id_mensalidade, m.id_aluno, a.nome_aluno,
                    m.valor_mensalidade, m.data_vencimento, m.data_pagamento,
                    m.valor_pago, m.status, m.multa_aplicada, m.juros_aplicados,
                    m.percentual_multa, m.dias_atraso
                  FROM 
                    " . $this->table_name . " m
                  JOIN 
                    alunos a ON m.id_aluno = a.id_aluno";

        if ($searchTerm) {
            $query .= " WHERE a.nome_aluno LIKE :searchTerm";
        }
        $query .= " ORDER BY m.data_vencimento DESC";
        $stmt = $this->conn->prepare($query);
        if ($searchTerm) {
            $likeTerm = "%" . $searchTerm . "%";
            $stmt->bindParam(':searchTerm', $likeTerm);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function readById($id) {
        $query = "SELECT * FROM " . $this->table_name . " WHERE id_mensalidade = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    private function exists($id_aluno, $data_vencimento) {
        $date = new DateTime($data_vencimento);
        $month = $date->format('m');
        $year = $date->format('Y');
        $query = "SELECT COUNT(*) FROM " . $this->table_name . " 
                  WHERE id_aluno = :id_aluno 
                  AND MONTH(data_vencimento) = :month 
                  AND YEAR(data_vencimento) = :year";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_aluno', $id_aluno, PDO::PARAM_INT);
        $stmt->bindParam(':month', $month);
        $stmt->bindParam(':year', $year);
        $stmt->execute();
        return $stmt->fetchColumn() > 0;
    }

    public function create($data) {
        if ($this->exists($data['id_aluno'], $data['data_vencimento']) && ($data['descricao'] ?? 'Mensalidade') === 'Mensalidade') {
            return false;
        }

        // **CORREÇÃO**: Usando o status 'pending' que é compatível com o ENUM do banco.
        $query = "INSERT INTO " . $this->table_name . " 
                    (id_aluno, valor_mensalidade, data_vencimento, status, multa_aplicada, juros_aplicados, descricao) 
                  VALUES 
                    (:id_aluno, :valor_mensalidade, :data_vencimento, 'open', 0, 0, :descricao)";
        $stmt = $this->conn->prepare($query);

        $descricao = $data['descricao'] ?? 'Mensalidade';

        $stmt->bindParam(':id_aluno', $data['id_aluno']);
        $stmt->bindParam(':valor_mensalidade', $data['valor_mensalidade']);
        $stmt->bindParam(':data_vencimento', $data['data_vencimento']);
        $stmt->bindParam(':descricao', $descricao);
        return $stmt->execute();
    }
    
    public function registerPayment($data) {
        // **CORREÇÃO**: Usando o status 'approved' que é compatível com o ENUM do banco (equivalente a 'pago').
        $query = "UPDATE " . $this->table_name . " SET data_pagamento = :data_pagamento, valor_pago = :valor_pago, status = 'approved' WHERE id_mensalidade = :id_mensalidade";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':data_pagamento', $data['data_pagamento']);
        $stmt->bindParam(':valor_pago', $data['valor_pago']);
        $stmt->bindParam(':id_mensalidade', $data['id_mensalidade']);
        return $stmt->execute();
    }
    
    public function delete($id) {
        $query = "DELETE FROM " . $this->table_name . " WHERE id_mensalidade = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        return $stmt->execute() && $stmt->rowCount() > 0;
    }
    
    public function updateStatus($id, $status) {
        $query = "UPDATE " . $this->table_name . " SET status = :status WHERE id_mensalidade = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }

    public function calcularEncargosAtraso($id) {
        $mensalidade = $this->readById($id);
        if (!$mensalidade) return false;

        $dataVencimento = new DateTime($mensalidade['data_vencimento']);
        $hoje = new DateTime();

        if ($hoje <= $dataVencimento) {
            return [
                'dias_atraso' => 0, 'multa_aplicada' => 0.00, 'juros_aplicados' => 0.00,
                'valor_total_devido' => (float)$mensalidade['valor_mensalidade']
            ];
        }

        $diasAtraso = $hoje->diff($dataVencimento)->days;
        $valorBase = (float)$mensalidade['valor_mensalidade'];
        $juroDeMoraAbsoluto = 0.00;
        $juroDoMesAbsoluto = 0.00;

        if ($diasAtraso >= 30) {
            $blocosDe30Dias = floor($diasAtraso / 30);
            $taxaJuroDoMes = 2.0 * $blocosDe30Dias;
            $juroDoMesAbsoluto = ($taxaJuroDoMes / 100) * $valorBase;
        }

        if ($diasAtraso > 0) {
            $taxaMoraMensalFixa = 2.0;
            $taxaMoraDiaria = $taxaMoraMensalFixa / 30;
            $juroDeMoraAbsoluto = ($taxaMoraDiaria / 100) * $valorBase * $diasAtraso;
        }
        
        $juroDoMesAbsoluto = round($juroDoMesAbsoluto, 2);
        $juroDeMoraAbsoluto = round($juroDeMoraAbsoluto, 2);

        $valorTotalDevido = $valorBase + $juroDoMesAbsoluto + $juroDeMoraAbsoluto;
        $valorTotalDevido = round($valorTotalDevido, 2);

        $query = "UPDATE " . $this->table_name . " SET 
                    dias_atraso = :dias_atraso,
                    multa_aplicada = :juro_do_mes,
                    juros_aplicados = :juro_de_mora
                  WHERE id_mensalidade = :id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':dias_atraso', $diasAtraso);
        $stmt->bindParam(':juro_do_mes', $juroDoMesAbsoluto);
        $stmt->bindParam(':juro_de_mora', $juroDeMoraAbsoluto);
        $stmt->bindParam(':id', $id);
        $stmt->execute();

        return [
            'dias_atraso' => $diasAtraso,
            'multa_aplicada' => $juroDoMesAbsoluto,
            'juros_aplicados' => $juroDeMoraAbsoluto,
            'valor_total_devido' => $valorTotalDevido
        ];
    }

    public function readByAlunoRa($ra) {
        $alunoQuery = "SELECT id_aluno FROM alunos WHERE ra = :ra LIMIT 1";
        $stmtAluno = $this->conn->prepare($alunoQuery);
        $stmtAluno->bindParam(':ra', $ra);
        $stmtAluno->execute();
        $aluno = $stmtAluno->fetch(PDO::FETCH_ASSOC);

        if (!$aluno) return false;
        
        $id_aluno = $aluno['id_aluno'];
        $query = "SELECT * FROM " . $this->table_name . " m
                  WHERE 
                    m.id_aluno = :id_aluno
                  ORDER BY 
                    m.data_vencimento ASC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_aluno', $id_aluno, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}