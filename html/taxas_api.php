<?php
// /var/www/html/taxas_api.php

session_start();
header("Content-Type: application/json; charset=UTF-8");

// --- CONEXÃO COM O BANCO DE DADOS ---
$servername = "localhost"; $username = "borarangar_user"; $password = "Tredf1234----"; $dbname = "borarangar_db";
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); 
try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    $conn->set_charset('utf8mb4');
} catch(mysqli_sql_exception $e) {
    error_log("TAXAS_API: Falha Conexão DB: " . $e->getMessage());
    http_response_code(500); echo json_encode(["status" => "error", "message" => "Erro interno (DB Connect)"]); exit();
}

// --- LÓGICA PARA DETERMINAR O RESTAURANTE_ID ---
$restaurante_id_param = filter_input(INPUT_GET, 'restaurante_id', FILTER_VALIDATE_INT);
$restaurante_id_sessao = $_SESSION['restaurante_id'] ?? null;
$restaurante_id_operacao = null; // Será definido abaixo

// --- ROTEADOR ---
$method = $_SERVER['REQUEST_METHOD'];

// ===========================================
// MÉTODO GET - LISTAR TAXAS, PEDIDO MÍNIMO E TAXA DE EMBALAGEM
// ===========================================
if ($method === 'GET') {
    // Para GET, aceita ID da URL (cliente) ou da sessão (painel)
    $id_para_buscar = $restaurante_id_param ?: $restaurante_id_sessao;
    if (!$id_para_buscar) {
        http_response_code(400); echo json_encode(["status" => "error", "message" => "ID do restaurante não fornecido."]); exit();
    }
    
    $stmt_bairros = null;
    $stmt_config = null;
    try {
        // --- 1. BUSCAR CONFIGS (Pedido Mínimo e Taxa Embalagem) ---
        $pedido_minimo = 0.00;
        $taxa_embalagem = 0.00;

        // Busca as configs do restaurante (seja para o painel ou para o cliente)
        $stmt_config = $conn->prepare("SELECT pedido_minimo, taxa_embalagem FROM restaurantes WHERE id = ?");
        $stmt_config->bind_param("i", $id_para_buscar);
        $stmt_config->execute();
        $result_config = $stmt_config->get_result();
        if ($result_config->num_rows > 0) {
            $config_data = $result_config->fetch_assoc();
            $pedido_minimo = (float)$config_data['pedido_minimo'];
            $taxa_embalagem = (float)$config_data['taxa_embalagem'];
        }
        $stmt_config->close();

        // --- 2. BUSCAR TAXAS DE BAIRRO ---
        $apenas_ativos = isset($_GET['ativo']) && $_GET['ativo'] === 'true';
        
        $sql = "SELECT id, bairro, taxa, ativo 
                FROM taxas_entrega_bairro 
                WHERE restaurante_id = ?";
        
        if ($apenas_ativos) {
            $sql .= " AND ativo = TRUE"; 
        }
        $sql .= " ORDER BY bairro ASC"; 
        
        $stmt_bairros = $conn->prepare($sql);
        $stmt_bairros->bind_param("i", $id_para_buscar); // Usa o ID determinado
        $stmt_bairros->execute();
        $result_bairros = $stmt_bairros->get_result();
        $taxas = [];
        while($row = $result_bairros->fetch_assoc()) {
            $row['ativo'] = (bool)$row['ativo']; 
            $row['taxa'] = (float)$row['taxa']; 
            $taxas[] = $row;
        }
        
        // --- 3. RETORNAR O OBJETO COMPLETO ---
        echo json_encode([
            "pedido_minimo" => $pedido_minimo,
            "taxa_embalagem" => $taxa_embalagem,
            "taxas" => $taxas
        ]); 
        exit(); 

    } catch (mysqli_sql_exception | Exception $e) {
        error_log("TAXAS_API GET: Erro: ".$e->getMessage()); 
        http_response_code(500); echo json_encode(['status' => 'error', 'message' => 'Erro ao buscar taxas.']);
        exit();
    } finally {
        if ($stmt_bairros instanceof mysqli_stmt) { $stmt_bairros->close(); }
        if ($stmt_config instanceof mysqli_stmt) { $stmt_config->close(); }
    }
}

// Para POST, PUT, DELETE, exige sessão e usa o ID da sessão
if (!$restaurante_id_sessao) {
    http_response_code(403); 
    echo json_encode(["status" => "error", "message" => "Acesso negado. Faça o login."]);
    exit();
}
$restaurante_id_operacao = $restaurante_id_sessao;

// ===========================================
// MÉTODO POST - ADICIONAR NOVA TAXA DE BAIRRO
// (Sem alterações)
// ===========================================
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) { http_response_code(400); echo json_encode(["status" => "error", "message" => "JSON inválido."]); exit(); }

    $bairro = trim($data['bairro'] ?? '');
    $taxa_input = $data['taxa'] ?? null;
    $taxa = filter_var($taxa_input, FILTER_VALIDATE_FLOAT); 

    if (empty($bairro) || $taxa === false || $taxa < 0) {
        http_response_code(400); echo json_encode(["status" => "error", "message" => "Nome do bairro obrigatório e taxa deve ser numérica >= 0."]); exit();
    }
    
    $stmt = null;
    try {
        $sql = "INSERT INTO taxas_entrega_bairro (restaurante_id, bairro, taxa, ativo) VALUES (?, ?, ?, TRUE)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("isd", $restaurante_id_operacao, $bairro, $taxa); 
        $stmt->execute();
        
        http_response_code(201); 
        echo json_encode(["status" => "success", "message" => "Taxa adicionada!", "id" => $stmt->insert_id]);

    } catch (mysqli_sql_exception $e) { /* ... (Erro POST com verificação 1062) ... */ } 
      catch (Exception $e) { /* ... (Erro Geral POST) ... */ } 
      finally { if ($stmt instanceof mysqli_stmt) { $stmt->close(); } }
    exit(); 
}
// ===========================================
// MÉTODO PUT - ATUALIZAR TAXA DE BAIRRO OU CONFIGS GERAIS
// ===========================================
elseif ($method === 'PUT') {
    $id_taxa = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    $data = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) { http_response_code(400); echo json_encode(["status" => "error", "message" => "JSON inválido."]); exit(); }

    $stmt = null;
    
    // ==============================================
    // ### ATUALIZADO: ATUALIZAR CONFIGS GERAIS ###
    // (Se não houver ?id= na URL)
    // ==============================================
    if (!$id_taxa) {
        // Verifica quais campos foram enviados
        $fields_to_update = [];
        $params = [];
        $types = "";

        if (isset($data['pedido_minimo'])) {
            $pedido_minimo_input = $data['pedido_minimo'] ?? 0;
            $pedido_minimo = filter_var($pedido_minimo_input, FILTER_VALIDATE_FLOAT);
            if ($pedido_minimo === false || $pedido_minimo < 0) {
                http_response_code(400); echo json_encode(["status" => "error", "message" => "Pedido mínimo deve ser um número >= 0."]); exit();
            }
            $fields_to_update[] = "pedido_minimo = ?";
            $params[] = $pedido_minimo;
            $types .= "d";
        }
        
        if (isset($data['taxa_embalagem'])) {
            $taxa_embalagem_input = $data['taxa_embalagem'] ?? 0;
            $taxa_embalagem = filter_var($taxa_embalagem_input, FILTER_VALIDATE_FLOAT);
            if ($taxa_embalagem === false || $taxa_embalagem < 0) {
                http_response_code(400); echo json_encode(["status" => "error", "message" => "Taxa de embalagem deve ser um número >= 0."]); exit();
            }
            $fields_to_update[] = "taxa_embalagem = ?";
            $params[] = $taxa_embalagem;
            $types .= "d";
        }

        // Se nenhum campo válido foi enviado
        if (empty($fields_to_update)) {
            http_response_code(400); echo json_encode(["status" => "error", "message" => "Nenhum dado válido para atualizar."]); exit();
        }

        try {
            $sql_update_config = "UPDATE restaurantes SET " . implode(", ", $fields_to_update) . " WHERE id = ?";
            $params[] = $restaurante_id_operacao; // Adiciona o ID do restaurante no final
            $types .= "i";

            $stmt = $conn->prepare($sql_update_config);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();

            if ($stmt->affected_rows > 0) {
                echo json_encode(["status" => "success", "message" => "Configurações salvas!"]);
            } else {
                echo json_encode(["status" => "success", "message" => "Nenhuma alteração (valores já eram esses)."]);
            }
        } catch (mysqli_sql_exception $e) {
            http_response_code(500); echo json_encode(['status' => 'error', 'message' => 'Erro ao salvar configurações.']);
        } finally {
            if ($stmt instanceof mysqli_stmt) { $stmt->close(); }
        }
        exit();
    }
    
    // ==============================================
    // CENÁRIO ANTIGO: ATUALIZAR TAXA DE BAIRRO
    // (Se houver ?id= na URL)
    // ==============================================
    $bairro = trim($data['bairro'] ?? ''); 
    $taxa_input = $data['taxa'] ?? null;
    $taxa = ($taxa_input !== null) ? filter_var($taxa_input, FILTER_VALIDATE_FLOAT) : null;
    $ativo_input = $data['ativo'] ?? null; 
    $ativo_db = ($ativo_input === null) ? null : ($ativo_input ? 1 : 0); 

    $fields = []; $params = []; $types = "";
    if (!empty($bairro)) { $fields[] = "bairro = ?"; $params[] = $bairro; $types .= "s"; }
    if ($taxa !== null && $taxa !== false && $taxa >= 0) { $fields[] = "taxa = ?"; $params[] = $taxa; $types .= "d"; }
    if ($ativo_db !== null) { $fields[] = "ativo = ?"; $params[] = $ativo_db; $types .= "i"; }

    if (empty($fields)) { http_response_code(400); echo json_encode(["status" => "error", "message" => "Nenhum dado válido para atualizar."]); exit(); }

    $sql = "UPDATE taxas_entrega_bairro SET " . implode(", ", $fields) . " WHERE id = ? AND restaurante_id = ?";
    $params[] = $id_taxa; $types .= "i";
    $params[] = $restaurante_id_operacao; $types .= "i";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        if ($stmt->affected_rows > 0) {
            echo json_encode(["status" => "success", "message" => "Taxa atualizada!"]);
        } else { /* ... (Verifica se existe ou se não mudou) ... */ }

    } catch (mysqli_sql_exception $e) { /* ... (Erro PUT com verificação 1062) ... */ } 
      catch (Exception $e) { /* ... (Erro Geral PUT) ... */ } 
      finally { if ($stmt instanceof mysqli_stmt) { $stmt->close(); } }
    exit(); 
}
// ===========================================
// MÉTODO DELETE - REMOVER UMA TAXA
// (Sem alterações)
// ===========================================
elseif ($method === 'DELETE') {
    $id_taxa = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$id_taxa) { http_response_code(400); echo json_encode(["status" => "error", "message" => "ID da taxa obrigatório."]); exit(); }

    $stmt = null;
    try {
        $sql = "DELETE FROM taxas_entrega_bairro WHERE id = ? AND restaurante_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $id_taxa, $restaurante_id_operacao);
        $stmt->execute();

        if ($stmt->affected_rows > 0) {
            echo json_encode(["status" => "success", "message" => "Taxa removida!"]);
        } else { /* ... (Erro 404) ... */ }
    } catch (mysqli_sql_exception | Exception $e) { /* ... (Erro DELETE) ... */ } 
      finally { if ($stmt instanceof mysqli_stmt) { $stmt->close(); } }
    exit(); 
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
