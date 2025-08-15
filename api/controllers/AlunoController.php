<?php
// api/controllers/AlunoController.php

require_once __DIR__ . '/../models/Aluno.php';

require_once __DIR__ . '/../models/Contrato.php';

require_once __DIR__ . '/../models/Mensalidade.php'; 

require_once __DIR__ . '/../../vendor/autoload.php';


use Dompdf\Dompdf;
use Dompdf\Options;

class AlunoController {
    private $model;
     private $contratoModel;
     private $mensalidadeModel;

    public function __construct() {
        $this->model = new Aluno();
        $this->contratoModel = new Contrato();
        $this->mensalidadeModel = new Mensalidade();
    }

    private function sendResponse($statusCode, $data) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
            http_response_code($statusCode);
        }
        echo json_encode($data);
    }

    public function getAll() {
        try {
            $searchName = isset($_GET['search']) ? $_GET['search'] : (isset($_GET['nome']) ? $_GET['nome'] : '');
            $searchRa = isset($_GET['ra']) ? $_GET['ra'] : '';
            $searchTurmaName = isset($_GET['turma']) ? $_GET['turma'] : '';
            
            $alunos = $this->model->getAll($searchName, $searchRa, $searchTurmaName);
            $this->sendResponse(200, ['success' => true, 'data' => $alunos]);
        } catch (Exception $e) {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro no servidor ao buscar alunos: ' . $e->getMessage()]);
        }
    }

    public function getById($id) {
        if (!isset($id) || !is_numeric($id)) {
            $this->sendResponse(400, ['success' => false, 'message' => 'ID do aluno inválido.']);
            return;
        }
        $aluno = $this->model->getById((int)$id);
        if ($aluno) {
            $this->sendResponse(200, ['success' => true, 'data' => $aluno]);
        } else {
            $this->sendResponse(404, ['success' => false, 'message' => 'Aluno não encontrado.']);
        }
    }

       public function create() {
        $data = json_decode(file_get_contents('php://input'), true);

        if (json_last_error() !== JSON_ERROR_NONE || empty($data['nome_aluno']) || empty($data['ra']) || empty($data['id_turma'])) {
            $this->sendResponse(400, ['success' => false, 'message' => 'Dados essenciais (aluno, RA, turma) estão faltando.']);
            return;
        }

        // Os nomes dos campos no HTML são 'valor_anuidade_total' e 'valor_matricula'
        $result = $this->model->create($data);

        if (is_numeric($result)) {
            $id_aluno = $result;
            $finalMessage = "Aluno criado com sucesso!";

            $caminho_pdf = $this->gerarContratoPDF($id_aluno, $data);
            if ($caminho_pdf) {
                $this->contratoModel->create(['id_aluno' => $id_aluno, 'caminho_pdf' => $caminho_pdf]);
                $finalMessage .= " Contrato gerado com sucesso!";
            } else {
                $finalMessage .= " Falha ao gerar o PDF do contrato.";
            }

            // --- LÓGICA DE GERAÇÃO DE COBRANÇAS CORRIGIDA ---
            $valorAnuidadeTotal = (float)($data['valor_anuidade_total'] ?? 0);
            $valorMatricula = (float)($data['valor_matricula'] ?? 0);
            $diaVencimentoMensalidade = $data['dia_vencimento_mensalidades'] ?? 10;
            $dataInicioAulas = $data['data_inicio'] ?? date('Y-m-d');

            if ($valorAnuidadeTotal > $valorMatricula) {
                try {
                    // 1. CRIA A COBRANÇA DA MATRÍCULA com vencimento em 10 dias
                    $dataVencimentoMatricula = (new DateTime())->modify('+10 days')->format('Y-m-d');
                    $dadosMatricula = [
                        'id_aluno' => $id_aluno, 'valor_mensalidade' => $valorMatricula,
                        'data_vencimento' => $dataVencimentoMatricula, 'descricao' => 'Matrícula'
                    ];
                    $this->mensalidadeModel->create($dadosMatricula);

                    // 2. CÁLCULO DEFINITIVO: (Anuidade - Matrícula) / 12
                    $valorMensalidadeCalculada = ($valorAnuidadeTotal - $valorMatricula) / 12;

                    // 3. CRIA AS 12 MENSALIDADES
                    $mensalidadesCriadas = 0;
                    $dataPrimeiraParcela = new DateTime($dataInicioAulas);
                    
                    for ($i = 1; $i <= 12; $i++) {
                        $dataVencimentoParcela = $dataPrimeiraParcela->format('Y-m-') . str_pad($diaVencimentoMensalidade, 2, '0', STR_PAD_LEFT);
                        $dadosMensalidade = [
                            'id_aluno' => $id_aluno,
                            'valor_mensalidade' => $valorMensalidadeCalculada,
                            'data_vencimento' => $dataVencimentoParcela,
                            'descricao' => "Mensalidade {$i}/12"
                        ];
                        if ($this->mensalidadeModel->create($dadosMensalidade)) {
                            $mensalidadesCriadas++;
                        }
                        $dataPrimeiraParcela->modify('+1 month');
                    }
                    $finalMessage .= " Matrícula e {$mensalidadesCriadas} mensalidades foram geradas.";

                } catch (Exception $e) {
                    $finalMessage .= " Erro fatal ao gerar cobranças: " . $e->getMessage();
                }
            } else {
                $finalMessage .= " AVISO: Anuidade deve ser maior que a Matrícula. Nenhuma cobrança gerada.";
            }
            // --- FIM DA LÓGICA ---

            $this->sendResponse(201, ['success' => true, 'message' => $finalMessage]);

        } elseif ($result === 'ra_exists') {
            $this->sendResponse(409, ['success' => false, 'message' => 'Já existe um aluno cadastrado com este RA.']);
        }  elseif ($result === 'parent_info_missing') {
            $this->sendResponse(400, ['success' => false, 'message' => 'É obrigatório preencher os dados completos (Nome e CPF) do Pai OU da Mãe.']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao criar aluno.']);
        }
    }

    public function update($id) {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($id) || !is_numeric($id) || empty($data['nome_aluno']) || empty($data['ra'])) {
            $this->sendResponse(400, ['success' => false, 'message' => 'Campos obrigatórios (ID, Nome do Aluno, RA) ausentes.']);
            return;
        }
        $data['id_aluno'] = (int)$id;

        $result = $this->model->update($data);

        if ($result === true) {
            $this->sendResponse(200, ['success' => true, 'message' => 'Aluno atualizado com sucesso!']);
        } elseif ($result === 'ra_exists') {
            $this->sendResponse(409, ['success' => false, 'message' => 'Já existe outro aluno cadastrado com este RA.']);
        } elseif ($result === 'parent_info_missing') {
            $this->sendResponse(400, ['success' => false, 'message' => 'É obrigatório preencher os dados completos (Nome e CPF) do Pai OU da Mãe.']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao atualizar aluno.']);
        }
    }

    public function delete($id) {
        if (!isset($id) || !is_numeric($id)) {
            $this->sendResponse(400, ['success' => false, 'message' => 'ID do aluno inválido.']);
            return;
        }
        if ($this->model->delete((int)$id)) {
            $this->sendResponse(200, ['success' => true, 'message' => 'Aluno excluído com sucesso!']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao excluir aluno.']);
        }
    }

    public function getAlunosByTurma($idTurma) {
        if (!isset($idTurma) || !is_numeric($idTurma)) {
            $this->sendResponse(400, ['success' => false, 'message' => 'ID da turma inválido.']);
            return;
        }
        $alunos = $this->model->getAlunosByTurmaId((int)$idTurma);
        if ($alunos !== null) {
            $this->sendResponse(200, ['success' => true, 'data' => $alunos]);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao buscar alunos para esta turma.']);
        }
    }

    private function gerarContratoPDF($id_aluno, $formData) {
        $aluno = $this->model->getById($id_aluno);
        if (!$aluno) return false;

        $anoLetivo = !empty($formData['data_inicio']) ? date('Y', strtotime($formData['data_inicio'])) : date('Y');
        $caminhoLogo = __DIR__ . '/../assets/logo_rodape.PNG';
        $logoBase64 = '';
        if (file_exists($caminhoLogo)) {
            $tipoImagem = pathinfo($caminhoLogo, PATHINFO_EXTENSION);
            $dadosImagem = file_get_contents($caminhoLogo);
            $logoBase64 = 'data:image/' . $tipoImagem . ';base64,' . base64_encode($dadosImagem);
        }

        $meses = ["", "Janeiro", "Fevereiro", "Março", "Abril", "Maio", "Junho", "Julho", "Agosto", "Setembro", "Outubro", "Novembro", "Dezembro"];
        $data_extenso = "São José dos Campos, " . date('d') . " de " . $meses[(int)date('m')] . " de " . date('Y');
        $html = file_get_contents(__DIR__ . '/../../templates/contrato_template.html');

        $desconto_extenso = $formData['percentual_desconto'] ?? 'zero';

        // --- LÓGICA DE CÁLCULO DEFINITIVA PARA O CONTRATO ---
        $valorAnuidadeTotal = (float)($formData['valor_anuidade_total'] ?? 0);
        $valorMatricula = (float)($formData['valor_matricula'] ?? 0);

        // CÁLCULO DEFINITIVO: (Anuidade - Matrícula) / 12
        $valorParcelaCalculado = ($valorAnuidadeTotal > $valorMatricula) ? (($valorAnuidadeTotal - $valorMatricula) / 12) : 0;
        
        // No contrato, o valor total da anuidade é o que foi digitado no campo anuidade.
        $valorAnuidadeDisplay = $valorAnuidadeTotal;

        // --- FIM DA LÓGICA ---

        $placeholders = [
            '{{CONTRATANTE_NOME}}' => $aluno['responsavel'] ?? '',
            '{{CONTRATANTE_RG}}' => $formData['rg_responsavel'] ?? '',
            '{{CONTRATANTE_CPF}}' => $aluno['cpf_responsavel'] ?? '',
            '{{CONTRATANTE_ENDERECO}}' => "{$aluno['endereco']}, {$aluno['bairro']} - {$aluno['cidade']}/{$aluno['estado']}, CEP: {$aluno['cep']}",
            '{{FIADOR_NOME}}' => $formData['nome_fiador'] ?? '________________________________________________',
            '{{FIADOR_RG}}' => $formData['rg_fiador'] ?? '',
            '{{FIADOR_CPF}}' => $formData['cpf_fiador'] ?? '',
            '{{FIADOR_ENDERECO}}' => $formData['endereco_fiador'] ?? '________________________________________________',
            '{{ALUNO_NOME}}' => $aluno['nome_aluno'],
            '{{ALUNO_RG}}' => $formData['rg_aluno'] ?? '',
            '{{ALUNO_CPF}}' => $formData['cpf_aluno'] ?? '',
            '{{ALUNO_ENDERECO}}' => "{$aluno['endereco']}, {$aluno['bairro']} - {$aluno['cidade']}/{$aluno['estado']}, CEP: {$aluno['cep']}",
            '{{ALUNO_TURMA}}' => $aluno['nome_turma'] ?? '___________________',
            '{{ANO_LETIVO}}' => $anoLetivo,
            
            // O placeholder {{VALOR_ANUIDADE}} mostra o valor do campo Anuidade Total
            '{{VALOR_ANUIDADE}}' => number_format($valorAnuidadeDisplay, 2, ',', '.'),
            '{{VALOR_MATRICULA}}' => number_format($valorMatricula, 2, ',', '.'),
            '{{VALOR_PARCELA}}' => number_format($valorParcelaCalculado, 2, ',', '.'),

            '{{VENCIMENTO_PRIMEIRA_PARCELA}}' => $formData['dia_vencimento_mensalidades'] ?? '__',
            '{{VENCIMENTO_ULTIMA_PARCELA}}' => $formData['dia_vencimento_mensalidades'] ?? '__',
            '{{DESCONTO_PERCENTUAL}}' => $formData['percentual_desconto'] ?? '__',
            '{{DESCONTO_PERCENTUAL_EXTENSO}}' => $desconto_extenso,
            '{{DIA_VENCIMENTO_PADRAO}}' => $formData['dia_vencimento_mensalidades'] ?? '10',
            '{{CONTRATANTE_ASSINATURA_1}}' => $aluno['responsavel'] ?? '___________________________________________',
            '{{CONTRATANTE_ASSINATURA_2}}' => $aluno['nome_pai'] ?: ($aluno['nome_mae'] ?: '___________________________________________'),
            '{{DATA_ASSINATURA}}' => $data_extenso,
            '{{LOGO_SRC}}' => $logoBase64
        ];

        foreach ($placeholders as $key => $value) {
            $html = str_replace($key, htmlspecialchars($value, ENT_QUOTES, 'UTF-8'), $html);
        }

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $uploadDir = __DIR__ . '/../../uploads/contratos/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $fileName = 'Contrato-'.$aluno['ra'].'-'.uniqid().'.pdf';
        $filePath = $uploadDir . $fileName;
        file_put_contents($filePath, $dompdf->output());

        return 'uploads/contratos/' . $fileName;
    }
    
}