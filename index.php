<?php

// Redireciona todas as requisições para a pasta public,
// que é onde está o bootstrap real da aplicação.

// Se o servidor estiver configurado para usar este index.php na raiz
// como DocumentRoot, a aplicação ainda irá funcionar normalmente.

require __DIR__ . '/public/index.php';
