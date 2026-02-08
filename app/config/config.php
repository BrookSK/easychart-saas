<?php

// defina aqui o ambiente atual
define('ENVIRONMENT', 'production'); // 'development' ou 'production'

define('DB_DEV_HOST', 'localhost');
define('DB_DEV_NAME', 'easychart-saas');
define('DB_DEV_USER', 'easychart-saas');

define('DB_DEV_PASS', 'Yd8wEtQ*rjv$es41');
define('DB_DEV_CHARSET', 'utf8');

define('DB_PROD_HOST', 'localhost');
define('DB_PROD_NAME', 'easychart-saas');
define('DB_PROD_USER', 'easychart-saas');
define('DB_PROD_PASS', 'Yd8wEtQ*rjv$es41');
define('DB_PROD_CHARSET', 'utf8');

// URL base por ambiente (ajuste as URLs conforme seu servidor)
if (ENVIRONMENT === 'development') {
    // Ex.: acessando em http://localhost:8080/Projeto-criacao-graficos/public
    define('BASE_URL', '/Projeto-criacao-graficos/public');
} else {
    // Em produção, ajuste conforme a pasta pública no servidor
    // Como o domínio deve abrir direto na raiz, use a raiz do site
    define('BASE_URL', '/');
}