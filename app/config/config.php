<?php

// defina aqui o ambiente atual
define('ENVIRONMENT', 'production'); // 'development' ou 'production'

// URL base por ambiente (ajuste as URLs conforme seu servidor)
if (ENVIRONMENT === 'development') {
    // Ex.: acessando em http://localhost:8080/Projeto-criacao-graficos/public
    define('BASE_URL', '/Projeto-criacao-graficos/public');
} else {
    // Em produção, ajuste conforme a pasta pública no servidor
    // Como o domínio deve abrir direto na raiz, use a raiz do site
    define('BASE_URL', '/easychart-saas/');
}