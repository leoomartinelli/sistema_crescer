<?php
// api/models/Mensalidade.php

require_once __DIR__ . '/../../config/Database.php';

class Mensalidade {
    private $conn;
    private $table_name = "mensalidades";

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    /**
     * Calcula a multa com base no valor da mensalidade, data de vencimento e data de pagamento.
     * @param float $valorMensalidade O valor base da mensalidade.
     * @param string $dataVencimento A data de vencimento (formato YYYY-MM-DD).
     * @param string|null $dataPagamento A data de pagamento (formato YYYY-MM-DD). Se null, usa a data atual.
     * @return array Contém 'multa_aplicada', 'percentual_multa' e 'dias_atraso'.
     */
    private function calcularMulta($valorMensalidade, $dataVencimento, $dataPagamento = null) {
        $dataVenc = new DateTime($dataVencimento);
        $dataPag = $dataPagamento ? new DateTime($dataPagamento) : new DateTime(); // Usa data atual se não houver data de pagamento

        if ($dataPag <= $dataVenc) {
            return ['multa_aplicada' => 0.00, 'percentual_multa' => 0.00, 'dias_atraso' => 0];
        }

        $interval = $dataPag->diff($dataVenc);
        $diasAtraso = $interval->days;

        $percentualMulta = 2.00; // 2% inicial [cite: 3]
        $multiplicadorTrintaDias = floor($diasAtraso / 30); // Calcula quantas vezes 30 dias se passaram

        // Dobra a porcentagem a cada 30 dias [cite: 3]
        if ($multiplicadorTrintaDias > 0) {
            $percentualMulta = $percentualMulta * (2 ** $multiplicadorTrintaDias);
        }

        $multaAplicada = $valorMensalidade * ($percentualMulta / 100);

        return [
            'multa_aplicada' => round($multaAplicada, 2),
            'percentual_multa' => round($percentualMulta, 2),
            'dias_atraso' => $diasAtraso
        ];
    }

    /**
     * Cria uma nova mensalidade.
     * @param array $data Contém id_aluno, valor_mensalidade, data_vencimento.
     * @return bool True em caso de sucesso, false caso contrário.
     */
    public function create($data) {
        $query = "INSERT INTO " . $this->table_name . " (
                    id_aluno, valor_mensalidade, data_vencimento, status
                  ) VALUES (
                    :id_aluno, :valor_mensalidade, :data_vencimento, :status
                  )";
        
        $stmt = $this->conn->prepare($query);

        $status = 'pendente'; // Nova mensalidade é sempre pendente
        
        $stmt->bindParam(":id_aluno", $data['id_aluno'], PDO::PARAM_INT);
        $stmt->bindParam(":valor_mensalidade", $data['valor_mensalidade']);
        $stmt->bindParam(":data_vencimento", $data['data_vencimento']);
        $stmt->bindParam(":status", $status);

        if ($stmt->execute()) {
            return true;
        }
        error_log("Erro ao criar mensalidade: " . implode(" ", $stmt->errorInfo()));
        return false;
    }

    /**
     * Obtém todas as mensalidades com filtros opcionais.
     * @param array $filters Array associativo com filtros (id_aluno, status, data_inicio, data_fim).
     * @return array Um array de mensalidades.
     */
    public function getAll($filters = []) {
        $query = "SELECT 
                    m.id_mensalidade, m.id_aluno, m.valor_mensalidade, m.data_vencimento, 
                    m.data_pagamento, m.status, m.valor_pago, m.multa_aplicada, 
                    m.percentual_multa, m.dias_atraso,
                    a.nome_aluno, a.ra
                  FROM " . $this->table_name . " m
                  JOIN alunos a ON m.id_aluno = a.id_aluno
                  WHERE 1=1";
        $params = [];

        if (isset($filters['id_aluno']) && !empty($filters['id_aluno'])) {
            $query .= " AND m.id_aluno = :id_aluno";
            $params[':id_aluno'] = $filters['id_aluno'];
        }
        if (isset($filters['status']) && !empty($filters['status'])) {
            $query .= " AND m.status = :status";
            $params[':status'] = $filters['status'];
        }
        if (isset($filters['data_inicio']) && !empty($filters['data_inicio'])) {
            $query .= " AND m.data_vencimento >= :data_inicio";
            $params[':data_inicio'] = $filters['data_inicio'];
        }
        if (isset($filters['data_fim']) && !empty($filters['data_fim'])) {
            $query .= " AND m.data_vencimento <= :data_fim";
            $params[':data_fim'] = $filters['data_fim'];
        }

        $query .= " ORDER BY m.data_vencimento DESC, a.nome_aluno ASC";

        $stmt = $this->conn->prepare($query);
        foreach ($params as $key => &$val) {
            $stmt->bindParam($key, $val);
        }

        try {
            $stmt->execute();
            $mensalidades = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calcula multa para mensalidades 'pendente' ou 'atrasado'
            foreach ($mensalidades as &$mensalidade) {
                if ($mensalidade['status'] !== 'pago') {
                    $calculoMulta = $this->calcularMulta(
                        $mensalidade['valor_mensalidade'],
                        $mensalidade['data_vencimento'],
                        null // Usa a data atual para cálculo de multa em mensalidades não pagas
                    );
                    $mensalidade['multa_aplicada'] = $calculoMulta['multa_aplicada'];
                    $mensalidade['percentual_multa'] = $calculoMulta['percentual_multa'];
                    $mensalidade['dias_atraso'] = $calculoMulta['dias_atraso'];
                    
                    // Atualiza o status para 'atrasado' se houver dias de atraso e não estiver pago
                    if ($mensalidade['dias_atraso'] > 0 && $mensalidade['status'] === 'pendente') {
                        $mensalidade['status'] = 'atrasado';
                        // Opcional: Persistir essa mudança de status no banco de dados para evitar recalcular sempre
                        // Mas para uma simples listagem, recalcular é aceitável se não houver muitos registros.
                    }
                }
                 // Calcula o total a pagar para o frontend
                $mensalidade['total_a_pagar'] = $mensalidade['valor_mensalidade'] + $mensalidade['multa_aplicada'];
            }
            return $mensalidades;
        } catch (PDOException $e) {
            error_log("Erro ao buscar mensalidades: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Obtém uma única mensalidade por ID.
     * @param int $id O ID da mensalidade.
     * @return array|false Retorna os dados da mensalidade ou false se não encontrada.
     */
    public function getById($id) {
        $query = "SELECT 
                    m.id_mensalidade, m.id_aluno, m.valor_mensalidade, m.data_vencimento, 
                    m.data_pagamento, m.status, m.valor_pago, m.multa_aplicada, 
                    m.percentual_multa, m.dias_atraso,
                    a.nome_aluno, a.ra
                  FROM " . $this->table_name . " m
                  JOIN alunos a ON m.id_aluno = a.id_aluno
                  WHERE m.id_mensalidade = :id LIMIT 0,1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $mensalidade = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($mensalidade && $mensalidade['status'] !== 'pago') {
            $calculoMulta = $this->calcularMulta(
                $mensalidade['valor_mensalidade'],
                $mensalidade['data_vencimento'],
                null
            );
            $mensalidade['multa_aplicada'] = $calculoMulta['multa_aplicada'];
            $mensalidade['percentual_multa'] = $calculoMulta['percentual_multa'];
            $mensalidade['dias_atraso'] = $calculoMulta['dias_atraso'];

            if ($mensalidade['dias_atraso'] > 0 && $mensalidade['status'] === 'pendente') {
                $mensalidade['status'] = 'atrasado';
            }
        }
        if ($mensalidade) {
            $mensalidade['total_a_pagar'] = $mensalidade['valor_mensalidade'] + $mensalidade['multa_aplicada'];
        }
        return $mensalidade;
    }

    /**
     * Marca uma mensalidade como paga.
     * Calcula a multa e o valor final pago com base na data de pagamento.
     * @param int $id O ID da mensalidade.
     * @param array $data Dados de pagamento, incluindo 'data_pagamento' e 'valor_pago'.
     * @return bool True em caso de sucesso, false caso contrário.
     */
    public function markAsPaid($id, $data) {
        // Obter a mensalidade atual para ter o valor original e data de vencimento
        $mensalidade = $this->getById($id);
        if (!$mensalidade) {
            return false;
        }

        $dataPagamento = isset($data['data_pagamento']) ? $data['data_pagamento'] : date('Y-m-d');
        $valorPago = isset($data['valor_pago']) ? $data['valor_pago'] : $mensalidade['valor_mensalidade'];

        $calculoMulta = $this->calcularMulta(
            $mensalidade['valor_mensalidade'],
            $mensalidade['data_vencimento'],
            $dataPagamento
        );

        $status = 'pago';
        $multaAplicada = $calculoMulta['multa_aplicada'];
        $percentualMulta = $calculoMulta['percentual_multa'];
        $diasAtraso = $calculoMulta['dias_atraso'];

        $query = "UPDATE " . $this->table_name . " SET 
                    data_pagamento = :data_pagamento, 
                    valor_pago = :valor_pago, 
                    status = :status,
                    multa_aplicada = :multa_aplicada,
                    percentual_multa = :percentual_multa,
                    dias_atraso = :dias_atraso
                  WHERE id_mensalidade = :id_mensalidade";
        
        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(":data_pagamento", $dataPagamento);
        $stmt->bindParam(":valor_pago", $valorPago);
        $stmt->bindParam(":status", $status);
        $stmt->bindParam(":multa_aplicada", $multaAplicada);
        $stmt->bindParam(":percentual_multa", $percentualMulta);
        $stmt->bindParam(":dias_atraso", $diasAtraso);
        $stmt->bindParam(":id_mensalidade", $id, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return true;
        }
        error_log("Erro ao marcar mensalidade como paga: " . implode(" ", $stmt->errorInfo()));
        return false;
    }
}