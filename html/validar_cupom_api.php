<?php
// /var/www/html/validar_cupom_api.php

ini_set('log_errors', 1);
ini_set('error_log', '/var/log/apache2/error.log');
error_reporting(E_ALL);
ini_set('display_errors', 0); 

// API PÚBLICA, NÃO PRECISA DE SESSÃO
header("Content-Type: application/json; charset=UTF-8");

// --- CONEXÃO COM O BANCO DE DADOS ---
$servername = "localhost"; $username = "borarangar_user"; $password = "Tredf1234----"; $dbname = "borarangar_db";
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    $conn->set_charset('utf8mb4');
} catch(mysqli_sql_exception $e) {
    error_log("VALIDAR_CUPOM_API: Falha Conexão DB: " . $e->getMessage());
    http_response_code(500); echo json_encode(["status" => "error", "message" => "Erro interno (DB Connect)"]); exit();
}

$method = $_SERVER['REQUEST_METHOD'];

// ===========================================
// MÉTODO POST - VALIDAR CUPOM
// ===========================================
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) { 
        http_response_code(400); echo json_encode(["status" => "error", "message" => "JSON inválido."]); exit(); 
    }

    $codigo = strtoupper(trim($data['codigo'] ?? ''));
    $restaurante_id = filter_var($data['restaurante_id'] ?? null, FILTER_VALIDATE_INT);

    if (empty($codigo) || empty($restaurante_id)) {
        http_response_code(400); 
        echo json_encode(["status" => "error", "message" => "Código do cupom e ID do restaurante são obrigatórios."]); 
        exit();
    }
    
    $stmt = null;
    try {
        // Pega a data de HOJE no fuso horário correto (IMPORTANTE)
        date_default_timezone_set('America/Sao_Paulo'); // Ajuste se seu servidor estiver em outro fuso
        $hoje = date('Y-m-d');

        $sql = "SELECT id, codigo, tipo, valor, data_validade 
                FROM cupons 
                WHERE restaurante_id = ? 
                  AND codigo = ? 
                  AND ativo = TRUE";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("is", $restaurante_id, $codigo);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            http_response_code(404); // Not Found
            echo json_encode(["status" => "error", "message" => "Cupom inválido ou não encontrado."]);
            exit();
        }
        
        $cupom = $result->fetch_assoc();
        
        // Checagem final: O cupom já venceu?
        if ($cupom['data_validade'] < $hoje) {
            http_response_code(410); // Gone (Expirado)
            echo json_encode(["status" => "error", "message" => "Este cupom expirou em " . date("d/m/Y", strtotime($cupom['data_validade'])) . "."]);
            exit();
        }
        
        // Se chegou aqui, o cupom é VÁLIDO!
        echo json_encode([
            "status" => "success",
            "codigo" => $cupom['codigo'],
            "tipo" => $cupom['tipo'],
            "valor" => (float)$cupom['valor']
        ]);

    } catch (mysqli_sql_exception $e) {
        error_log("VALIDAR_CUPOM_API POST Erro: " . $e->getMessage());
        http_response_code(500); echo json_encode(["status" => "error", "message" => "Erro ao validar o cupom."]);
    } finally {
        if ($stmt) $stmt->close();
        $conn->close();
    }
}
// ===========================================
// MÉTODO NÃO PERMITIDO
// ===========================================
else {
    http_response_code(405); // Method Not Allowed
    echo json_encode(["status" => "error", "message" => "Método não permitido."]);
}
?>
