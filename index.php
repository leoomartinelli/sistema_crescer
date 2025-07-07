<?php
// index.php
// Configurações para depuração (ATIVAR PARA VER ERROS DETALHADOS, DESATIVAR EM PRODUÇÃO!)
ini_set('display_errors', 1); // ATIVA A EXIBIÇÃO DE ERROS NO NAVEGADOR
ini_set('display_startup_errors', 1); // ATIVA ERROS DE INICIALIZAÇÃO
error_reporting(E_ALL); // Reporta todos os tipos de erros para o log

// Obtém a URI da requisição e o método
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$requestMethod = $_SERVER['REQUEST_METHOD'];

// IMPORTANT: Ajuste esta linha para o caminho do seu projeto no servidor web
// Ex: Se você acessa http://localhost/Sistema/public/login.html, então $basePath é '/Sistema'
$basePath = '/Sistema'; 

// Remove o basePath do requestUri
if (strpos($requestUri, $basePath) === 0) {
    $requestUri = substr($requestUri, strlen($basePath));
}

// Se a URL ainda começar com '/public', remova-o também.
// Isso é crucial porque os arquivos HTML estão dentro de 'public',
// mas as rotas internas da API não usam '/public'.
if (strpos($requestUri, '/public') === 0) {
    $requestUri = substr($requestUri, strlen('/public'));
}

// Garante que o requestUri comece com '/'
if (empty($requestUri) || $requestUri[0] !== '/') {
    $requestUri = '/' . $requestUri;
}


// Array de rotas públicas (que não exigem autenticação JWT)
// Agora, as rotas para os arquivos HTML e scripts utilitários NÃO terão o '/public' prefixo aqui,
// pois ele já foi removido do $requestUri.
$publicRoutes = [
    '/api/auth/login' => true,
    '/test_hash.php' => true,
    '/gerar_hash.php' => true,
    '/login.html' => true,
    '/crud_alunos.html' => true,
    '/crud_turmas.html' => true,
    '/aluno_dashboard.html' => true,
];

// Define o cabeçalho Content-Type. Por padrão, será JSON para a API.
// Se a requisição for para um arquivo HTML ou um script PHP público que não é API,
// o PHP não deve definir o Content-Type como JSON, mas sim deixar o servidor web lidar com isso.
header('Access-Control-Allow-Origin: *'); // Allow requests from any origin (for development)
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle OPTIONS request for CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Inclua o autoloader do Composer para as bibliotecas
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/Auth.php'; // Inclui as configurações de autenticação

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;

// Inclua os controladores necessários
require_once __DIR__ . '/api/controllers/AlunoController.php';
require_once __DIR__ . '/api/controllers/TurmaController.php';
require_once __DIR__ . '/api/controllers/AuthController.php'; // Controlador de Autenticação

// Instanciar os controladores com verificação de existência da classe
$alunoController = class_exists('AlunoController') ? new AlunoController() : null;
$turmaController = class_exists('TurmaController') ? new TurmaController() : null;
$authController = class_exists('AuthController') ? new AuthController() : null;


// Lógica de proteção de rotas JWT
// A validação JWT só é aplicada se a rota NÃO for pública E for uma rota de API
if (!isset($publicRoutes[$requestUri]) && strpos($requestUri, '/api/') === 0) {
    // Definir Content-Type JSON apenas para rotas de API protegidas
    header('Content-Type: application/json');

    $headers = getallheaders();
    $jwt = null;

    if (isset($headers['Authorization'])) {
        $authHeader = $headers['Authorization'];
        if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            $jwt = $matches[1];
        }
    }

    if (!$jwt) {
        http_response_code(401); // Unauthorized
        echo json_encode(['success' => false, 'message' => 'Token JWT ausente ou inválido.']);
        exit();
    }

    try {
        $decoded = JWT::decode($jwt, new Key(JWT_SECRET_KEY, JWT_ALGORITHM));
        $GLOBALS['user_data'] = (array) $decoded->data; // Armazena os dados do usuário
    } catch (ExpiredException $e) {
        http_response_code(401); // Unauthorized
        echo json_encode(['success' => false, 'message' => 'Token JWT expirado.']);
        exit();
    } catch (SignatureInvalidException $e) {
        http_response_code(401); // Unauthorized
        echo json_encode(['success' => false, 'message' => 'Assinatura JWT inválida.']);
        exit();
    } catch (Exception $e) {
        http_response_code(401); // Unauthorized
        echo json_encode(['success' => false, 'message' => 'Token JWT inválido: ' . $e->getMessage()]);
        exit();
    }
} else if (strpos($requestUri, '/api/') === 0) {
    // Se for uma rota de API pública, também define Content-Type JSON
    header('Content-Type: application/json');
}


// Roteamento
switch ($requestUri) {
    // Rota de Autenticação (PÚBLICA)
    case '/api/auth/login':
        if ($requestMethod === 'POST') {
            $authController->login();
        } else {
            http_response_code(405); // Method Not Allowed
            echo json_encode(['success' => false, 'message' => 'Método Não Permitido para /api/auth/login']);
        }
        break;

    // Rotas para Alunos (PROTEGIDAS)
    case '/api/alunos':
        // Permite GET para todos os papéis (admin, professor, aluno)
        if ($requestMethod === 'GET') {
            if ($GLOBALS['user_data']['role'] === 'admin' || $GLOBALS['user_data']['role'] === 'professor' || $GLOBALS['user_data']['role'] === 'aluno') {
                $alunoController->getAll();
            } else {
                http_response_code(403); // Forbidden
                echo json_encode(['success' => false, 'message' => 'Acesso negado. Você não tem permissão para listar alunos.']);
            }
        } elseif ($requestMethod === 'POST') {
            // POST (criar) restrito a admin/professor
            if ($GLOBALS['user_data']['role'] !== 'admin' && $GLOBALS['user_data']['role'] !== 'professor') {
                http_response_code(403); // Forbidden
                echo json_encode(['success' => false, 'message' => 'Acesso negado. Você não tem permissão para criar alunos.']);
                exit();
            }
            $alunoController->create();
        } else {
            http_response_code(405); // Method Not Allowed
            echo json_encode(['success' => false, 'message' => 'Método Não Permitido para /api/alunos']);
        }
        break;
    case (preg_match('/^\/api\/alunos\/(\d+)$/', $requestUri, $matches) ? true : false):
        // Permite GET para todos os papéis (admin, professor, aluno)
        // PUT/DELETE restrito a admin/professor
        if ($GLOBALS['user_data']['role'] === 'admin' || $GLOBALS['user_data']['role'] === 'professor' || $GLOBALS['user_data']['role'] === 'aluno') {
            $id = $matches[1];
            if ($requestMethod === 'GET') {
                $alunoController->getById($id);
            } elseif ($requestMethod === 'PUT' || $requestMethod === 'DELETE') {
                if ($GLOBALS['user_data']['role'] !== 'admin' && $GLOBALS['user_data']['role'] !== 'professor') {
                    http_response_code(403); // Forbidden
                    echo json_encode(['success' => false, 'message' => 'Acesso negado. Você não tem permissão para modificar alunos.']);
                    exit();
                }
                if ($requestMethod === 'PUT') {
                    $alunoController->update($id);
                } else { // DELETE
                    $alunoController->delete($id);
                }
            } else {
                http_response_code(405); // Method Not Allowed
                echo json_encode(['success' => false, 'message' => 'Método Não Permitido para /api/alunos/{id}']);
            }
        } else {
            http_response_code(403); // Forbidden
            echo json_encode(['success' => false, 'message' => 'Acesso negado. Você não tem permissão para acessar alunos.']);
            exit();
        }
        break;
    
    // Rotas para Turmas (PROTEGIDAS)
    case '/api/turmas':
        // GET permitido para todos os papéis (admin, professor, aluno) para carregar dropdowns
        if ($requestMethod === 'GET') {
            if ($GLOBALS['user_data']['role'] === 'admin' || $GLOBALS['user_data']['role'] === 'professor' || $GLOBALS['user_data']['role'] === 'aluno') {
                $turmaController->getAll();
            } else {
                http_response_code(403); // Forbidden
                echo json_encode(['success' => false, 'message' => 'Acesso negado. Você não tem permissão para listar turmas.']);
            }
        } elseif ($requestMethod === 'POST') {
            // POST (criar) restrito a admin/professor
            if ($GLOBALS['user_data']['role'] !== 'admin' && $GLOBALS['user_data']['role'] !== 'professor') {
                http_response_code(403); // Forbidden
                echo json_encode(['success' => false, 'message' => 'Acesso negado. Você não tem permissão para criar turmas.']);
                exit();
            }
            $turmaController->create();
        } else {
            http_response_code(405); // Method Not Allowed
            echo json_encode(['success' => false, 'message' => 'Método Não Permitido para /api/turmas']);
        }
        break;
    case (preg_match('/^\/api\/turmas\/(\d+)\/alunos$/', $requestUri, $matches) ? true : false):
        // GET permitido para todos os papéis (admin, professor, aluno)
        if ($GLOBALS['user_data']['role'] === 'admin' || $GLOBALS['user_data']['role'] === 'professor' || $GLOBALS['user_data']['role'] === 'aluno') {
            $idTurma = $matches[1];
            if ($requestMethod === 'GET') {
                $alunoController->getAlunosByTurma($idTurma);
            } else {
                http_response_code(405); // Method Not Allowed
                echo json_encode(['success' => false, 'message' => 'Método Não Permitido para /api/turmas/{id}/alunos']);
            }
        } else {
            http_response_code(403); // Forbidden
            echo json_encode(['success' => false, 'message' => 'Acesso negado. Você não tem permissão para consultar alunos por turma.']);
            exit();
        }
        break;
    case (preg_match('/^\/api\/turmas\/(\d+)$/', $requestUri, $matches) ? true : false):
        // Permite GET para todos os papéis (admin, professor, aluno)
        // PUT/DELETE restrito a admin/professor
        if ($GLOBALS['user_data']['role'] === 'admin' || $GLOBALS['user_data']['role'] === 'professor' || $GLOBALS['user_data']['role'] === 'aluno') {
            $id = $matches[1];
            if ($requestMethod === 'GET') {
                $turmaController->getById($id);
            } elseif ($requestMethod === 'PUT' || $requestMethod === 'DELETE') {
                if ($GLOBALS['user_data']['role'] !== 'admin' && $GLOBALS['user_data']['role'] !== 'professor') {
                    http_response_code(403); // Forbidden
                    echo json_encode(['success' => false, 'message' => 'Acesso negado. Você não tem permissão para modificar turmas.']);
                    exit();
                }
                if ($requestMethod === 'PUT') {
                    $turmaController->update($id);
                } else { // DELETE
                    $turmaController->delete($id);
                }
            } else {
                http_response_code(405); // Method Not Allowed
                echo json_encode(['success' => false, 'message' => 'Método Não Permitido para /api/turmas/{id}']);
            }
        } else {
            http_response_code(403); // Forbidden
            echo json_encode(['success' => false, 'message' => 'Acesso negado. Você não tem permissão para acessar turmas.']);
            exit();
        }
        break;

    // Casos para servir arquivos PHP diretamente (como utilitários)
    // Estes arquivos NÃO devem estar na pasta 'public' se o index.php está na raiz do Sistema.
    case '/test_hash.php':
    case '/gerar_hash.php':
        exit();

    // Casos para servir arquivos HTML diretamente
    // Estes arquivos são esperados DENTRO da pasta 'public'.
    case '/login.html':
    case '/crud_alunos.html':
    case '/crud_turmas.html':
    case '/aluno_dashboard.html':
        exit();

    default:
        http_response_code(404); // Not Found
        echo json_encode(['success' => false, 'message' => 'Endpoint não encontrado.']);
        break;
}
