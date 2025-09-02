<?php
// api/models/Aluno.php

require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../models/Usuario.php';

class Aluno {
    private $conn;
    private $table_name = "alunos";
    private $turmas_table_name = "turmas";
    private $usuarioModel;

    /**
     * Construtor que inicializa a conexão com o banco de dados e o model de usuário.
     */
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
     * Verifica se um RA (Registro Acadêmico) já existe no banco.
     * @param string $ra O RA a ser verificado.
     * @param int|null $excludeId O ID de um aluno a ser ignorado na verificação (usado em atualizações).
     * @return bool Retorna true se o RA já existe, false caso contrário.
     */
    private function existsByRa($ra, $excludeId = null) {
        $query = "SELECT id_aluno FROM " . $this->table_name . " WHERE ra = :ra";
        if ($excludeId !== null) {
            $query .= " AND id_aluno != :exclude_id";
        }
        $query .= " LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":ra", $ra);
        if ($excludeId !== null) {
            $stmt->bindParam(":exclude_id", $excludeId, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Valida se as informações mínimas de um dos pais (nome e CPF) foram fornecidas.
     * @param array $data Os dados do aluno.
     * @return bool Retorna true se a validação for bem-sucedida.
     */
    private function validateParentInfo($data) {
        $hasFatherInfo = !empty($data['nome_pai']) && !empty($this->cleanNumber($data['cpf_pai']));
        $hasMotherInfo = !empty($data['nome_mae']) && !empty($this->cleanNumber($data['cpf_mae']));
        return $hasFatherInfo || $hasMotherInfo;
    }

    /**
     * Busca todos os alunos, com filtros opcionais.
     * @param string $searchName Filtra por nome do aluno.
     * @param string $searchRa Filtra por RA do aluno.
     * @param string $searchTurmaName Filtra por nome da turma.
     * @return array Uma lista de alunos.
     */
     public function getAll($searchName = '', $searchRa = '', $searchTurmaName = '') {
        $query = "SELECT a.*, t.nome_turma
                   FROM " . $this->table_name . " a
                    LEFT JOIN " . $this->turmas_table_name . " t ON a.id_turma = t.id_turma
                    WHERE 1=1";
        $params = [];

        if (!empty($searchName)) {
            $query .= " AND a.nome_aluno LIKE :searchName";
            $params[':searchName'] = '%' . $searchName . '%';
        }

        // --- INÍCIO DA CORREÇÃO ---
        if (!empty($searchRa)) {
            // Trocado "LIKE" por "=" para busca exata de RA
            $query .= " AND a.ra = :searchRa";
            // Removidos os caracteres '%' para busca exata
            $params[':searchRa'] = $searchRa;
        }
        // --- FIM DA CORREÇÃO ---

        if (!empty($searchTurmaName)) {
            $query .= " AND t.nome_turma LIKE :searchTurmaName";
            $params[':searchTurmaName'] = '%' . $searchTurmaName . '%';
        }

        $query .= " ORDER BY a.nome_aluno ASC";
        
        if (!empty($searchName) && empty($searchRa) && empty($searchTurmaName)) {
            $query .= " LIMIT 10";
        }

        $stmt = $this->conn->prepare($query);
        foreach ($params as $key => &$val) {
            $stmt->bindParam($key, $val);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Busca um único aluno pelo seu ID.
     * @param int $id O ID do aluno.
     * @return array|false Os dados do aluno ou false se não encontrado.
     */
    public function getById($id) {
        $query = "SELECT a.*, t.nome_turma 
                  FROM " . $this->table_name . " a
                  LEFT JOIN " . $this->turmas_table_name . " t ON a.id_turma = t.id_turma
                  WHERE a.id_aluno = :id LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Cria um novo aluno no banco de dados.
     * @param array $data Os dados do aluno.
     * @return bool|string Retorna true em sucesso, ou uma string de erro em caso de falha de validação.
     */
    public function create($data) {
        if (!$this->validateParentInfo($data)) return 'parent_info_missing';
        if ($this->existsByRa($data['ra'])) return 'ra_exists';

        $query = "INSERT INTO " . $this->table_name . " (
                    nome_aluno, ra, data_nascimento, idade, id_turma, periodo, endereco, complemento, bairro, cidade, cep, estado,
                    responsavel, telefone_responsavel, celular_responsavel, email_responsavel, cpf_responsavel,
                    nome_pai, cpf_pai, nome_mae, cpf_mae
                ) VALUES (
                    :nome_aluno, :ra, :data_nascimento, :idade, :id_turma, :periodo, :endereco, :complemento, :bairro, :cidade, :cep, :estado,
                    :responsavel, :telefone_responsavel, :celular_responsavel, :email_responsavel, :cpf_responsavel,
                    :nome_pai, :cpf_pai, :nome_mae, :cpf_mae
                )";
        
        $stmt = $this->conn->prepare($query);
        
        $id_turma = !empty($data['id_turma']) ? (int)$data['id_turma'] : null;
        $idade = !empty($data['idade']) ? (int)$data['idade'] : null;

        // CORREÇÃO: Armazena os valores limpos em variáveis antes de fazer o bind
        $telefone_responsavel_clean = $this->cleanNumber($data['telefone_responsavel']);
        $celular_responsavel_clean = $this->cleanNumber($data['celular_responsavel']);
        $cpf_responsavel_clean = $this->cleanNumber($data['cpf_responsavel']);
        $cpf_pai_clean = $this->cleanNumber($data['cpf_pai']);
        $cpf_mae_clean = $this->cleanNumber($data['cpf_mae']);

        $stmt->bindParam(":nome_aluno", $data['nome_aluno']);
        $stmt->bindParam(":ra", $data['ra']);
        $stmt->bindParam(":data_nascimento", $data['data_nascimento']);
        $stmt->bindParam(":idade", $idade, PDO::PARAM_INT);
        $stmt->bindParam(":id_turma", $id_turma, PDO::PARAM_INT);
        $stmt->bindParam(":periodo", $data['periodo']);
        $stmt->bindParam(":endereco", $data['endereco']);
        $stmt->bindParam(":complemento", $data['complemento']);
        $stmt->bindParam(":bairro", $data['bairro']);
        $stmt->bindParam(":cidade", $data['cidade']);
        $stmt->bindParam(":cep", $data['cep']);
        $stmt->bindParam(":estado", $data['estado']);
        $stmt->bindParam(":responsavel", $data['responsavel']);
        $stmt->bindParam(":telefone_responsavel", $telefone_responsavel_clean);
        $stmt->bindParam(":celular_responsavel", $celular_responsavel_clean);
        $stmt->bindParam(":email_responsavel", $data['email_responsavel']);
        $stmt->bindParam(":cpf_responsavel", $cpf_responsavel_clean);
        $stmt->bindParam(":nome_pai", $data['nome_pai']);
        $stmt->bindParam(":cpf_pai", $cpf_pai_clean);
        $stmt->bindParam(":nome_mae", $data['nome_mae']);
        $stmt->bindParam(":cpf_mae", $cpf_mae_clean);

        if ($stmt->execute()) {
             $lastId = $this->conn->lastInsertId();
            if (!empty($data['data_nascimento'])) {
                try {
                    $dateObj = new DateTime($data['data_nascimento']);
                    $alunoPassword = $dateObj->format('dmY');
                    $this->usuarioModel->create([
                        'username' => $data['ra'],
                        'password' => $alunoPassword,
                        'role' => 'aluno_pendente',
                        'id_aluno' => $lastId 

                    ]);
                } catch (Exception $e) { 
                    error_log("Erro ao criar usuário para aluno: " . $e->getMessage()); 
                }
            }
            return $lastId;;
        }
        return false;
    }

    /**
     * Atualiza um aluno existente.
     * @param array $data Os novos dados do aluno.
     * @return bool|string Retorna true em sucesso, ou uma string de erro em caso de falha de validação.
     */
    public function update($data) {
        if (!$this->validateParentInfo($data)) return 'parent_info_missing';
        if ($this->existsByRa($data['ra'], $data['id_aluno'])) return 'ra_exists';

        $query = "UPDATE " . $this->table_name . " SET 
                    nome_aluno = :nome_aluno, ra = :ra, data_nascimento = :data_nascimento, idade = :idade, id_turma = :id_turma, periodo = :periodo, 
                    endereco = :endereco, complemento = :complemento, bairro = :bairro, cidade = :cidade, cep = :cep, estado = :estado,
                    responsavel = :responsavel, telefone_responsavel = :telefone_responsavel, celular_responsavel = :celular_responsavel, 
                    email_responsavel = :email_responsavel, cpf_responsavel = :cpf_responsavel, nome_pai = :nome_pai, cpf_pai = :cpf_pai,
                    nome_mae = :nome_mae, cpf_mae = :cpf_mae
                  WHERE id_aluno = :id_aluno";
        
        $stmt = $this->conn->prepare($query);

        $id_turma = !empty($data['id_turma']) ? (int)$data['id_turma'] : null;
        $idade = !empty($data['idade']) ? (int)$data['idade'] : null;
        
        // CORREÇÃO: Armazena os valores limpos em variáveis antes de fazer o bind
        $telefone_responsavel_clean = $this->cleanNumber($data['telefone_responsavel']);
        $celular_responsavel_clean = $this->cleanNumber($data['celular_responsavel']);
        $cpf_responsavel_clean = $this->cleanNumber($data['cpf_responsavel']);
        $cpf_pai_clean = $this->cleanNumber($data['cpf_pai']);
        $cpf_mae_clean = $this->cleanNumber($data['cpf_mae']);

        $stmt->bindParam(":id_aluno", $data['id_aluno'], PDO::PARAM_INT);
        $stmt->bindParam(":nome_aluno", $data['nome_aluno']);
        $stmt->bindParam(":ra", $data['ra']);
        $stmt->bindParam(":data_nascimento", $data['data_nascimento']);
        $stmt->bindParam(":idade", $idade, PDO::PARAM_INT);
        $stmt->bindParam(":id_turma", $id_turma, PDO::PARAM_INT);
        $stmt->bindParam(":periodo", $data['periodo']);
        $stmt->bindParam(":endereco", $data['endereco']);
        $stmt->bindParam(":complemento", $data['complemento']);
        $stmt->bindParam(":bairro", $data['bairro']);
        $stmt->bindParam(":cidade", $data['cidade']);
        $stmt->bindParam(":cep", $data['cep']);
        $stmt->bindParam(":estado", $data['estado']);
        $stmt->bindParam(":responsavel", $data['responsavel']);
        $stmt->bindParam(":telefone_responsavel", $telefone_responsavel_clean);
        $stmt->bindParam(":celular_responsavel", $celular_responsavel_clean);
        $stmt->bindParam(":email_responsavel", $data['email_responsavel']);
        $stmt->bindParam(":cpf_responsavel", $cpf_responsavel_clean);
        $stmt->bindParam(":nome_pai", $data['nome_pai']);
        $stmt->bindParam(":cpf_pai", $cpf_pai_clean);
        $stmt->bindParam(":nome_mae", $data['nome_mae']);
        $stmt->bindParam(":cpf_mae", $cpf_mae_clean);

        return $stmt->execute();
    }

    /**
     * Deleta um aluno e seu usuário associado.
     * @param int $id O ID do aluno a ser deletado.
     * @return bool Retorna true em sucesso.
     */
    public function delete($id) {
        // Inicia uma transação para garantir a integridade dos dados
        $this->conn->beginTransaction();

        try {
            // Passo 1: Excluir as mensalidades associadas ao aluno
            $queryMensalidades = "DELETE FROM mensalidades WHERE id_aluno = :id_aluno";
            $stmtMensalidades = $this->conn->prepare($queryMensalidades);
            $stmtMensalidades->bindParam(':id_aluno', $id);
            $stmtMensalidades->execute();

            // Passo 2: Excluir os contratos associados ao aluno
            $queryContratos = "DELETE FROM contratos WHERE id_aluno = :id_aluno";
            $stmtContratos = $this->conn->prepare($queryContratos);
            $stmtContratos->bindParam(':id_aluno', $id);
            $stmtContratos->execute();

            // O PASSO 3 QUE CAUSAVA O ERRO FOI REMOVIDO.

            // Passo Final: Finalmente, excluir o próprio aluno
            $queryAluno = "DELETE FROM " . $this->table_name . " WHERE id_aluno = :id_aluno";
            $stmtAluno = $this->conn->prepare($queryAluno);
            $stmtAluno->bindParam(':id_aluno', $id);
            $stmtAluno->execute();

            // Se tudo deu certo, confirma as alterações no banco de dados
            $this->conn->commit();
            return true;

        } catch (Exception $e) {
            // Se algo deu errado, desfaz todas as alterações
            $this->conn->rollBack();
            // A linha de debug 'die(...)' foi removida.
            return false;
        }
    }

    /**
     * Busca alunos de uma turma específica.
     * @param int $idTurma O ID da turma.
     * @return array A lista de alunos da turma.
     */
    public function getAlunosByTurmaId($idTurma) {
        $query = "SELECT a.id_aluno, a.nome_aluno, a.ra, t.nome_turma FROM " . $this->table_name . " a
                  LEFT JOIN " . $this->turmas_table_name . " t ON a.id_turma = t.id_turma
                  WHERE a.id_turma = :id_turma ORDER BY a.nome_aluno ASC";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_turma', $idTurma, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

     public function findByRa($ra) {
        $query = "SELECT * FROM " . $this->table_name . " WHERE ra = :ra LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':ra', $ra);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}