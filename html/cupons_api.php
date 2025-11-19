<?php
// /var/www/html/cupons_api.php

ini_set('log_errors', 1);
ini_set('error_log', '/var/log/apache2/error.log');
error_reporting(E_ALL);
ini_set('display_errors', 0); 

session_start();
header("Content-Type: application/json; charset=UTF-8");

// --- CONEXÃO COM O BANCO DE DADOS ---
$servername = "localhost"; $username = "borarangar_user"; $password = "Tredf1234----"; $dbname = "borarangar_db";
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    $conn->set_charset('utf8mb4');
} catch(mysqli_sql_exception $e) {
    error_log("CUPONS_API: Falha Conexão DB: " . $e->getMessage());
    http_response_code(500); echo json_encode(["status" => "error", "message" => "Erro interno (DB Connect)"]); exit();
}

// --- VERIFICAÇÃO DE SESSÃO E CARGOS ---
if (!isset($_SESSION['user_id']) || !isset($_SESSION['restaurante_id'])) {
    http_response_code(403); 
    echo json_encode(["status" => "error", "message" => "Acesso negado. Faça o login."]);
    exit();
}
$restaurante_id_logado = $_SESSION['restaurante_id'];
$role_logado = $_SESSION['user_role'] ?? 'funcionario';
$pode_gerenciar = in_array($role_logado, ['admin', 'gerente']);
if (!$pode_gerenciar) {
    http_response_code(403); 
    echo json_encode(["status" => "error", "message" => "Você não tem permissão para gerenciar cupons."]);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

// ===========================================
// MÉTODO GET - LISTAR CUPONS COM ESTATÍSTICAS
// ===========================================
if ($method === 'GET') {
    $stmt = null;
    try {
        // 1. Busca os cupons do restaurante
        $sql = "SELECT id, codigo, tipo, valor, data_validade, ativo 
                FROM cupons 
                WHERE restaurante_id = ? 
                ORDER BY data_validade ASC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $restaurante_id_logado);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $cupons = [];
        $mapaCodigoIndex = []; 
        
        while($row = $result->fetch_assoc()) {
            $row['qtd_usos'] = 0;
            $row['total_descontado'] = 0;
            $row['ativo'] = (bool)$row['ativo']; // Garante que seja booleano
            $cupons[] = $row;
            $mapaCodigoIndex[$row['codigo']] = count($cupons) - 1;
        }
        $stmt->close();

        // 2. Busca os pedidos para contar os usos
        $sql_pedidos = "SELECT detalhes_pedido FROM pedidos 
                        WHERE restaurante_id = ? 
                        AND status NOT IN ('cancelado', 'recusado') 
                        AND detalhes_pedido LIKE '%cupom_codigo%'";
                        
        $stmt_pedidos = $conn->prepare($sql_pedidos);
        $stmt_pedidos->bind_param("i", $restaurante_id_logado);
        $stmt_pedidos->execute();
        $result_pedidos = $stmt_pedidos->get_result();

        while($pedido = $result_pedidos->fetch_assoc()) {
            $detalhes = json_decode($pedido['detalhes_pedido'], true);
            if (isset($detalhes['cupom_codigo']) && isset($mapaCodigoIndex[$detalhes['cupom_codigo']])) {
                $index = $mapaCodigoIndex[$detalhes['cupom_codigo']];
                $cupons[$index]['qtd_usos']++;
                if (isset($detalhes['valor_desconto'])) {
                    $cupons[$index]['total_descontado'] += (float)$detalhes['valor_desconto'];
                }
            }
        }
        $stmt_pedidos->close();
        
        echo json_encode($cupons);

    } catch (mysqli_sql_exception $e) { /* ... (Erro GET) ... */ }
}

// ===========================================
// MÉTODO POST - CRIAR NOVO CUPOM
// ===========================================
elseif ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) { /* ... (Erro JSON) ... */ exit(); }
    $codigo = strtoupper(trim($data['codigo'] ?? ''));
    $tipo = $data['tipo'] ?? '';
    $valor = $data['valor'] ?? 0;
    $data_validade = $data['data_validade'] ?? '';
    if (empty($codigo) || !in_array($tipo, ['percent', 'fixed']) || !is_numeric($valor) || $valor <= 0 || empty($data_validade)) { /* ... (Erro Dados) ... */ exit(); }

    $stmt_insert = null;
    try {
        $stmt_insert = $conn->prepare("INSERT INTO cupons (restaurante_id, codigo, tipo, valor, data_validade, ativo) VALUES (?, ?, ?, ?, ?, TRUE)");
        $stmt_insert->bind_param("issds", $restaurante_id_logado, $codigo, $tipo, $valor, $data_validade);
        $stmt_insert->execute();
        http_response_code(201);
        echo json_encode(["status" => "success", "message" => "Cupom criado com sucesso!", "id" => $stmt_insert->insert_id]);
    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() == 1062) { /* ... (Erro Duplicado) ... */ } 
        else { /* ... (Erro DB) ... */ }
    } catch (Exception $e) { /* ... (Erro Geral) ... */ }
    finally { if ($stmt_insert) $stmt_insert->close(); }
}

// ===========================================
// ### NOVO MÉTODO PUT - ATUALIZAR UM CUPOM ###
// ===========================================
elseif ($method === 'PUT') {
    $id_cupom = $_GET['id'] ?? '';
    if (empty($id_cupom) || !is_numeric($id_cupom)) {
        http_response_code(400); echo json_encode(["status" => "error", "message" => "ID do cupom é obrigatório."]); exit();
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) { 
        http_response_code(400); echo json_encode(["status" => "error", "message" => "JSON inválido."]); exit(); 
    }

    $codigo = strtoupper(trim($data['codigo'] ?? ''));
    $tipo = $data['tipo'] ?? '';
    $valor = $data['valor'] ?? 0;
    $data_validade = $data['data_validade'] ?? '';
    $ativo = isset($data['ativo']) ? ($data['ativo'] ? 1 : 0) : 1; // Pega o status (true/false)

    if (empty($codigo) || !in_array($tipo, ['percent', 'fixed']) || !is_numeric($valor) || $valor <= 0 || empty($data_validade)) {
        http_response_code(400); 
        echo json_encode(["status" => "error", "message" => "Dados incompletos ou inválidos."]); 
        exit();
    }
    
    $stmt_update = null;
    try {
        $stmt_update = $conn->prepare("UPDATE cupons SET codigo = ?, tipo = ?, valor = ?, data_validade = ?, ativo = ? WHERE id = ? AND restaurante_id = ?");
        $stmt_update->bind_param("ssdsiii", $codigo, $tipo, $valor, $data_validade, $ativo, $id_cupom, $restaurante_id_logado);
        $stmt_update->execute();
        
        if ($stmt_update->affected_rows > 0) {
            echo json_encode(["status" => "success", "message" => "Cupom atualizado com sucesso!"]);
        } else {
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "Nenhuma alteração ou cupom não encontrado."]);
        }

    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() == 1062) { // Erro de Chave Duplicada
             http_response_code(409); // Conflict
             echo json_encode(["status" => "error", "message" => "Erro: Você já possui outro cupom com este código."]);
        } else {
            error_log("CUPONS_API PUT Erro: " . $e->getMessage());
            http_response_code(500); echo json_encode(["status" => "error", "message" => "Erro de banco de dados."]);
        }
    } finally {
        if ($stmt_update) $stmt_update->close();
    }
}

// ===========================================
// MÉTODO DELETE - EXCLUIR UM CUPOM
// ===========================================
elseif ($method === 'DELETE') {
    $id_cupom = $_GET['id'] ?? '';
    if (empty($id_cupom) || !is_numeric($id_cupom)) { /* ... (Erro ID) ... */ exit(); }
    $stmt = null;
    try {
        $stmt = $conn->prepare("DELETE FROM cupons WHERE id = ? AND restaurante_id = ?");
        $stmt->bind_param("ii", $id_cupom, $restaurante_id_logado);
        $stmt->execute();
        if ($stmt->affected_rows > 0) { /* ... (Sucesso) ... */ } 
        else { /* ... (Erro 404) ... */ }
    } catch (mysqli_sql_exception $e) { /* ... (Erro DB) ... */ } 
    finally { if ($stmt) $stmt->close(); }
}

else {
    http_response_code(405); 
    echo json_encode(["status" => "error", "message" => "Método não permitido."]);
}

$conn->close();
?>
