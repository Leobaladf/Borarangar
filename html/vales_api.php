<?php
// /var/www/html/vales_api.php

// ATIVAR LOGS (ajuste o caminho se necessário)
ini_set('log_errors', 1);
ini_set('error_log', '/var/log/apache2/error.log');
error_reporting(E_ALL);
ini_set('display_errors', 0); // Não mostrar erros para o usuário

session_start();
header("Content-Type: application/json; charset=UTF-8");

// --- CONEXÃO COM O BANCO DE DADOS ---
$servername = "localhost"; $username = "borarangar_user"; $password = "Tredf1234----"; $dbname = "borarangar_db";
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    $conn->set_charset('utf8mb4');
} catch(mysqli_sql_exception $e) {
    error_log("VALES_API: Falha CRÍTICA na conexão DB: " . $e->getMessage());
    http_response_code(500); echo json_encode(["status" => "error", "message" => "Erro interno (DB Connect)"]); exit();
}

// --- VERIFICAÇÃO DE SESSÃO E CARGOS ---
if (!isset($_SESSION['user_id']) || !isset($_SESSION['restaurante_id'])) {
    http_response_code(403); // Proibido
    echo json_encode(["status" => "error", "message" => "Acesso negado. Faça o login."]);
    exit();
}
$usuario_id_logado = $_SESSION['user_id'];
$restaurante_id_logado = $_SESSION['restaurante_id'];
$role_logado = $_SESSION['user_role'] ?? 'funcionario'; // Padrão é o mais restrito

// Define quem pode gerenciar (admin logado no restaurante, ou gerente)
$pode_gerenciar = in_array($role_logado, ['admin', 'gerente']);

$method = $_SERVER['REQUEST_METHOD'];

// ===========================================
// MÉTODO GET - LISTAR VALES
// ===========================================
if ($method === 'GET') {
    $stmt = null;
    try {
        $sql = "SELECT v.id, v.usuario_id, u.nome as usuario_nome, v.valor, v.descricao, v.mes_referencia, v.data_lancamento
                FROM vales_adiantamentos v
                JOIN usuarios u ON v.usuario_id = u.id
                WHERE v.restaurante_id = ?"; // Filtra pelo restaurante sempre

        $params = [$restaurante_id_logado];
        $types = "i";

        // Se NÃO PODE gerenciar, força a busca apenas para o usuário logado
        if (!$pode_gerenciar) {
            $sql .= " AND v.usuario_id = ?";
            $params[] = $usuario_id_logado;
            $types .= "i";
        } 
        // Se PODE gerenciar, opcionalmente filtra por um funcionário
        elseif (!empty($_GET['usuario_id']) && is_numeric($_GET['usuario_id'])) {
            $sql .= " AND v.usuario_id = ?";
            $params[] = $_GET['usuario_id'];
            $types .= "i";
        }
        
        // Opcionalmente filtra por mês/ano (Ex: 2025-11-01)
        if (!empty($_GET['mes_referencia'])) {
            $sql .= " AND v.mes_referencia = ?";
            $params[] = $_GET['mes_referencia'];
            $types .= "s";
        }

        $sql .= " ORDER BY v.mes_referencia DESC, v.data_lancamento DESC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $vales = [];
        while($row = $result->fetch_assoc()) {
            $vales[] = $row;
        }
        
        echo json_encode($vales);

    } catch (mysqli_sql_exception $e) {
        error_log("VALES_API GET Erro: " . $e->getMessage());
        http_response_code(500); echo json_encode(["status" => "error", "message" => "Erro ao buscar vales."]);
    } finally {
        if ($stmt) $stmt->close();
    }
}

// ===========================================
// MÉTODO POST - LANÇAR NOVO VALE
// ===========================================
elseif ($method === 'POST') {
    // Apenas quem pode gerenciar pode lançar
    if (!$pode_gerenciar) {
        http_response_code(403); echo json_encode(["status" => "error", "message" => "Você não tem permissão para lançar vales."]); exit();
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) { http_response_code(400); echo json_encode(["status" => "error", "message" => "JSON inválido."]); exit(); }

    $usuario_id_alvo = $data['usuario_id'] ?? null; // ID do funcionário que recebe
    $valor = $data['valor'] ?? null;
    $descricao = $data['descricao'] ?? '';
    $mes_referencia = $data['mes_referencia'] ?? ''; // Espera 'YYYY-MM-01'

    if (empty($usuario_id_alvo) || !is_numeric($usuario_id_alvo) || empty($valor) || !is_numeric($valor) || trim($descricao) === '' || empty($mes_referencia)) {
        http_response_code(400); echo json_encode(["status" => "error", "message" => "Dados incompletos (funcionário, valor, descrição e mês são obrigatórios)."]); exit();
    }
    
    $conn->begin_transaction();
    $stmt_check = null;
    $stmt_insert = null;
    try {
        // --- CHECAGEM DE SEGURANÇA CRÍTICA ---
        $stmt_check = $conn->prepare("SELECT id FROM usuarios WHERE id = ? AND restaurante_id = ?");
        $stmt_check->bind_param("ii", $usuario_id_alvo, $restaurante_id_logado);
        $stmt_check->execute();
        if ($stmt_check->get_result()->num_rows === 0) {
            http_response_code(403); // Proibido
            throw new Exception("Este funcionário não pertence ao seu restaurante.");
        }
        $stmt_check->close();

        // --- INSERÇÃO ---
        $stmt_insert = $conn->prepare("INSERT INTO vales_adiantamentos (restaurante_id, usuario_id, valor, descricao, mes_referencia) VALUES (?, ?, ?, ?, ?)");
        
        // *** CORREÇÃO CRÍTICA APLICADA AQUI (LINHA 132) ***
        // Corrigido de "iids" para "iidss" (i-int, i-int, d-double, s-string, s-string)
        $stmt_insert->bind_param("iidss", $restaurante_id_logado, $usuario_id_alvo, $valor, $descricao, $mes_referencia);
        $stmt_insert->execute();
        
        $conn->commit();
        http_response_code(201); // Created
        echo json_encode(["status" => "success", "message" => "Vale lançado com sucesso!", "id" => $stmt_insert->insert_id]);

    } catch (Exception $e) {
        $conn->rollback();
        error_log("VALES_API POST Erro: " . $e->getMessage());
        if (http_response_code() === 200) {
            http_response_code(500);
        }
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    } finally {
        if (isset($stmt_check) && $stmt_check instanceof mysqli_stmt) $stmt_check->close();
        if (isset($stmt_insert) && $stmt_insert instanceof mysqli_stmt) $stmt_insert->close();
    }
}

// ===========================================
// MÉTODO DELETE - EXCLUIR UM VALE
// ===========================================
elseif ($method === 'DELETE') {
    // Apenas quem pode gerenciar pode excluir
    if (!$pode_gerenciar) {
        http_response_code(403); echo json_encode(["status" => "error", "message" => "Você não tem permissão para excluir vales."]); exit();
    }
    
    $id_vale = $_GET['id'] ?? '';
    if (empty($id_vale) || !is_numeric($id_vale)) {
        http_response_code(400); echo json_encode(["status" => "error", "message" => "ID do vale é obrigatório."]); exit();
    }
    
    $stmt = null;
    try {
        // A checagem de segurança é feita no WHERE
        $stmt = $conn->prepare("DELETE FROM vales_adiantamentos WHERE id = ? AND restaurante_id = ?");
        $stmt->bind_param("ii", $id_vale, $restaurante_id_logado);
        $stmt->execute();

        if ($stmt->affected_rows > 0) {
            echo json_encode(["status" => "success", "message" => "Lançamento excluído!"]);
        } else {
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "Lançamento não encontrado ou não pertence ao seu restaurante."]);
        }
    } catch (mysqli_sql_exception $e) {
        error_log("VALES_API DELETE Erro: " . $e->getMessage());
        http_response_code(500); echo json_encode(["status" => "error", "message" => "Erro de banco de dados ao excluir."]);
    } finally {
        if ($stmt) $stmt->close();
    }
}

// ===========================================
// MÉTODO NÃO PERMITIDO
// ===========================================
else {
    http_response_code(405); // Method Not Allowed
    echo json_encode(["status" => "error", "message" => "Método não permitido."]);
}

$conn->close();
?>
