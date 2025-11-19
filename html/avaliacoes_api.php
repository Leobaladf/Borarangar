<?php
session_start();
header("Content-Type: application/json; charset=UTF-8");

// 2. CONEXÃO COM O BANCO DE DADOS
$servername = "localhost";
$username = "borarangar_user";
$password = "Tredf1234----"; // Sua senha
$dbname = "borarangar_db";
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Erro de conexão."]);
    exit();
}
$conn->set_charset('utf8mb4');

$method = $_SERVER['REQUEST_METHOD'];

// ========================================================
// MÉTODO GET (Para o Lojista ler as avaliações)
// ========================================================
if ($method === 'GET') {
    
    // 1. VERIFICAR SE O LOJISTA ESTÁ LOGADO
    if (!isset($_SESSION['restaurante_id'])) {
        http_response_code(403); // Proibido
        echo json_encode(["status" => "error", "message" => "Acesso negado (Lojista)."]);
        exit();
    }
    $restaurante_id_logado = $_SESSION['restaurante_id'];

    // 2. BUSCAR AS AVALIAÇÕES NO BANCO
    try {
        // ========================================================
        // ### FILTROS DE DATA E RATING APLICADOS AQUI ###
        // ========================================================
        
        $sql = "SELECT a.id, a.rating, a.comentario, c.nome as cliente_nome, a.data_criacao 
                FROM avaliacoes a
                LEFT JOIN clientes c ON a.cliente_id = c.id
                WHERE a.restaurante_id = ?";
        
        $params = [$restaurante_id_logado];
        $types = "i";

        // Adiciona o filtro de rating SE ele for enviado
        if (!empty($_GET['rating']) && is_numeric($_GET['rating'])) {
            $sql .= " AND a.rating = ?";
            $params[] = $_GET['rating'];
            $types .= "i";
        }
        
        // Adiciona filtro de Data Inicial
        if (!empty($_GET['data_inicio'])) {
            $sql .= " AND DATE(a.data_criacao) >= ?";
            $params[] = $_GET['data_inicio'];
            $types .= "s";
        }
        
        // Adiciona filtro de Data Final
        if (!empty($_GET['data_fim'])) {
            $sql .= " AND DATE(a.data_criacao) <= ?";
            $params[] = $_GET['data_fim'];
            $types .= "s";
        }
        
        $sql .= " ORDER BY a.data_criacao DESC"; // MUDADO de a.id para a.data_criacao
        // ========================================================

        $stmt = $conn->prepare($sql);
        
        // Usa o spread operator (...) para ligar os parâmetros dinamicamente
        $stmt->bind_param($types, ...$params); 
        
        $stmt->execute();
        $result = $stmt->get_result();
        $avaliacoes = [];
        
        while($row = $result->fetch_assoc()) {
            $avaliacoes[] = $row;
        }
        
        echo json_encode($avaliacoes); // Retorna a lista de avaliações
        $stmt->close();

    } catch (Exception $e) {
        http_response_code(500);
        error_log("Erro GET avaliacoes_api: " . $e->getMessage()); 
        echo json_encode(["status" => "error", "message" => "Erro ao buscar avaliações."]);
    }
    
    $conn->close();
    exit();
}
// ========================================================
// MÉTODO POST (Para o Cliente criar)
// ========================================================
elseif ($method === 'POST') {
    
    // 1. VERIFICAR SE O CLIENTE ESTÁ LOGADO
    if (!isset($_SESSION['cliente']['id'])) {
        http_response_code(403); // Proibido
        echo json_encode(["status" => "error", "message" => "Acesso negado. Faça o login."]);
        exit();
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $cliente_id_logado = $_SESSION['cliente']['id'];

    $pedido_id = $data['pedido_id'] ?? null;
    $rating = $data['rating'] ?? null;
    $comentario = $data['comentario'] ?? null;

    if (empty($pedido_id) || empty($rating) || !is_numeric($rating) || $rating < 1 || $rating > 5) {
        http_response_code(400); // Bad Request
        echo json_encode(["status" => "error", "message" => "ID do pedido e nota (1-5) são obrigatórios."]);
        exit();
    }

    $conn->begin_transaction();
    try {
        // 4. VERIFICAÇÃO DE SEGURANÇA
        $stmt_check = $conn->prepare(
            "SELECT restaurante_id FROM pedidos 
             WHERE id = ? AND cliente_id = ? AND status = 'concluido'"
        );
        $stmt_check->bind_param("ii", $pedido_id, $cliente_id_logado);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();
        
        if ($result_check->num_rows === 0) {
            http_response_code(403);
            echo json_encode(["status" => "error", "message" => "Você não pode avaliar este pedido (não concluído ou não pertence a você)."]);
            $stmt_check->close();
            $conn->rollback();
            exit(); 
        }
        
        $restaurante_id = $result_check->fetch_assoc()['restaurante_id'];
        $stmt_check->close();
        
        // 5. INSERIR A AVALIAÇÃO
        // ========================================================
        // ### ATUALIZAÇÃO DA DATA APLICADA AQUI ###
        // Como o banco de dados foi alterado para ter DEFAULT CURRENT_TIMESTAMP,
        // não precisamos mais mudar a query de INSERT. O banco fará isso
        // automaticamente. A query original está correta.
        // ========================================================
        $stmt_insert = $conn->prepare(
            "INSERT INTO avaliacoes (pedido_id, restaurante_id, cliente_id, rating, comentario) 
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt_insert->bind_param("iiiis", $pedido_id, $restaurante_id, $cliente_id_logado, $rating, $comentario);
        
        if ($stmt_insert->execute()) {
            http_response_code(201); // Created
            echo json_encode(["status" => "success", "message" => "Avaliação enviada com sucesso!"]);
            $conn->commit();
        } else {
            throw new Exception("Erro ao inserir.");
        }
        $stmt_insert->close();

    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        if ($e->getCode() == 1062) {
            http_response_code(409); // Conflict
            echo json_encode(["status" => "error", "message" => "Este pedido já foi avaliado."]);
        } else {
            http_response_code(500);
             error_log("Erro SQL em avaliacoes_api: " . $e->getMessage());
            echo json_encode(["status" => "error", "message" => "Erro no banco de dados."]);
        }
    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(500);
        error_log("Erro Geral em avaliacoes_api: " . $e->getMessage());
        echo json_encode(["status" => "error", "message" => "Erro geral no servidor: " . $e->getMessage()]);
    }
    $conn->close();
    exit();

} 
// ========================================================
// MÉTODO NÃO PERMITIDO
// ========================================================
else {
    http_response_code(405); // Method Not Allowed
    echo json_encode(["status" => "error", "message" => "Método não permitido."]);
}

// Fechamento final (caso o método não seja GET ou POST)
$conn->close();
?>
