<?php
// ATIVAR LOGS DETALHADOS (manter ativo por enquanto)
ini_set('log_errors', 1);
ini_set('error_log', '/var/log/apache2/error.log'); // Ajuste se necessário
error_reporting(E_ALL);
ini_set('display_errors', 0); // NÃO mostra erros para o usuário

session_start();
header("Content-Type: application/json; charset=UTF-8");

// --- CONEXÃO COM O BANCO DE DADOS ---
$servername = "localhost"; $username = "borarangar_user"; $password = "Tredf1234----"; $dbname = "borarangar_db";
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    $conn->set_charset('utf8mb4'); // Garante UTF8
} catch(mysqli_sql_exception $e) {
    error_log("CLIENTE_API: Falha CRÍTICA na conexão DB: " . $e->getMessage());
    http_response_code(500); echo json_encode(["status" => "error", "message" => "Erro interno (DB Connect)"]); exit();
}

$method = $_SERVER['REQUEST_METHOD'];

// =============================================================
// MÉTODO POST - REGISTRO, LOGIN, LOGOUT
// =============================================================
if ($method === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- LÓGICA DE REGISTRO ---
    if ($action === 'registrar') {
        $restaurante_id = filter_input(INPUT_POST, 'restaurante_id', FILTER_VALIDATE_INT);
        $nome = trim($_POST['nome'] ?? '');
        $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
        $senha = $_POST['senha'] ?? '';
        $telefone = trim($_POST['telefone'] ?? '');
        $endereco = trim($_POST['endereco'] ?? '');

        if (!$restaurante_id || !$nome || !$email || !$senha || !$telefone || !$endereco) {
            http_response_code(400); echo json_encode(['status' => 'error', 'message' => 'Todos os campos são obrigatórios.']); exit();
        }
        $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
        if ($senha_hash === false) { http_response_code(500); echo json_encode(['status' => 'error', 'message' => 'Erro ao processar senha.']); exit(); }

        $stmt = null; 
        try {
            $stmt = $conn->prepare("INSERT INTO clientes (restaurante_id, nome, email, senha, telefone, endereco) VALUES (?, ?, ?, ?, ?, ?)");
            if (!$stmt) { throw new Exception("Erro ao preparar a consulta."); } 
            $stmt->bind_param("isssss", $restaurante_id, $nome, $email, $senha_hash, $telefone, $endereco);
            $stmt->execute(); 
            error_log("CLIENTE_API Registrar: Sucesso! Cliente ID: " . $stmt->insert_id);
            echo json_encode(['status' => 'success', 'message' => 'Cadastro realizado com sucesso!']);

        } catch (mysqli_sql_exception $e) {
            error_log("CLIENTE_API Registrar: Erro SQL EXECUTE: (" . $e->getCode() . ") " . $e->getMessage()); 
            if ($e->getCode() == 1062) {
                http_response_code(409); // Conflict
                echo json_encode(['status' => 'error', 'message' => 'Este e-mail já está cadastrado neste restaurante.']);
            } else {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => 'Não foi possível realizar o cadastro. Tente novamente mais tarde.']);
            }
        } catch (Exception $general_e) {
             error_log("CLIENTE_API Registrar: Erro Geral: " . $general_e->getMessage());
             http_response_code(500);
             echo json_encode(['status' => 'error', 'message' => 'Erro interno ao processar cadastro.']);
        } finally {
            if ($stmt instanceof mysqli_stmt) { $stmt->close(); }
        }
        exit(); 
    }

    // --- LÓGICA DE LOGIN ---
    elseif ($action === 'login') {
        $stmt = null;
        try {
            $restaurante_id = filter_input(INPUT_POST, 'restaurante_id', FILTER_VALIDATE_INT);
            $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
            $senha = $_POST['senha'] ?? '';
            if (!$restaurante_id || !$email || !$senha) { http_response_code(400); throw new Exception('Dados de login inválidos.'); }

            $stmt = $conn->prepare("SELECT id, nome, senha, telefone, endereco FROM clientes WHERE email = ? AND restaurante_id = ?");
            if (!$stmt) throw new Exception("Erro prepare login.");
            $stmt->bind_param("si", $email, $restaurante_id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 1) {
                $cliente = $result->fetch_assoc();
                if (password_verify($senha, $cliente['senha'])) {
                    
                    session_regenerate_id(true); // <-- CORREÇÃO DA SESSÃO

                    $_SESSION['cliente'] = ['id' => $cliente['id'], 'nome' => $cliente['nome'], 'telefone' => $cliente['telefone'], 'endereco' => $cliente['endereco'], 'restaurante_id' => $restaurante_id];
                    echo json_encode(['status' => 'success', 'data' => $_SESSION['cliente']]);
                } else { http_response_code(401); echo json_encode(['status' => 'error', 'message' => 'Senha incorreta.']); }
            } else { http_response_code(404); echo json_encode(['status' => 'error', 'message' => 'E-mail não encontrado.']); }

        } catch (mysqli_sql_exception $e) {
            error_log("CLIENTE_API Login: Erro SQL: ".$e->getMessage());
            http_response_code(500); echo json_encode(['status' => 'error', 'message' => 'Erro interno no login.']);
        } catch (Exception $e) {
            http_response_code(400); echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); 
        } finally {
             if ($stmt instanceof mysqli_stmt) { $stmt->close(); }
        }
        exit();
    }

    // --- LÓGICA DE LOGOUT ---
    elseif ($action === 'logout') {
        session_destroy();
        echo json_encode(['status' => 'success']); 
        exit();
    }
    else { http_response_code(400); echo json_encode(['status' => 'error', 'message' => 'Ação inválida.']); exit(); }
}
// =============================================================
// MÉTODO GET - VERIFICAR SESSÃO
// =============================================================
elseif ($method === 'GET') {
    if (isset($_SESSION['cliente'])) { echo json_encode(['status' => 'success', 'data' => $_SESSION['cliente']]); }
    else { http_response_code(401); echo json_encode(['status' => 'error', 'message' => 'Não autenticado.']); }
    exit();
}
// =============================================================
// MÉTODO PUT - ATUALIZAR PERFIL
// =============================================================
elseif ($method === 'PUT') {
     $stmt = null;
     try {
        if (!isset($_SESSION['cliente']['id'])) { http_response_code(403); throw new Exception('Acesso negado PUT.'); }
        $cliente_id_logado = $_SESSION['cliente']['id'];
        $data = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) { http_response_code(400); throw new Exception('JSON inválido PUT.'); }
        $nome = trim($data['nome'] ?? ''); $telefone = trim($data['telefone'] ?? ''); $endereco = trim($data['endereco'] ?? '');
        if (!$nome || !$telefone || !$endereco) { http_response_code(400); throw new Exception('Dados obrigatórios faltando PUT.'); }

        $stmt = $conn->prepare("UPDATE clientes SET nome = ?, telefone = ?, endereco = ? WHERE id = ?");
        if (!$stmt) throw new Exception("Erro prepare PUT.");
        $stmt->bind_param("sssi", $nome, $telefone, $endereco, $cliente_id_logado);
        if ($stmt->execute()) {
            $_SESSION['cliente']['nome'] = $nome; $_SESSION['cliente']['telefone'] = $telefone; $_SESSION['cliente']['endereco'] = $endereco;
            echo json_encode(['status' => 'success', 'message' => 'Perfil atualizado!', 'data' => $_SESSION['cliente']]);
        } else { throw new mysqli_sql_exception("Execute PUT falhou."); } 

     } catch (mysqli_sql_exception $e) {
         error_log("CLIENTE_API PUT: Erro SQL: ".$e->getMessage());
         http_response_code(500); echo json_encode(['status' => 'error', 'message' => 'Erro ao atualizar.']);
     } catch (Exception $e) {
         http_response_code(isset($cliente_id_logado) ? 400 : 403); 
         echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
     } finally {
          if ($stmt instanceof mysqli_stmt) { $stmt->close(); }
     }
    exit();
}
// =============================================================
// MÉTODO NÃO PERMITIDO
// =============================================================
else {
    http_response_code(405); echo json_encode(['status' => 'error', 'message' => 'Método não permitido.']); exit();
}

if ($conn) { $conn->close(); }
?>
