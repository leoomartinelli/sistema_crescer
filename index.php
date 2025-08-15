<?php
// index.php

// -----------------------------------------------------------------------------
// CONFIGURAÇÕES GERAIS E DE SEGURANÇA
// -----------------------------------------------------------------------------
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('America/Sao_Paulo');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// -----------------------------------------------------------------------------
// AUTOLOAD E INCLUSÃO DE DEPENDÊNCIAS
// -----------------------------------------------------------------------------
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/Auth.php';
require_once __DIR__ . '/api/controllers/AlunoController.php';
require_once __DIR__ . '/api/controllers/TurmaController.php';
require_once __DIR__ . '/api/controllers/AuthController.php';
require_once __DIR__ . '/api/controllers/MensalidadeController.php';
require_once __DIR__ . '/api/controllers/ProfessorController.php';
require_once __DIR__ . '/api/controllers/DisciplinaController.php';
require_once __DIR__ . '/api/controllers/BoletimController.php';
require_once __DIR__ . '/api/controllers/InadimplenciaController.php';
require_once __DIR__ . '/api/controllers/ContratoController.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// -----------------------------------------------------------------------------
// PROCESSAMENTO DA ROTA (ROTEAMENTO)
// -----------------------------------------------------------------------------
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$requestMethod = $_SERVER['REQUEST_METHOD'];
$basePath = '/Sistema'; // <-- DEFINIÇÃO DA BASE PATH
if (strpos($requestUri, $basePath) === 0) {
    // CORREÇÃO AQUI: USAR $basePath, NÃO 'Sistemas'
    $requestUri = substr($requestUri, strlen($basePath)); 
}
if (empty($requestUri) || $requestUri[0] !== '/') {
    $requestUri = '/' . $requestUri;
}

// -----------------------------------------------------------------------------
// VALIDAÇÃO DE TOKEN JWT PARA ROTAS PROTEGIDAS
// -----------------------------------------------------------------------------
$publicRoutes = ['/api/auth/login' => true];

if (!isset($publicRoutes[$requestUri]) && strpos($requestUri, '/api/') === 0) {
    header('Content-Type: application/json');
    $headers = getallheaders();
    $jwt = null;

    if (isset($headers['Authorization']) && preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) {
        $jwt = $matches[1];
    }

    if (!$jwt) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Token JWT ausente ou inválido.']);
        exit();
    }

    try {
        $decoded = JWT::decode($jwt, new Key(JWT_SECRET_KEY, JWT_ALGORITHM));
        $GLOBALS['user_data'] = (array) $decoded->data;

        // NOVO LOG: Inspeciona o conteúdo de $GLOBALS['user_data'] após a decodificação
        error_log("DEBUG INDEX: Conteúdo de \$GLOBALS['user_data'] após decodificação em index.php: " . print_r($GLOBALS['user_data'], true));

    } catch (Exception $e) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Token JWT inválido ou expirado: ' . $e->getMessage()]);
        exit();
    }
} elseif (strpos($requestUri, '/api/') === 0) {
    header('Content-Type: application/json');
}

// -----------------------------------------------------------------------------
// FUNÇÕES AUXILIARES DE AUTORIZAÇÃO
// -----------------------------------------------------------------------------
function requireRole($allowedRoles) {
    $userRole = $GLOBALS['user_data']['role'] ?? null;
    if (!in_array($userRole, $allowedRoles)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Acesso negado. Você não tem permissão para esta ação.']);
        exit();
    }
}

// FUNÇÕES QUE AGORA ACESSAM DIRETAMENTE $GLOBALS['user_data']
function getUsernameFromToken() {
    return $GLOBALS['user_data']['username'] ?? null;
}

function getUserIdFromToken() {
    return $GLOBALS['user_data']['id_usuario'] ?? null;
}

// -----------------------------------------------------------------------------
// DIRECIONAMENTO DAS ROTAS
// -----------------------------------------------------------------------------
$alunoController = new AlunoController();
$turmaController = new TurmaController();
$authController = new AuthController();
$mensalidadeController = new MensalidadeController();
$professorController = new ProfessorController();
$disciplinaController = new DisciplinaController();
$boletimController = new BoletimController();
$inadimplenciaController = new InadimplenciaController();
$contratoController = new ContratoController();

switch (true) {
    // --- ROTA DE AUTENTICAÇÃO ---
    case $requestUri === '/api/auth/login':
        if ($requestMethod === 'POST') $authController->login();
        break;

    // --- ROTAS DE ALUNOS ---
    case $requestUri === '/api/alunos':
        $userRole = $GLOBALS['user_data']['role'] ?? null;
        // CORREÇÃO: Acessando diretamente as variáveis, pois as funções podem ter sido chamadas antes da definição do global em alguns casos.
        $usernameFromToken = $GLOBALS['user_data']['username'] ?? null;
        $userIdFromToken = $GLOBALS['user_data']['id_usuario'] ?? null;
        
        // Logs de debug atualizados para usar as variáveis locais
        error_log("DEBUG: Token User Role: " . ($userRole ?? 'NULL'));
        error_log("DEBUG: Token Username (RA): " . ($usernameFromToken ?? 'NULL'));
        error_log("DEBUG: Token User ID: " . ($userIdFromToken ?? 'NULL'));

        if ($requestMethod === 'GET') {
            if ($userRole === 'admin' || $userRole === 'professor') {
                $alunoController->getAll();
            } 
            elseif ($userRole === 'aluno') {
                $requestedRa = isset($_GET['ra']) ? $_GET['ra'] : null;
                
                if ($requestedRa && $requestedRa === $usernameFromToken) {
                    $alunoController->getAll();
                } else {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'message' => 'Acesso negado. Alunos só podem consultar os próprios dados.']);
                    exit();
                }
            } else {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Acesso negado.']);
                exit();
            }
        } 
        elseif ($requestMethod === 'POST') {
            requireRole(['admin', 'professor']);
            $alunoController->create();
        }
        break;
        
    case preg_match('/^\/api\/alunos\/(\d+)$/', $requestUri, $matches):
        requireRole(['admin', 'professor']);
        $id = $matches[1];
        if ($requestMethod === 'GET') $alunoController->getById($id);
        elseif ($requestMethod === 'PUT') $alunoController->update($id);
        elseif ($requestMethod === 'DELETE') $alunoController->delete($id);
        break;

    // --- ROTAS DE TURMAS ---
    case $requestUri === '/api/turmas':
        requireRole(['admin', 'professor']);
        if ($requestMethod === 'GET') $turmaController->getAll();
        elseif ($requestMethod === 'POST') $turmaController->create();
        break;
    case preg_match('/^\/api\/turmas\/(\d+)\/alunos$/', $requestUri, $matches):
        requireRole(['admin', 'professor']);
        $idTurma = $matches[1];
        if ($requestMethod === 'GET') $alunoController->getAlunosByTurma($idTurma);
        break;
    case preg_match('/^\/api\/turmas\/(\d+)$/', $requestUri, $matches):
        requireRole(['admin', 'professor']);
        $id = $matches[1];
        if ($requestMethod === 'GET') $turmaController->getById($id);
        elseif ($requestMethod === 'PUT') $turmaController->update($id);
        elseif ($requestMethod === 'DELETE') $turmaController->delete($id);
        break;

    // --- ROTAS DE PROFESSORES ---
    case $requestUri === '/api/professores':
        requireRole(['admin']); // Apenas admin pode gerenciar o CRUD completo de professores
        if ($requestMethod === 'GET') $professorController->getAll();
        elseif ($requestMethod === 'POST') $professorController->create();
        break;
    case preg_match('/^\/api\/professores\/(\d+)$/', $requestUri, $matches):
        requireRole(['admin']);
        $id = $matches[1];
        if ($requestMethod === 'GET') $professorController->getById($id);
        elseif ($requestMethod === 'PUT') $professorController->update($id);
        elseif ($requestMethod === 'DELETE') $professorController->delete($id);
        break;

    // Rotas para gerenciar turmas de um professor específico
    case preg_match('/^\/api\/professores\/(\d+)\/turmas$/', $requestUri, $matches):
        requireRole(['admin']); // Apenas admin pode adicionar/remover turmas de professores
        $idProfessor = $matches[1];
        if ($requestMethod === 'GET') $professorController->getProfessorTurmas($idProfessor);
        elseif ($requestMethod === 'POST') $professorController->addTurmaToProfessor($idProfessor);
        break;
    case preg_match('/^\/api\/professores\/(\d+)\/turmas\/(\d+)$/', $requestUri, $matches):
        requireRole(['admin']);
        $idProfessor = $matches[1];
        $idTurma = $matches[2];
        if ($requestMethod === 'DELETE') $professorController->removeTurmaFromProfessor($idProfessor, $idTurma);
        break;

    // Rota para o Dashboard do Professor (acessível pelo próprio professor)
    case $requestUri === '/api/professor/dashboard':
        requireRole(['professor', 'admin']); // Professor pode acessar o próprio dashboard. Admin também, para testes.
        if ($requestMethod === 'GET') {
            $userId = getUserIdFromToken();
            if ($userId) {
                $professorController->getDashboardData($userId);
            } else {
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'Não foi possível identificar o usuário a partir do token.']);
            }
        }
        break;

    // --- ROTAS DE DISCIPLINAS (NOVAS - GERENCIADAS POR ADMIN) ---
    case $requestUri === '/api/disciplinas':
        requireRole(['admin']);
        if ($requestMethod === 'GET') $disciplinaController->getAll();
        elseif ($requestMethod === 'POST') $disciplinaController->create();
        break;
    case preg_match('/^\/api\/disciplinas\/(\d+)$/', $requestUri, $matches):
        requireRole(['admin']);
        $id = $matches[1];
        if ($requestMethod === 'GET') $disciplinaController->getById($id);
        elseif ($requestMethod === 'PUT') $disciplinaController->update($id);
        elseif ($requestMethod === 'DELETE') $disciplinaController->delete($id);
        break;
    
    // NOVA ROTA: Obtém turmas associadas a uma disciplina
    case preg_match('/^\/api\/disciplinas\/(\d+)\/turmas$/', $requestUri, $matches):
        requireRole(['admin']);
        $idDisciplina = $matches[1];
        if ($requestMethod === 'GET') $disciplinaController->getDisciplinasAssociatedTurmas($idDisciplina);
        break;

    // Rotas para gerenciar disciplinas de uma turma (Associar disciplinas a turmas)
    case preg_match('/^\/api\/turmas\/(\d+)\/disciplinas$/', $requestUri, $matches):
        requireRole(['admin']); // Apenas admin pode adicionar/remover disciplinas de turmas
        $idTurma = $matches[1];
        if ($requestMethod === 'GET') $disciplinaController->getTurmaDisciplinas($idTurma);
        elseif ($requestMethod === 'POST') $disciplinaController->addDisciplinaToTurma($idTurma);
        break;
    case preg_match('/^\/api\/turmas\/(\d+)\/disciplinas\/(\d+)$/', $requestUri, $matches):
        requireRole(['admin']);
        $idTurma = $matches[1];
        $idDisciplina = $matches[2];
        if ($requestMethod === 'DELETE') $disciplinaController->removeDisciplinaFromTurma($idTurma, $idDisciplina);
        break;
    
    // --- ROTAS DE BOLETIM (NOVAS - GERENCIADAS POR PROFESSOR/ADMIN) ---
    // Rota para obter dados para a tela de gerenciamento de boletim (alunos e disciplinas de uma turma)
    case preg_match('/^\/api\/boletim\/turma\/(\d+)\/data$/', $requestUri, $matches):
        requireRole(['admin', 'professor']);
        $idTurma = $matches[1];
        if ($requestMethod === 'GET') $boletimController->getBoletimManagementData($idTurma);
        break;

    // Rota para obter o boletim completo de um aluno (usado na tela de gerenciamento de boletim)
    case $requestUri === '/api/boletim/aluno':
        // Acesso para Admin e Professor (podem ver qualquer boletim)
        // Acesso para Aluno (pode ver apenas o próprio boletim)
        $userRole = $GLOBALS['user_data']['role'] ?? null;
        // As variáveis abaixo são agora preenchidas diretamente de $GLOBALS['user_data']
        $userIdFromToken = $GLOBALS['user_data']['id_usuario'] ?? null;
        $usernameFromToken = $GLOBALS['user_data']['username'] ?? null;

        if ($requestMethod === 'GET') {
            if ($userRole === 'admin' || $userRole === 'professor') {
                // Admin e Professor podem usar os parâmetros id_aluno e ano_letivo normalmente
                $boletimController->getBoletimAluno();
            } elseif ($userRole === 'aluno') {
                // Aluno só pode consultar o próprio boletim
                $requestedIdAluno = isset($_GET['id_aluno']) ? (int)$_GET['id_aluno'] : null;
                $requestedAnoLetivo = isset($_GET['ano_letivo']) ? (int)$_GET['ano_letivo'] : null;

                require_once __DIR__ . '/api/models/Aluno.php';
                $alunoModel = new Aluno();
                // Assumindo que getAll pode filtrar por RA se o primeiro parâmetro for null para ID
                $alunoLogado = $alunoModel->getAll(null, $usernameFromToken, null);
                
                // Se encontrar o aluno logado e o ID solicitado for o dele
                if (!empty($alunoLogado) && $alunoLogado[0]['id_aluno'] === $requestedIdAluno) {
                    // Se o ID do aluno logado corresponde ao ID solicitado, permita
                    $boletimController->getBoletimAluno(); // Passa os parâmetros da URL
                } else {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'message' => 'Acesso negado. Alunos só podem consultar o próprio boletim.']);
                    exit();
                }
            } else {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Acesso negado. Papel de usuário inválido para esta ação.']);
                exit();
            }
        }
        break;

    // Rota para salvar/atualizar uma entrada de boletim
    case $requestUri === '/api/boletim/entry':
        requireRole(['admin', 'professor']);
        if ($requestMethod === 'POST') $boletimController->saveBoletimEntry();
        break;

    // Rota para deletar uma entrada específica do boletim (se necessário)
    case preg_match('/^\/api\/boletim\/entry\/(\d+)$/', $requestUri, $matches):
        requireRole(['admin', 'professor']);
        $idBoletim = $matches[1];
        if ($requestMethod === 'DELETE') $boletimController->deleteBoletimEntry($idBoletim);
        break;

    // --- ROTAS DE MENSALIDADES (ADMIN/PROFESSOR/ALUNO) ---
    case $requestUri === '/api/mensalidades':
        requireRole(['admin', 'professor']);
        if ($requestMethod === 'GET') $mensalidadeController->getAll();
        elseif ($requestMethod === 'POST') $mensalidadeController->create();
        break;
        
    case preg_match('/^\/api\/mensalidades\/(\d+)$/', $requestUri, $matches):
        if ($requestMethod === 'GET') {
            // A verificação de permissão (se o aluno é dono da fatura) é feita DENTRO do controller
            $mensalidadeController->getMensalidadeDetails($matches[1]);
        }
        elseif ($requestMethod === 'DELETE') {
            requireRole(['admin', 'professor']);
            $mensalidadeController->delete($matches[1]);
        }
        break;

    case preg_match('/^\/api\/mensalidades\/(\d+)\/pagar$/', $requestUri, $matches):
        requireRole(['admin', 'professor']);
        if ($requestMethod === 'PUT') $mensalidadeController->registerPayment($matches[1]);
        break;

    case $requestUri === '/api/aluno/mensalidades':
        requireRole(['aluno']);
        if ($requestMethod === 'GET') {
            $alunoRa = getUsernameFromToken();
            if ($alunoRa) {
                $mensalidadeController->getByAluno($alunoRa);
            } else {
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'Não foi possível identificar o aluno a partir do token.']);
            }
        }
        break;

    case preg_match('/^\/api\/aluno\/mensalidades\/(\d+)\/status$/', $requestUri, $matches):
        requireRole(['aluno']);
        if ($requestMethod === 'PUT') {
            $mensalidadeController->updateStudentMensalidadeStatus($matches[1]);
        }
        break;

    // --- ROTAS DE INADIMPLÊNCIA (NOVO) ---
    case $requestUri === '/api/inadimplencia/local':
        if ($requestMethod === 'GET') $inadimplenciaController->getInadimplenciaLocal();
        break;
        
    case $requestUri === '/api/inadimplencia/salvar':
        if ($requestMethod === 'POST') $inadimplenciaController->salvarInadimplencia();
        break;

    case preg_match('/^\/api\/contratos\/(\d+)\/assinar$/', $requestUri, $matches):
        $idContrato = $matches[1];
        if ($requestMethod === 'POST') {
             // A verificação de permissão é feita dentro do método assinar()
             $contratoController->assinar($idContrato);
        }
        break;    
    // --- ROTA PADRÃO (Endpoint não encontrado) ---
    default:
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Endpoint não encontrado.']);
        break;    
}