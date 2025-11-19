<?php
// /var/www/html/horarios_api.php

session_start();
header("Content-Type: application/json; charset=UTF-8");

// --- CONEXÃO COM O BANCO DE DADOS ---
// !! LEMBRETE DE SEGURANÇA: Mover para config.php !!
$servername = "localhost"; $username = "borarangar_user"; $password = "Tredf1234----"; $dbname = "borarangar_db";
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); 
try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    $conn->set_charset('utf8mb4');
} catch(mysqli_sql_exception $e) {
    error_log("HORARIOS_API: Falha Conexão DB: " . $e->getMessage());
    http_response_code(500); echo json_encode(["status" => "error", "message" => "Erro interno (DB Connect)"]); exit();
}

// --- LÓGICA PARA DETERMINAR O RESTAURANTE_ID ---
$restaurante_id_param = filter_input(INPUT_GET, 'restaurante_id', FILTER_VALIDATE_INT);
$restaurante_id_sessao = $_SESSION['restaurante_id'] ?? null;
$restaurante_id_operacao = $restaurante_id_param ?: $restaurante_id_sessao; // Prioriza ID da URL

// Se for operação de escrita (POST, PUT, DELETE), exige sessão
if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'DELETE'])) {
    if (!$restaurante_id_sessao) {
        http_response_code(403); echo json_encode(["status" => "error", "message" => "Acesso negado."]); exit();
    }
    // Garante que a operação seja feita no restaurante da sessão
    $restaurante_id_operacao = $restaurante_id_sessao; 
} 
// Se for GET e nenhum ID foi fornecido (nem URL nem sessão)
elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && !$restaurante_id_operacao) {
     http_response_code(400); echo json_encode(["status" => "error", "message" => "ID do restaurante não fornecido."]); exit();
}

// --- ROTEADOR ---
$method = $_SERVER['REQUEST_METHOD'];

// ===========================================
// MÉTODO GET - LISTAR HORÁRIOS E VERIFICAR STATUS
// ===========================================
if ($method === 'GET') {
    $stmt = null;
    $horarios = []; 
    $esta_aberto_agora = false; 

    try {
        $sql = "SELECT id, dia_semana, abertura, fechamento, aberto 
                FROM horarios_funcionamento 
                WHERE restaurante_id = ? 
                ORDER BY dia_semana, abertura"; 
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $restaurante_id_operacao); 
        $stmt->execute();
        $result = $stmt->get_result();
        
        date_default_timezone_set('America/Sao_Paulo'); // Ajuste se necessário
        $agora = new DateTime();
        $diaSemanaAtual = (int)$agora->format('w'); 
        $horaAtual = $agora->format('H:i:s');

        while($row = $result->fetch_assoc()) {
            $aberto_bool = (bool)$row['aberto']; 
            
            if ($aberto_bool && (int)$row['dia_semana'] === $diaSemanaAtual) {
                 $abertura = $row['abertura'];
                 $fechamento = $row['fechamento'];
                 if ($fechamento < $abertura) { // Vira a noite
                      if ($horaAtual >= $abertura || $horaAtual < $fechamento) { $esta_aberto_agora = true; }
                 } else { // Mesmo dia
                      if ($horaAtual >= $abertura && $horaAtual < $fechamento) { $esta_aberto_agora = true; }
                 }
            }

            $row['aberto'] = $aberto_bool; 
            $row['abertura'] = substr($row['abertura'], 0, 5);
            $row['fechamento'] = substr($row['fechamento'], 0, 5);
            $horarios[] = $row;
        }

        echo json_encode([
            "horarios" => $horarios,
            "esta_aberto" => $esta_aberto_agora 
        ]);
        exit(); // Garante que pare aqui

    } catch (mysqli_sql_exception | Exception $e) { 
        error_log("HORARIOS_API GET: Erro: ".$e->getMessage()); 
        http_response_code(500); echo json_encode(['status' => 'error', 'message' => 'Erro ao buscar horários.']);
        exit(); 
    } finally { 
        if ($stmt instanceof mysqli_stmt) { $stmt->close(); } 
    }
}
// ===========================================
// MÉTODO POST - ADICIONAR NOVO HORÁRIO
// ===========================================
elseif ($method === 'POST') {
    // Garante que a operação seja feita no restaurante da sessão
    if (!isset($_SESSION['restaurante_id'])) { http_response_code(403); exit(); }
    $restaurante_id_logado = $_SESSION['restaurante_id'];

    $data = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) { http_response_code(400); echo json_encode(["status" => "error", "message" => "JSON inválido."]); exit(); }

    $dia_semana = filter_var($data['dia_semana'] ?? null, FILTER_VALIDATE_INT, ["options" => ["min_range" => 0, "max_range" => 6]]);
    $abertura = $data['abertura'] ?? ''; // Espera formato HH:MM
    $fechamento = $data['fechamento'] ?? ''; // Espera formato HH:MM
    $aberto = isset($data['aberto']) ? ($data['aberto'] ? 1 : 0) : 1; 

    if ($dia_semana === false || !preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $abertura) || !preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $fechamento)) {
        http_response_code(400); echo json_encode(["status" => "error", "message" => "Dados inválidos."]); exit();
    }
    
    $abertura_db = $abertura . ':00';
    $fechamento_db = $fechamento . ':00';
    
    $stmt = null;
    try {
        $sql = "INSERT INTO horarios_funcionamento (restaurante_id, dia_semana, abertura, fechamento, aberto) VALUES (?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iissi", $restaurante_id_logado, $dia_semana, $abertura_db, $fechamento_db, $aberto);
        $stmt->execute();
        
        http_response_code(201); 
        echo json_encode(["status" => "success", "message" => "Horário adicionado!", "id" => $stmt->insert_id]);

    } catch (mysqli_sql_exception | Exception $e) { /* ... (Erro POST) ... */ } 
      finally { if ($stmt instanceof mysqli_stmt) { $stmt->close(); } }
    exit(); // Sai após POST
}
// ===========================================
// MÉTODO PUT - ATUALIZAR UM HORÁRIO 
// ===========================================
elseif ($method === 'PUT') {
    // Garante que a operação seja feita no restaurante da sessão
    if (!isset($_SESSION['restaurante_id'])) { http_response_code(403); exit(); }
    $restaurante_id_logado = $_SESSION['restaurante_id'];

    $id_horario = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$id_horario) { http_response_code(400); echo json_encode(["status" => "error", "message" => "ID do horário é obrigatório."]); exit(); }
    
    $data = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) { http_response_code(400); echo json_encode(["status" => "error", "message" => "JSON inválido."]); exit(); }

    $dia_semana = filter_var($data['dia_semana'] ?? null, FILTER_VALIDATE_INT, ["options" => ["min_range" => 0, "max_range" => 6]]);
    $abertura = $data['abertura'] ?? ''; 
    $fechamento = $data['fechamento'] ?? ''; 
    $aberto_input = $data['aberto'] ?? null; 
    $aberto_db = ($aberto_input === null) ? null : ($aberto_input ? 1 : 0); 

    $fields = []; $params = []; $types = "";
    if ($dia_semana !== false && $dia_semana !== null) { $fields[] = "dia_semana = ?"; $params[] = $dia_semana; $types .= "i"; }
    if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $abertura)) { $fields[] = "abertura = ?"; $params[] = $abertura.':00'; $types .= "s"; }
    if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $fechamento)) { $fields[] = "fechamento = ?"; $params[] = $fechamento.':00'; $types .= "s"; }
    if ($aberto_db !== null) { $fields[] = "aberto = ?"; $params[] = $aberto_db; $types .= "i"; }

    if (empty($fields)) { http_response_code(400); echo json_encode(["status" => "error", "message" => "Nenhum dado válido para atualizar."]); exit(); }

    $sql = "UPDATE horarios_funcionamento SET " . implode(", ", $fields) . " WHERE id = ? AND restaurante_id = ?";
    $params[] = $id_horario; $types .= "i";
    $params[] = $restaurante_id_logado; $types .= "i"; // Usa ID da sessão para segurança

    $stmt = null;
    try {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        if ($stmt->affected_rows > 0) {
            echo json_encode(["status" => "success", "message" => "Horário atualizado!"]);
        } else { /* ... (Verifica se existe ou se não mudou) ... */ }

    } catch (mysqli_sql_exception | Exception $e) { /* ... (Erro PUT) ... */ } 
      finally { if ($stmt instanceof mysqli_stmt) { $stmt->close(); } }
    exit(); // Sai após PUT
}
// ===========================================
// MÉTODO DELETE - REMOVER UM HORÁRIO
// ===========================================
elseif ($method === 'DELETE') {
    // Garante que a operação seja feita no restaurante da sessão
    if (!isset($_SESSION['restaurante_id'])) { http_response_code(403); exit(); }
    $restaurante_id_logado = $_SESSION['restaurante_id'];

    $id_horario = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$id_horario) { http_response_code(400); echo json_encode(["status" => "error", "message" => "ID do horário é obrigatório."]); exit(); }

    $stmt = null;
    try {
        $sql = "DELETE FROM horarios_funcionamento WHERE id = ? AND restaurante_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $id_horario, $restaurante_id_logado); // Usa ID da sessão
        $stmt->execute();

        if ($stmt->affected_rows > 0) {
            echo json_encode(["status" => "success", "message" => "Horário removido!"]);
        } else { /* ... (Erro 404) ... */ }
    } catch (mysqli_sql_exception | Exception $e) { /* ... (Erro DELETE) ... */ } 
      finally { if ($stmt instanceof mysqli_stmt) { $stmt->close(); } }
    exit(); // Sai após DELETE
}
// ===========================================
// MÉTODO NÃO PERMITIDO
// ===========================================
else {
    http_response_code(405); 
    echo json_encode(["status" => "error", "message" => "Método não permitido."]);
    exit();
}

$conn->close();
?>
