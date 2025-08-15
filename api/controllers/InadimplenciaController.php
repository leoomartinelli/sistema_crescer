<?php
// api/controllers/InadimplenciaController.php

require_once __DIR__ . '/../models/Responsavel.php';
require_once __DIR__ . '/../models/Pendencia.php';

class InadimplenciaController {
    private $responsavelModel;
    private $pendenciaModel;
    private $id_escola_padrao = 1; // ID da escola a ser usada para a consulta

    public function __construct() {
        $this->responsavelModel = new Responsavel();
        $this->pendenciaModel = new Pendencia();
    }

    private function sendResponse($statusCode, $data) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
            http_response_code($statusCode);
        }
        echo json_encode($data);
    }

    public function getInadimplenciaLocal() {
        $cpf = $_GET['cpf'] ?? null;
        if (!$cpf) {
            $this->sendResponse(400, ['success' => false, 'message' => 'CPF é obrigatório.']);
            return;
        }
        
        $responsavel = $this->responsavelModel->findByCpf($cpf);

        if ($responsavel) {
            $pendencias = $this->pendenciaModel->findPendenciasByResponsavelId($responsavel['id_responsavel']);
            
            $valorTotal = array_reduce($pendencias, function($sum, $item) {
                return $sum + ($item['valor'] ?? 0);
            }, 0);

            $this->sendResponse(200, [
                'success' => true,
                'status' => count($pendencias) > 0 ? "pendências encontradas" : "nenhuma pendência apontada",
                'total_pendencias' => count($pendencias),
                'valor_total' => $valorTotal,
                'nome' => $responsavel['nome'],
                'cpf' => $responsavel['cpf'],
                'results' => $pendencias
            ]);
        } else {
            // Nenhum responsável encontrado localmente, o frontend deve perguntar se quer pesquisar externamente
            $this->sendResponse(404, ['success' => false, 'message' => 'Responsável não encontrado localmente.']);
        }
    }
    
    public function salvarInadimplencia() {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['cpf']) || !isset($data['nome']) || !isset($data['pendencias'])) {
            $this->sendResponse(400, ['success' => false, 'message' => 'Dados de inadimplência incompletos.']);
            return;
        }

        $cpf = $data['cpf'];
        $nome = $data['nome'];
        $pendencias = $data['pendencias'];
        $cep = $data['cep'] ?? null;
        
        $responsavel = $this->responsavelModel->findByCpf($cpf);
        
        if (!$responsavel) {
            // Se o responsável não existe, cria um novo
            $responsavel_data = [
                'nome' => $nome,
                'cpf' => $cpf,
                'cep' => $cep,
                'id_escola' => $this->id_escola_padrao // Adicionar o ID da escola aqui
            ];
            $id_responsavel = $this->responsavelModel->create($responsavel_data);
            if (!$id_responsavel) {
                $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao salvar o responsável.']);
                return;
            }
        } else {
            $id_responsavel = $responsavel['id_responsavel'];
        }
        
        $pendencias_salvas_com_sucesso = 0;
        foreach ($pendencias as $item) {
            $item['id_responsavel'] = $id_responsavel;
            $item['id_escola'] = $this->id_escola_padrao; // Adicionar o ID da escola aqui
            
            $result = $this->pendenciaModel->create($item);
            if ($result === true) {
                $pendencias_salvas_com_sucesso++;
            }
        }
        
        $this->sendResponse(200, ['success' => true, 'message' => "$pendencias_salvas_com_sucesso pendências salvas ou atualizadas com sucesso."]);
    }
}