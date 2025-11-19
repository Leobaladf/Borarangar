<?php
// ATENÇÃO: Arquivo de teste temporário. Deve ser apagado após o uso.

ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<pre>"; // Formata a saída para ficar mais legível

// --- INFORMAÇÕES DE CONEXÃO ---
$servername = "localhost";
$username   = "borarangar_user";
$dbname     = "borarangar_db";

// É AQUI QUE VOCÊ VAI COLOCAR A SENHA QUE VOCÊ ACHA QUE É A CORRETA
$password   = "Tredf1234----"; 

echo "Tentando conectar ao banco de dados '$dbname' com o usuário '$username'...\n\n";

// Cria a conexão
$conn = new mysqli($servername, $username, $password, $dbname);

// Verifica a conexão
if ($conn->connect_error) {
    echo "======================================\n";
    echo "🛑 FALHA NA CONEXÃO 🛑\n";
    echo "======================================\n\n";
    die("Motivo do erro: " . $conn->connect_error);
}

echo "======================================\n";
echo "✅ SUCESSO! ✅\n";
echo "======================================\n\n";
echo "Conexão com o banco de dados '$dbname' realizada com sucesso!\n";

// Fecha a conexão
$conn->close();

echo "</pre>";
?>
