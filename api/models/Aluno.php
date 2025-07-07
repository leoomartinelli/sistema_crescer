<?php
// api/models/Aluno.php

require_once __DIR__ . '/../../config/Database.php'; // Caminho corrigido para Database.php
require_once __DIR__ . '/../models/Usuario.php'; // Inclui o modelo Usuario

class Aluno {
    private $conn;
    private $table_name = "alunos";
    private $turmas_table_name = "turmas";
    private $usuarioModel; // Adiciona a propriedade para o modelo Usuario

    // Construtor que inicializa a conexão com o banco de dados
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->usuarioModel = new Usuario(); // Instancia o modelo Usuario
    }

    /**
     * Limpa números (telefone/celular/CPF), removendo caracteres não numéricos.
     * @param string $number O número a ser limpo.
     * @return string O número contendo apenas dígitos.
     */
    private function cleanNumber($number) {
        return preg_replace('/\D/', '', $number); // Remove tudo que não for dígito
    }

    /**
     * Verifica se um aluno com o RA (Registro Acadêmico) fornecido já existe.
     * @param string $ra O RA a ser verificado.
     * @param int|null $excludeId O ID do aluno a ser excluído da verificação (para atualizações).
     * @return bool Retorna true se o RA já existe, false caso contrário.
     */
    public function existsByRa($ra, $excludeId = null) {
        $query = "SELECT COUNT(*) FROM " . $this->table_name . " WHERE ra = :ra";
        
        if ($excludeId !== null) {
            $query .= " AND id_aluno != :exclude_id";
        }

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":ra", $ra);
        
        if ($excludeId !== null) {
            $stmt->bindParam(":exclude_id", $excludeId, PDO::PARAM_INT);
        }

        $stmt->execute();
        return $stmt->fetchColumn() > 0;
    }

    /**
     * Valida se pelo menos um dos pais (pai ou mãe) tem nome e CPF preenchidos.
     * @param array $data Os dados do aluno contendo nome_pai, cpf_pai, nome_mae, cpf_mae.
     * @return bool Retorna true se a validação passar, false caso contrário.
     */
    private function validateParentInfo($data) {
        $hasFatherInfo = !empty($data['nome_pai']) && !empty($data['cpf_pai']);
        $hasMotherInfo = !empty($data['nome_mae']) && !empty($data['cpf_mae']);

        return $hasFatherInfo || $hasMotherInfo;
    }

    /**
     * Obtém o ID de uma turma pelo nome.
     * Se a turma não existir, ela pode ser criada (opcional, mas útil para importação).
     * @param string $nomeTurma O nome da turma.
     * @return int|null O ID da turma ou null se não encontrada/criada.
     */
    private function getTurmaIdByName($nomeTurma) {
        if (empty($nomeTurma)) {
            return null;
        }

        $query = "SELECT id_turma FROM " . $this->turmas_table_name . " WHERE nome_turma = :nome_turma LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":nome_turma", $nomeTurma);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            return $row['id_turma'];
        } else {
            // Opcional: Criar a turma se ela não existir
            $insertQuery = "INSERT INTO " . $this->turmas_table_name . " (nome_turma) VALUES (:nome_turma)";
            $insertStmt = $this->conn->prepare($insertQuery);
            $insertStmt->bindParam(":nome_turma", $nomeTurma);
            if ($insertStmt->execute()) {
                return $this->conn->lastInsertId();
            }
        }
        return null;
    }


    /**
     * Cria um novo aluno no banco de dados e um usuário associado.
     * @param array $data Os dados do aluno a serem inseridos.
     * @return bool|string Retorna true em caso de sucesso, 'ra_exists' se o RA já existir, 'parent_info_missing' se a validação dos pais falhar, ou false em caso de erro.
     */
    public function create($data) {
        // Validação básica dos campos obrigatórios
        if (empty($data['nome_aluno']) || empty($data['ra'])) {
            return false;
        }

        // Validação da informação dos pais (pelo menos um deve ter nome e CPF)
        if (!$this->validateParentInfo($data)) {
            error_log("Tentativa de criar aluno sem informações mínimas de pai/mãe.");
            return 'parent_info_missing';
        }

        // Limpa os números de telefone/celular e CPFs
        $telefone_responsavel_cleaned = isset($data['telefone_responsavel']) ? $this->cleanNumber($data['telefone_responsavel']) : null;
        $celular_responsavel_cleaned = isset($data['celular_responsavel']) ? $this->cleanNumber($data['celular_responsavel']) : null;
        $cpf_pai_cleaned = isset($data['cpf_pai']) ? $this->cleanNumber($data['cpf_pai']) : null;
        $cpf_mae_cleaned = isset($data['cpf_mae']) ? $this->cleanNumber($data['cpf_mae']) : null;

        // Verifica a unicidade do RA
        if ($this->existsByRa($data['ra'])) {
            error_log("Tentativa de criar aluno com RA existente: " . $data['ra']);
            return 'ra_exists';
        }

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

        // Bind dos parâmetros
        $stmt->bindParam(":nome_aluno", $data['nome_aluno']);
        $stmt->bindParam(":ra", $data['ra']);
        $stmt->bindParam(":data_nascimento", $data['data_nascimento']);
        $stmt->bindParam(":idade", $data['idade'], PDO::PARAM_INT);
        $stmt->bindParam(":id_turma", $data['id_turma'], PDO::PARAM_INT);
        $stmt->bindParam(":periodo", $data['periodo']);
        $stmt->bindParam(":endereco", $data['endereco']);
        $stmt->bindParam(":complemento", $data['complemento']);
        $stmt->bindParam(":bairro", $data['bairro']);
        $stmt->bindParam(":cidade", $data['cidade']);
        $stmt->bindParam(":cep", $data['cep']);
        $stmt->bindParam(":estado", $data['estado']);
        $stmt->bindParam(":responsavel", $data['responsavel']);
        $stmt->bindParam(":telefone_responsavel", $telefone_responsavel_cleaned);
        $stmt->bindParam(":celular_responsavel", $celular_responsavel_cleaned);
        $stmt->bindParam(":email_responsavel", $data['email_responsavel']);
        $stmt->bindParam(":cpf_responsavel", $data['cpf_responsavel']);
        // Novos campos de pai/mãe
        $stmt->bindParam(":nome_pai", $data['nome_pai']);
        $stmt->bindParam(":cpf_pai", $cpf_pai_cleaned);
        $stmt->bindParam(":nome_mae", $data['nome_mae']);
        $stmt->bindParam(":cpf_mae", $cpf_mae_cleaned);

        if ($stmt->execute()) {
            // NOVO: Criar usuário para o aluno
            $alunoUsername = $data['ra'];
            $alunoPassword = null;
            if (!empty($data['data_nascimento'])) {
                try {
                    $dateObj = new DateTime($data['data_nascimento']);
                    $alunoPassword = $dateObj->format('dmY'); // Formato DDMMYYYY
                } catch (Exception $e) {
                    error_log("Erro ao formatar data de nascimento para senha do aluno: " . $e->getMessage());
                }
            }

            if ($alunoPassword) {
                $usuarioData = [
                    'username' => $alunoUsername,
                    'password' => $alunoPassword,
                    'role' => 'aluno'
                ];
                // Tenta criar o usuário. Se o RA já for um username, ele não será criado novamente.
                $userCreationResult = $this->usuarioModel->create($usuarioData);
                if (!$userCreationResult) {
                    error_log("Falha ou RA já existente como usuário para o aluno RA: " . $alunoUsername);
                }
            } else {
                error_log("Data de nascimento ausente ou inválida para criar usuário do aluno RA: " . $alunoUsername);
            }
            return true;
        }
        error_log("Erro ao criar aluno: " . implode(" ", $stmt->errorInfo()));
        return false;
    }

    /**
     * Obtém todos os alunos do banco de dados, com filtros opcionais, incluindo o nome da turma.
     * @param string $searchName Filtra por nome do aluno.
     * @param string $searchRa Filtra por RA do aluno.
     * @param string $searchTurmaName Filtra por nome da turma.
     * @return array Retorna um array de alunos.
     */
    public function getAll($searchName = '', $searchRa = '', $searchTurmaName = '') {
        // Seleciona explicitamente todas as colunas da tabela 'alunos' (a)
        // e o nome da turma (t.nome_turma). Inclui data_nascimento para reconstruir a senha no frontend.
        $query = "SELECT 
                    a.id_aluno, a.nome_aluno, a.ra, a.data_nascimento, a.idade, a.id_turma, a.periodo, 
                    a.endereco, a.complemento, a.bairro, a.cidade, a.cep, a.estado,
                    a.responsavel, a.telefone_responsavel, a.celular_responsavel, a.email_responsavel, a.cpf_responsavel, 
                    a.nome_pai, a.cpf_pai, a.nome_mae, a.cpf_mae,
                    t.nome_turma 
                  FROM " . $this->table_name . " a
                  LEFT JOIN " . $this->turmas_table_name . " t ON a.id_turma = t.id_turma
                  WHERE 1=1";
        $params = [];

        if (!empty($searchName)) {
            $query .= " AND a.nome_aluno LIKE :searchName";
            $params[':searchName'] = '%' . $searchName . '%';
        }
        if (!empty($searchRa)) {
            $query .= " AND a.ra LIKE :searchRa";
            $params[':searchRa'] = '%' . $searchRa . '%';
        }
        if (!empty($searchTurmaName)) {
            $query .= " AND t.nome_turma LIKE :searchTurmaName"; // Filtra pelo nome da turma
            $params[':searchTurmaName'] = '%' . $searchTurmaName . '%';
        }

        $query .= " ORDER BY a.nome_aluno ASC";

        $stmt = $this->conn->prepare($query);
        foreach ($params as $key => &$val) {
            $stmt->bindParam($key, $val);
        }

        try {
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Erro ao buscar alunos: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Obtém um único aluno por ID, incluindo o nome da turma.
     * @param int $id O ID do aluno.
     * @return array|false Retorna os dados do aluno ou false se não encontrado.
     */
    public function getById($id) {
        // Seleciona explicitamente todas as colunas da tabela 'alunos' (a)
        // e o nome da turma (t.nome_turma). Inclui data_nascimento para reconstruir a senha no frontend.
        $query = "SELECT 
                    a.id_aluno, a.nome_aluno, a.ra, a.data_nascimento, a.idade, a.id_turma, a.periodo, 
                    a.endereco, a.complemento, a.bairro, a.cidade, a.cep, a.estado,
                    a.responsavel, a.telefone_responsavel, a.celular_responsavel, a.email_responsavel, a.cpf_responsavel, 
                    a.nome_pai, a.cpf_pai, a.nome_mae, a.cpf_mae,
                    t.nome_turma 
                  FROM " . $this->table_name . " a
                  LEFT JOIN " . $this->turmas_table_name . " t ON a.id_turma = t.id_turma
                  WHERE a.id_aluno = :id LIMIT 0,1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Atualiza um aluno existente no banco de dados.
     * @param array $data Os dados do aluno a serem atualizados.
     * @return bool|string Retorna true em caso de sucesso, 'ra_exists' se o RA já existir em outro aluno, 'parent_info_missing' se a validação dos pais falhar, ou false em caso de erro.
     */
    public function update($data) {
        // Validação básica dos campos obrigatórios
        if (empty($data['id_aluno']) || empty($data['nome_aluno']) || empty($data['ra'])) {
            return false;
        }

        // Validação da informação dos pais (pelo menos um deve ter nome e CPF)
        if (!$this->validateParentInfo($data)) {
            error_log("Tentativa de atualizar aluno sem informações mínimas de pai/mãe.");
            return 'parent_info_missing';
        }

        // Limpa os números de telefone/celular e CPFs
        $telefone_responsavel_cleaned = isset($data['telefone_responsavel']) ? $this->cleanNumber($data['telefone_responsavel']) : null;
        $celular_responsavel_cleaned = isset($data['celular_responsavel']) ? $this->cleanNumber($data['celular_responsavel']) : null;
        $cpf_pai_cleaned = isset($data['cpf_pai']) ? $this->cleanNumber($data['cpf_pai']) : null;
        $cpf_mae_cleaned = isset($data['cpf_mae']) ? $this->cleanNumber($data['cpf_mae']) : null;

        // Verifica a unicidade do RA, excluindo o próprio aluno
        if ($this->existsByRa($data['ra'], $data['id_aluno'])) {
            error_log("Tentativa de atualizar aluno com RA já existente em outro aluno: " . $data['ra']);
            return 'ra_exists';
        }

        $query = "UPDATE " . $this->table_name . " SET 
                    nome_aluno = :nome_aluno, 
                    ra = :ra, 
                    data_nascimento = :data_nascimento, 
                    idade = :idade, 
                    id_turma = :id_turma, 
                    periodo = :periodo, 
                    endereco = :endereco, 
                    complemento = :complemento, 
                    bairro = :bairro, 
                    cidade = :cidade, 
                    cep = :cep, 
                    estado = :estado, 
                    responsavel = :responsavel, 
                    telefone_responsavel = :telefone_responsavel, 
                    celular_responsavel = :celular_responsavel, 
                    email_responsavel = :email_responsavel, 
                    cpf_responsavel = :cpf_responsavel,
                    nome_pai = :nome_pai,
                    cpf_pai = :cpf_pai,
                    nome_mae = :nome_mae,
                    cpf_mae = :cpf_mae
                WHERE id_aluno = :id_aluno";
        
        $stmt = $this->conn->prepare($query);

        // Bind dos parâmetros
        $stmt->bindParam(":nome_aluno", $data['nome_aluno']);
        $stmt->bindParam(":ra", $data['ra']);
        $stmt->bindParam(":data_nascimento", $data['data_nascimento']);
        $stmt->bindParam(":idade", $data['idade'], PDO::PARAM_INT);
        $stmt->bindParam(":id_turma", $data['id_turma'], PDO::PARAM_INT);
        $stmt->bindParam(":periodo", $data['periodo']);
        $stmt->bindParam(":endereco", $data['endereco']);
        $stmt->bindParam(":complemento", $data['complemento']);
        $stmt->bindParam(":bairro", $data['bairro']);
        $stmt->bindParam(":cidade", $data['cidade']);
        $stmt->bindParam(":cep", $data['cep']);
        $stmt->bindParam(":estado", $data['estado']); 
        $stmt->bindParam(":responsavel", $data['responsavel']);
        $stmt->bindParam(":telefone_responsavel", $telefone_responsavel_cleaned);
        $stmt->bindParam(":celular_responsavel", $celular_responsavel_cleaned);
        $stmt->bindParam(":email_responsavel", $data['email_responsavel']);
        $stmt->bindParam(":cpf_responsavel", $data['cpf_responsavel']);
        // Novos campos de pai/mãe
        $stmt->bindParam(":nome_pai", $data['nome_pai']);
        $stmt->bindParam(":cpf_pai", $cpf_pai_cleaned);
        $stmt->bindParam(":nome_mae", $data['nome_mae']);
        $stmt->bindParam(":cpf_mae", $cpf_mae_cleaned);
        $stmt->bindParam(":id_aluno", $data['id_aluno'], PDO::PARAM_INT);

        if ($stmt->execute()) {
            // NOVO: Atualizar usuário do aluno (se o RA mudou, ou se não existia)
            $alunoUsername = $data['ra'];
            $alunoPassword = null;
            if (!empty($data['data_nascimento'])) {
                try {
                    $dateObj = new DateTime($data['data_nascimento']);
                    $alunoPassword = $dateObj->format('dmY'); // Formato DDMMYYYY
                } catch (Exception $e) {
                    error_log("Erro ao formatar data de nascimento para senha do aluno (update): " . $e->getMessage());
                }
            }

            if ($alunoPassword) {
                // Tenta encontrar o usuário existente pelo RA antigo (se o RA mudou)
                // Ou cria se não existir.
                // A lógica aqui pode ser mais complexa se o RA for a chave primária do usuário.
                // Para simplificar, tentaremos criar/atualizar o usuário.
                $usuarioData = [
                    'username' => $alunoUsername,
                    'password' => $alunoPassword,
                    'role' => 'aluno'
                ];
                // Uma maneira simples é tentar criar. Se o username já existe, o método create do Usuario falhará silenciosamente (ou logará).
                // Para updates de username, seria necessário um método update no Usuario.php
                $existingUser = $this->usuarioModel->findByUsername($alunoUsername);
                if ($existingUser) {
                    // Se o usuário já existe, e a senha é baseada na data de nascimento,
                    // podemos atualizar o hash da senha caso a data de nascimento tenha mudado.
                    // Isso requer um método de update no Usuario.php.
                    // Por enquanto, vamos apenas logar se a data de nascimento mudou e o hash não.
                    if (!password_verify($alunoPassword, $existingUser['password_hash'])) {
                         error_log("Aviso: Data de nascimento do aluno '{$alunoUsername}' mudou, mas o hash da senha do usuário não foi atualizado. Implementar Usuario->update().");
                    }
                } else {
                    $userCreationResult = $this->usuarioModel->create($usuarioData);
                    if (!$userCreationResult) {
                        error_log("Falha ao criar usuário para o aluno RA: " . $alunoUsername . " durante a atualização.");
                    }
                }
            }
            return true;
        }
        error_log("Erro ao atualizar aluno: " . implode(" ", $stmt->errorInfo()));
        return false;
    }

    /**
     * Deleta um aluno do banco de dados e o usuário associado.
     * @param int $id O ID do aluno a ser deletado.
     * @return bool Retorna true em caso de sucesso, false em caso de erro.
     */
    public function delete($id) {
        // Primeiro, obtenha o RA do aluno para deletar o usuário
        $aluno = $this->getById($id);
        if ($aluno && !empty($aluno['ra'])) {
            $this->usuarioModel->deleteByUsername($aluno['ra']); // Chama novo método no UsuarioModel
        }

        $query = "DELETE FROM " . $this->table_name . " WHERE id_aluno = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id", $id, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return true;
        }
        error_log("Erro ao deletar aluno: " . implode(" ", $stmt->errorInfo()));
        return false;
    }

    /**
     * Obtém alunos de uma turma específica com informações essenciais.
     * @param int $idTurma O ID da turma.
     * @return array Retorna um array de alunos.
     */
    public function getAlunosByTurmaId($idTurma) {
        $query = "SELECT 
                    a.id_aluno, 
                    a.nome_aluno, 
                    a.ra, 
                    a.idade, 
                    a.periodo, 
                    a.responsavel, 
                    a.telefone_responsavel, 
                    a.email_responsavel,
                    a.data_nascimento, -- Inclui data_nascimento para reconstrução da senha
                    t.nome_turma
                  FROM " . $this->table_name . " a
                  JOIN " . $this->turmas_table_name . " t ON a.id_turma = t.id_turma
                  WHERE a.id_turma = :id_turma
                  ORDER BY a.nome_aluno ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_turma', $idTurma, PDO::PARAM_INT);

        try {
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Erro ao buscar alunos por turma: " . $e->getMessage());
            return [];
        }
    }
}
