<?php
// ATIVAR LOGS DETALHADOS (manter ativo por enquanto)
ini_set('log_errors', 1);
ini_set('error_log', '/var/log/apache2/error.log'); // Ajuste se o log do Apache estiver em outro lugar
error_reporting(E_ALL);
ini_set('display_errors', 0); // NÃO mostra erros para o usuário

session_start();
header("Content-Type: application/json; charset=UTF-8");
error_log("--- PEDIDOS_API (v4 - Cupom Fix): Requisição recebida --- Metodo: ".$_SERVER['REQUEST_METHOD']);

// --- CONEXÃO COM O BANCO DE DADOS ---
$servername = "localhost"; $username = "borarangar_user"; $password = "Tredf1234----"; $dbname = "borarangar_db";
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    error_log("Falha na conexão DB: " . $conn->connect_error);
    http_response_code(500); echo json_encode(["status" => "error", "message" => "Erro interno (DB Connect)"]); exit();
}
$conn->set_charset('utf8mb4'); // Garante UTF-8
error_log("Conexão DB OK.");

$method = $_SERVER['REQUEST_METHOD'];

// =============================================================
// MÉTODO GET - BUSCA DE PEDIDOS (v3 - ATUALIZADO COM TAXA)
// =============================================================
if ($method === 'GET') {
    error_log("Iniciando processamento GET.");

    // Cenário 1: Cliente buscando UM pedido (Rastreador)
    if (isset($_GET['id'])) {
        // ... (código original sem alteração) ...
        $id_pedido = filter_var($_GET['id'], FILTER_VALIDATE_INT);
        if (!$id_pedido) { http_response_code(400); echo json_encode(["status" => "error", "message" => "ID inválido."]); exit(); }
        $stmt = $conn->prepare("SELECT id, status, restaurante_id FROM pedidos WHERE id = ?"); 
        if (!$stmt) { http_response_code(500); exit(); }
        $stmt->bind_param("i", $id_pedido);
        if (!$stmt->execute()) { http_response_code(500); exit(); }
        $result = $stmt->get_result();
        if ($result->num_rows > 0) { echo json_encode($result->fetch_assoc()); }
        else { http_response_code(404); echo json_encode(["status" => "error", "message" => "Pedido não encontrado."]); }
        $stmt->close();
    }
    // Cenário 2: Lojista LOGADO buscando lista/histórico (ATUALIZADO)
    elseif (isset($_SESSION['user_id']) && isset($_SESSION['restaurante_id'])) {
        $restaurante_id_logado = $_SESSION['restaurante_id'];
        $role_logado = $_SESSION['user_role'] ?? 'funcionario'; 
        
        error_log("GET: Sessão LOJISTA encontrada. Restaurante ID: " . $restaurante_id_logado);
        $status = $_GET['status'] ?? 'ativos'; 
        $data_inicio = $_GET['data_inicio'] ?? null; 
        $data_fim = $_GET['data_fim'] ?? null;
        $pagamento_status_filtro = $_GET['pagamento_status'] ?? null;
        $entregador_id_filtro = $_GET['entregador_id'] ?? null;

        $sql = "SELECT p.id, p.status, p.total, p.detalhes_pedido, p.created_at, p.updated_at, a.rating,
                       p.entregador_id, u.nome as entregador_nome, p.pagamento_entrega_status
                FROM pedidos p 
                LEFT JOIN avaliacoes a ON p.id = a.pedido_id
                LEFT JOIN usuarios u ON p.entregador_id = u.id 
                WHERE p.restaurante_id = ?";
                
        $params = [$restaurante_id_logado]; $types = "i";
        
        if ($status === 'ativos') { $sql .= " AND p.status NOT IN ('concluido', 'cancelado', 'recusado')"; }
        elseif ($status !== 'todos') { $sql .= " AND p.status = ?"; $params[] = $status; $types .= "s"; }
        
        if ($data_inicio) { $sql .= " AND DATE(p.created_at) >= ?"; $params[] = $data_inicio; $types .= "s"; }
        if ($data_fim) { $sql .= " AND DATE(p.created_at) <= ?"; $params[] = $data_fim; $types .= "s"; }
        
        if ($pagamento_status_filtro === 'pendente' || $pagamento_status_filtro === 'pago') {
             $sql .= " AND p.pagamento_entrega_status = ?";
             $params[] = $pagamento_status_filtro; $types .= "s";
             $sql .= " AND p.entregador_id IS NOT NULL";
        }
        
        if ($role_logado === 'entregador') {
            $sql .= " AND p.entregador_id = ?";
            $params[] = $_SESSION['user_id']; 
            $types .= "i";
        }
        elseif ($entregador_id_filtro && is_numeric($entregador_id_filtro)) {
             $sql .= " AND p.entregador_id = ?";
             $params[] = $entregador_id_filtro; $types .= "i";
        }
        
        $sql .= " ORDER BY p.created_at DESC";

        $stmt = $conn->prepare($sql);
        if (!$stmt) { error_log("Erro PREPARE GET Lojista: " . $conn->error); http_response_code(500); exit(); } 
        
        $stmt->bind_param($types, ...$params); 
        
        if(!$stmt->execute()) { error_log("Erro EXECUTE GET Lojista: " . $stmt->error); http_response_code(500); exit(); } 
        
        $result = $stmt->get_result();
        $pedidos = [];
        while($row = $result->fetch_assoc()) {
            $decoded_details = json_decode($row['detalhes_pedido'], true);
            if (json_last_error() === JSON_ERROR_NONE) { $row['detalhes_pedido'] = $decoded_details; }
            else { $row['detalhes_pedido'] = ['itens' => [], 'cliente' => ['nome' => 'Erro Dados']]; } 

            try { $row['created_at'] = isset($row['created_at']) ? (new DateTime($row['created_at']))->format(DateTime::ATOM) : null; } catch (Exception $e) { $row['created_at'] = null; }
            try { $row['updated_at'] = isset($row['updated_at']) ? (new DateTime($row['updated_at']))->format(DateTime::ATOM) : null; } catch (Exception $e) { $row['updated_at'] = null; }
            
            $row['rating'] = isset($row['rating']) ? (int)$row['rating'] : null;
            $row['total'] = (float)$row['total']; 
            
            $row['valor_entrega'] = (float)($decoded_details['taxa_entrega'] ?? 0);
            
            $row['entregador_id'] = $row['entregador_id'] ? (int)$row['entregador_id'] : null;
            $row['entregador_nome'] = $row['entregador_nome'] ?? null;
            $row['pagamento_entrega_status'] = $row['pagamento_entrega_status'] ?? 'pendente';

            $pedidos[] = $row;
        }
        $stmt->close();
        echo json_encode($pedidos); 
    }
    
    // Cenário 3: Cliente LOGADO buscando seu histórico de pedidos
    elseif (isset($_SESSION['cliente']['id'])) {
        // ... (código original do Cenário 3 sem alterações) ...
        $cliente_id_logado = $_SESSION['cliente']['id'];
        if (!isset($_SESSION['cliente']['restaurante_id'])) { http_response_code(403); exit(); }
        $restaurante_id_logado = $_SESSION['cliente']['restaurante_id'];
        $pedidos = []; $stmt = null;
        try {
            $sql = "SELECT p.id, p.status, p.total, p.detalhes_pedido, p.created_at, a.rating, r.nome as nome_restaurante
                    FROM pedidos p 
                    LEFT JOIN avaliacoes a ON p.id = a.pedido_id
                    JOIN restaurantes r ON p.restaurante_id = r.id
                    WHERE p.cliente_id = ? AND p.restaurante_id = ? 
                    ORDER BY p.created_at DESC LIMIT 20";
            $stmt = $conn->prepare($sql);
            if (!$stmt) { throw new Exception($conn->error); }
            $stmt->bind_param("ii", $cliente_id_logado, $restaurante_id_logado);
            if (!$stmt->execute()) { throw new Exception($stmt->error); }
            $result = $stmt->get_result();
            while($row = $result->fetch_assoc()) {
                $decoded_details = json_decode($row['detalhes_pedido'], true);
                if (json_last_error() === JSON_ERROR_NONE) { $row['detalhes_pedido'] = $decoded_details; } 
                else { $row['detalhes_pedido'] = ['itens' => [], 'cliente' => ['nome' => 'Erro Dados']]; }
                try { $row['created_at'] = isset($row['created_at']) ? (new DateTime($row['created_at']))->format(DateTime::ATOM) : null; } catch (Exception $e) { $row['created_at'] = null; }
                $row['rating'] = isset($row['rating']) ? (int)$row['rating'] : null;
                $row['total'] = (float)$row['total']; 
                $pedidos[] = $row;
            }
            echo json_encode($pedidos); 
        } catch (mysqli_sql_exception | Exception $e) { http_response_code(500); } 
        finally { if ($stmt instanceof mysqli_stmt) { $stmt->close(); } }
    }
    // Se não é nenhum dos cenários autorizados
    else {
        http_response_code(403); echo json_encode(["status" => "error", "message" => "Acesso negado GET."]); exit(); 
    }
// =============================================================
// MÉTODO POST - CRIAR PEDIDO (COM CORREÇÃO DO CUPOM)
// =============================================================
} elseif ($method === 'POST') {
    // 1. Pega os dados
    $data = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) { http_response_code(400); exit(); }
    $restaurante_id = $data['restaurante_id'] ?? null; $carrinho = $data['carrinho'] ?? null; $cliente_info = $data['cliente'] ?? ['nome' => 'Cliente Balcão'];
    $cliente_id = $_SESSION['cliente']['id'] ?? ($data['cliente_id'] ?? null); $tipo_entrega = $data['tipo_entrega'] ?? 'delivery'; 
    
    // --- PEGA O CUPOM ENVIADO PELO CLIENTE ---
    $cupom_codigo_cliente = $data['cupom_codigo'] ?? null;
    
    if (empty($restaurante_id) || empty($carrinho) || !is_array($carrinho)) { http_response_code(400); exit(); }

    // 2. Valida o preço dos itens no banco
    $total_itens = 0; $ids_itens = [];
    foreach ($carrinho as $item) { if (isset($item['id']) && is_numeric($item['id'])) { $ids_itens[] = (int)$item['id']; } }
    
    $precos_reais = []; $pedido_minimo_restaurante = 0.00; $taxa_embalagem_restaurante = 0.00; 
    
    if (count($ids_itens) > 0) {
        // Pega config (pedido minimo, taxa embalagem)
        $stmt_config = $conn->prepare("SELECT pedido_minimo, taxa_embalagem FROM restaurantes WHERE id = ?");
        $stmt_config->bind_param("i", $restaurante_id); $stmt_config->execute(); $result_config = $stmt_config->get_result();
        if ($result_config->num_rows > 0) { $config_data = $result_config->fetch_assoc(); $pedido_minimo_restaurante = (float)$config_data['pedido_minimo']; $taxa_embalagem_restaurante = (float)$config_data['taxa_embalagem']; }
        $stmt_config->close();
        
        // Pega preços reais dos itens
        $id_list_string = implode(',', $ids_itens); $sql_precos = "SELECT id, preco FROM cardapio_itens WHERE id IN ($id_list_string) AND restaurante_id = ?";
        $stmt_precos = $conn->prepare($sql_precos); $stmt_precos->bind_param("i", $restaurante_id); $stmt_precos->execute(); $result_precos = $stmt_precos->get_result();
        while ($row = $result_precos->fetch_assoc()) { $precos_reais[$row['id']] = (float)$row['preco']; } $stmt_precos->close();
    }

    // 3. Calcula o total dos ITENS (Subtotal)
    foreach ($carrinho as $item) {
        $item_id = $item['id'] ?? null; $item_qtd = $item['quantidade'] ?? 0;
        if ($item_id && $item_qtd > 0 && isset($precos_reais[$item_id])) { $total_itens += $precos_reais[$item_id] * (int)$item_qtd; } 
        else { http_response_code(400); exit(); }
    }

    // 4. Checa pedido mínimo
    if ($tipo_entrega === 'delivery' && $pedido_minimo_restaurante > 0 && $total_itens < $pedido_minimo_restaurante) {
        http_response_code(400); echo json_encode(["status" => "error", "message" => "O valor mínimo para delivery é de R$ " . number_format($pedido_minimo_restaurante, 2, ',', '.')]); exit();
    }
    
    // =========================================================
    // ### INÍCIO DA CORREÇÃO (VALIDAÇÃO DO CUPOM NO BACKEND) ###
    // =========================================================
    $valor_desconto = 0;
    if (!empty($cupom_codigo_cliente)) {
        
        date_default_timezone_set('America/Sao_Paulo'); 
        $hoje = date('Y-m-d');

        // Valida o cupom DE NOVO no servidor
        $stmt_cupom = $conn->prepare("SELECT tipo, valor FROM cupons WHERE restaurante_id = ? AND codigo = ? AND ativo = TRUE AND data_validade >= ?");
        $stmt_cupom->bind_param("iss", $restaurante_id, $cupom_codigo_cliente, $hoje);
        $stmt_cupom->execute();
        $result_cupom = $stmt_cupom->get_result();
        
        if ($result_cupom->num_rows > 0) {
            $cupom_db = $result_cupom->fetch_assoc();
            
            if ($cupom_db['tipo'] === 'percent') {
                $valor_desconto = ($total_itens * (float)$cupom_db['valor']) / 100;
            } else { // fixed
                $valor_desconto = (float)$cupom_db['valor'];
                if ($valor_desconto > $total_itens) {
                    $valor_desconto = $total_itens; // Garante que não zere
                }
            }
        }
        $stmt_cupom->close();
    }
    // =========================================================
    // ### FIM DA CORREÇÃO ###
    // =========================================================

    // 5. Calcula o TOTAL FINAL (com desconto e taxas)
    $total_final = $total_itens - $valor_desconto; // <-- APLICA O DESCONTO

    $taxa_entrega_bairro = $data['taxa_entrega'] ?? 0;
    if ($tipo_entrega === 'delivery' && is_numeric($taxa_entrega_bairro) && $taxa_entrega_bairro > 0) { 
        $total_final += (float)$taxa_entrega_bairro; 
    }
    if ($taxa_embalagem_restaurante > 0) { 
        $total_final += $taxa_embalagem_restaurante; 
    }

    // 6. Prepara o JSON para salvar
    $detalhes_pedido = json_encode([
        'itens' => $carrinho, 
        'cliente' => $cliente_info, 
        'taxa_entrega' => $taxa_entrega_bairro, 
        'taxa_embalagem' => $taxa_embalagem_restaurante, 
        'tipo_entrega' => $tipo_entrega,
        'cupom_codigo' => $cupom_codigo_cliente, // Salva o cupom
        'valor_desconto' => $valor_desconto      // Salva o desconto
    ]);
    
    if (json_last_error() !== JSON_ERROR_NONE) { http_response_code(500); exit(); }

    // 7. Salva no banco
    $stmt = $conn->prepare("INSERT INTO pedidos (restaurante_id, cliente_id, total, detalhes_pedido) VALUES (?, ?, ?, ?)");
    if (!$stmt) { http_response_code(500); exit(); }
    $stmt->bind_param("iids", $restaurante_id, $cliente_id, $total_final, $detalhes_pedido);
    if ($stmt->execute()) { http_response_code(201); echo json_encode(["status" => "success", "id" => $stmt->insert_id]); }
    else { http_response_code(500); }
    $stmt->close();
    exit(); 

// =============================================================
// MÉTODO PATCH - ATUALIZAR STATUS (v2)
// =============================================================
} elseif ($method === 'PATCH') {
    // ... (código original do PATCH v2 sem alterações) ...
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['restaurante_id'])) { http_response_code(403); exit(); }
    $restaurante_id_logado = $_SESSION['restaurante_id']; $id_pedido = $_GET['id'] ?? ''; $data = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE || empty($id_pedido) || !filter_var($id_pedido, FILTER_VALIDATE_INT)) { http_response_code(400); exit(); }
    $fields_to_update = []; $params = []; $types = "";
    if (isset($data['status'])) {
        $novo_status = $data['status'];
        $allowed_statuses = ['novo', 'preparacao', 'pronto', 'em_entrega', 'concluido', 'cancelado', 'recusado'];
        if (!in_array($novo_status, $allowed_statuses)) { http_response_code(400); exit(); }
        $fields_to_update[] = "status = ?"; $params[] = $novo_status; $types .= "s";
    }
    if (isset($data['entregador_id'])) {
        $entregador_id = filter_var($data['entregador_id'], FILTER_VALIDATE_INT);
        if (!$entregador_id) { http_response_code(400); exit(); }
        $stmt_check = $conn->prepare("SELECT id FROM usuarios WHERE id = ? AND restaurante_id = ? AND role = 'entregador'");
        $stmt_check->bind_param("ii", $entregador_id, $restaurante_id_logado); $stmt_check->execute();
        if ($stmt_check->get_result()->num_rows === 0) { http_response_code(403); exit(); }
        $stmt_check->close();
        $fields_to_update[] = "entregador_id = ?"; $params[] = $entregador_id; $types .= "i";
    }
    if (isset($data['pagamento_entrega_status'])) {
        $novo_pagamento_status = $data['pagamento_entrega_status'];
        if ($novo_pagamento_status !== 'pago' && $novo_pagamento_status !== 'pendente') { http_response_code(400); exit(); }
        $fields_to_update[] = "pagamento_entrega_status = ?"; $params[] = $novo_pagamento_status; $types .= "s";
    }
    if (empty($fields_to_update)) { http_response_code(400); exit(); }
    $sql = "UPDATE pedidos SET " . implode(", ", $fields_to_update) . " WHERE id = ? AND restaurante_id = ?";
    $params[] = $id_pedido; $types .= "i"; $params[] = $restaurante_id_logado; $types .= "i";
    $stmt = null;
    try {
        $stmt = $conn->prepare($sql); $stmt->bind_param($types, ...$params);
        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) { echo json_encode(["status" => "success", "message" => "Pedido atualizado."]); } 
            else { http_response_code(404); echo json_encode(["status" => "error", "message" => "Pedido não encontrado ou dados já estavam atualizados."]); }
        } else { throw new mysqli_sql_exception("Execute PATCH falhou."); }
    } catch (mysqli_sql_exception | Exception $e) { error_log("Erro execute PATCH (v2): ".$e->getMessage()); http_response_code(500); } 
    finally { if ($stmt) $stmt->close(); }
    exit(); 

} else {
    http_response_code(405); echo json_encode(["status" => "error", "message" => "Método não permitido."]); exit();
}

if ($conn) { $conn->close(); error_log("Conexão DB fechada no final."); }
error_log("--- PEDIDOS_API: Fim da execução ---");
?>
