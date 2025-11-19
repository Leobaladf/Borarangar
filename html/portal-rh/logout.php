<?php
// /var/www/html/portal-rh/logout.php

// Inicia a sessão
session_start();

// Limpa todas as variáveis da sessão
$_SESSION = array();

// Destrói a sessão
session_destroy();

// Redireciona o usuário para a página de login do PORTAL
header("Location: /portal-rh/"); 
exit();
?>
