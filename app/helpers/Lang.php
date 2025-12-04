<?php

class Lang
{
    private static $translations = [
        'pt' => [
            // Navigation
            'Dashboard' => 'Dashboard',
            'Spreadsheets' => 'Planilhas',
            'Settings' => 'Configurações',
            'AI Admin' => 'Admin IA',
            'Welcome' => 'Bem-vindo',
            'Logout' => 'Sair',
            
            // Auth/Login
            'Login' => 'Entrar',
            'Sign in to your account' => 'Entre na sua conta',
            'Email Address' => 'Endereço de E-mail',
            'Password' => 'Senha',
            'Remember me' => 'Lembrar de mim',
            'Sign in' => 'Entrar',
            "Don't have an account?" => 'Não tem uma conta?',
            'Sign up' => 'Cadastre-se',
            'Demo Credentials' => 'Credenciais de Demonstração',
            'Or create a new account' => 'Ou crie uma nova conta',
            
            // Auth/Register
            'Create Account' => 'Criar Conta',
            'Sign up to get started' => 'Cadastre-se para começar',
            'Full Name' => 'Nome Completo',
            'Confirm Password' => 'Confirmar Senha',
            'Already have an account?' => 'Já tem uma conta?',
            
            // Dashboard
            'Dashboard' => 'Dashboard',
            'AI Chart Generator' => 'Gerador de Gráficos IA',
            'Generate charts from your spreadsheets using AI' => 'Gere gráficos de suas planilhas usando IA',
            'Tell me what you want to visualize and I\'ll create the perfect chart for you' => 'Me diga o que você quer visualizar e eu criarei o gráfico perfeito para você',
            'Select Spreadsheet' => 'Selecionar Planilha',
            'Choose a spreadsheet...' => 'Escolha uma planilha...',
            'Or upload a new spreadsheet' => 'Ou carregue uma nova planilha',
            'What do you want to visualize?' => 'O que você quer visualizar?',
            'Show sales trend over time' => 'Mostrar tendência de vendas ao longo do tempo',
            'Compare revenue by region' => 'Comparar receita por região',
            'Generate' => 'Gerar',
            'or' => 'ou',
            'Upload your first spreadsheet to start generating charts with AI' => 'Carregue sua primeira planilha para começar a gerar gráficos com IA',
            'Total Spreadsheets' => 'Total de Planilhas',
            'Generated Charts' => 'Gráficos Gerados',
            'Saved Dashboards' => 'Dashboards Salvos',
            'AI Insights' => 'Insights IA',
            'Automatic textual analysis generated from your spreadsheet' => 'Análises textuais automáticas geradas a partir da sua planilha',
            'Recent Charts' => 'Gráficos Recentes',
            'No charts yet' => 'Nenhum gráfico ainda',
            
            // Spreadsheets
            'Spreadsheets' => 'Planilhas',
            'Manage your spreadsheets and upload new files' => 'Gerencie suas planilhas e carregue novos arquivos',
            'Upload CSV File' => 'Carregar Arquivo CSV',
            'Upload your spreadsheet to start creating amazing visualizations' => 'Carregue sua planilha para começar a criar visualizações incríveis',
            'Choose File' => 'Escolher Arquivo',
            'Supports CSV files up to 10MB' => 'Suporta arquivos CSV até 10MB',
            'Size' => 'Tamanho',
            'Uploaded At' => 'Carregado em',
            'File Name' => 'Nome do Arquivo',
            'Upload Date' => 'Data de Upload',
            'Actions' => 'Ações',
            'Download' => 'Baixar',
            'Delete' => 'Excluir',
            'No spreadsheets uploaded yet' => 'Nenhuma planilha carregada ainda',
            'No spreadsheets uploaded' => 'Nenhuma planilha carregada',
            'Upload your first CSV file to get started with data visualization' => 'Carregue seu primeiro arquivo CSV para começar com visualização de dados',
            'Delete this spreadsheet?' => 'Excluir esta planilha?',
            
            // Settings
            'Settings' => 'Configurações',
            'Manage your account preferences and API configurations' => 'Gerencie suas preferências de conta e configurações de API',
            'Profile Information' => 'Informações do Perfil',
            'Update your personal details' => 'Atualize seus dados pessoais',
            'API Configuration' => 'Configuração da API',
            'Configure your API key used for AI chart generation' => 'Configure sua chave API usada para geração de gráficos IA',
            'OpenAI API Key' => 'Chave API OpenAI',
            'Notification Preferences' => 'Preferências de Notificação',
            'Choose how you want to be notified' => 'Escolha como deseja ser notificado',
            'Email notifications for chart generation' => 'Notificações por e-mail para geração de gráficos',
            'Weekly usage summary' => 'Resumo semanal de uso',
            'Product updates and announcements' => 'Atualizações e anúncios do produto',
            'Save Changes' => 'Salvar Alterações',
            
            // Admin
            'Admin Panel' => 'Painel Administrativo',
            'System overview and user management' => 'Visão geral do sistema e gerenciamento de usuários',
            'Total Users' => 'Total de Usuários',
            'Active Users' => 'Usuários Ativos',
            'Total Spreadsheets' => 'Total de Planilhas',
            'Total Charts' => 'Total de Gráficos',
            'User Management' => 'Gerenciamento de Usuários',
            'View all users and manage their roles' => 'Ver todos os usuários e gerenciar suas funções',
            'ID' => 'ID',
            'Name' => 'Nome',
            'Email' => 'E-mail',
            'Role' => 'Função',
            'Status' => 'Status',
            'Last Login' => 'Último Login',
            'View' => 'Ver',
            'Toggle Role' => 'Alternar Função',
            'Toggle Status' => 'Alternar Status',
            'Delete User' => 'Excluir Usuário',
            'No users found' => 'Nenhum usuário encontrado',
            'No users found. Users will appear here once they register for the platform' => 'Nenhum usuário encontrado. Os usuários aparecerão aqui assim que se registrarem na plataforma',
            'User' => 'Usuário',
            'Joined' => 'Entrou em',
            'Charts' => 'Gráficos',
            'Spreadsheets' => 'Planilhas',
            'Actions' => 'Ações',
            'Make Admin' => 'Tornar Admin',
            'Make User' => 'Tornar Usuário',
            'Deactivate' => 'Desativar',
            'Activate' => 'Ativar',
            'Delete' => 'Excluir',
            'Delete this user? This action cannot be undone' => 'Excluir este usuário? Esta ação não pode ser desfeita',
            '(current user)' => '(usuário atual)',
            '(inactive)' => '(inativo)',
            'User Details' => 'Detalhes do Usuário',
            'Account Information' => 'Informações da Conta',
            'User ID' => 'ID do Usuário',
            'Registration Date' => 'Data de Registro',
            'Account Status' => 'Status da Conta',
            'Active' => 'Ativo',
            'Inactive' => 'Inativo',
            'User Role' => 'Função do Usuário',
            'Change Role' => 'Alterar Função',
            'Change Status' => 'Alterar Status',
            'Back to Users' => 'Voltar aos Usuários',
            
            // Messages
            'Changes saved successfully' => 'Alterações salvas com sucesso',
            'Error saving changes' => 'Erro ao salvar alterações',
            'Account created successfully' => 'Conta criada com sucesso',
            'Invalid email or password' => 'E-mail ou senha inválidos',
            'Passwords do not match' => 'As senhas não coincidem',
            'File uploaded successfully' => 'Arquivo carregado com sucesso',
            'Error uploading file' => 'Erro ao carregar arquivo',
            'Chart generated successfully' => 'Gráfico gerado com sucesso',
            'Error generating chart' => 'Erro ao gerar gráfico',
        ],
        'en' => [
            // Navigation
            'Dashboard' => 'Dashboard',
            'Spreadsheets' => 'Spreadsheets',
            'Settings' => 'Settings',
            'AI Admin' => 'AI Admin',
            'Welcome' => 'Welcome',
            'Logout' => 'Logout',
            
            // Auth/Login
            'Login' => 'Login',
            'Sign in to your account' => 'Sign in to your account',
            'Email Address' => 'Email Address',
            'Password' => 'Password',
            'Remember me' => 'Remember me',
            'Sign in' => 'Sign in',
            "Don't have an account?" => "Don't have an account?",
            'Sign up' => 'Sign up',
            'Demo Credentials' => 'Demo Credentials',
            'Or create a new account' => 'Or create a new account',
            
            // Auth/Register
            'Create Account' => 'Create Account',
            'Sign up to get started' => 'Sign up to get started',
            'Full Name' => 'Full Name',
            'Confirm Password' => 'Confirm Password',
            'Already have an account?' => 'Already have an account?',
            
            // Dashboard
            'Dashboard' => 'Dashboard',
            'AI Chart Generator' => 'AI Chart Generator',
            'Generate charts from your spreadsheets using AI' => 'Generate charts from your spreadsheets using AI',
            'Tell me what you want to visualize and I\'ll create the perfect chart for you' => 'Tell me what you want to visualize and I\'ll create the perfect chart for you',
            'Select Spreadsheet' => 'Select Spreadsheet',
            'Choose a spreadsheet...' => 'Choose a spreadsheet...',
            'Or upload a new spreadsheet' => 'Or upload a new spreadsheet',
            'What do you want to visualize?' => 'What do you want to visualize?',
            'Show sales trend over time' => 'Show sales trend over time',
            'Compare revenue by region' => 'Compare revenue by region',
            'Generate' => 'Generate',
            'or' => 'or',
            'Upload your first spreadsheet to start generating charts with AI' => 'Upload your first spreadsheet to start generating charts with AI',
            'Total Spreadsheets' => 'Total Spreadsheets',
            'Generated Charts' => 'Generated Charts',
            'Saved Dashboards' => 'Saved Dashboards',
            'AI Insights' => 'AI Insights',
            'Automatic textual analysis generated from your spreadsheet' => 'Automatic textual analysis generated from your spreadsheet',
            'Recent Charts' => 'Recent Charts',
            'No charts yet' => 'No charts yet',
            
            // Spreadsheets
            'Spreadsheets' => 'Spreadsheets',
            'Manage your spreadsheets and upload new files' => 'Manage your spreadsheets and upload new files',
            'Upload CSV File' => 'Upload CSV File',
            'Upload your spreadsheet to start creating amazing visualizations' => 'Upload your spreadsheet to start creating amazing visualizations',
            'Choose File' => 'Choose File',
            'Supports CSV files up to 10MB' => 'Supports CSV files up to 10MB',
            'Size' => 'Size',
            'Uploaded At' => 'Uploaded At',
            'File Name' => 'File Name',
            'Upload Date' => 'Upload Date',
            'Actions' => 'Actions',
            'Download' => 'Download',
            'Delete' => 'Delete',
            'No spreadsheets uploaded yet' => 'No spreadsheets uploaded yet',
            'No spreadsheets uploaded' => 'No spreadsheets uploaded',
            'Upload your first CSV file to get started with data visualization' => 'Upload your first CSV file to get started with data visualization',
            'Delete this spreadsheet?' => 'Delete this spreadsheet?',
            
            // Settings
            'Settings' => 'Settings',
            'Manage your account preferences and API configurations' => 'Manage your account preferences and API configurations',
            'Profile Information' => 'Profile Information',
            'Update your personal details' => 'Update your personal details',
            'API Configuration' => 'API Configuration',
            'Configure your API key used for AI chart generation' => 'Configure your API key used for AI chart generation',
            'OpenAI API Key' => 'OpenAI API Key',
            'Notification Preferences' => 'Notification Preferences',
            'Choose how you want to be notified' => 'Choose how you want to be notified',
            'Email notifications for chart generation' => 'Email notifications for chart generation',
            'Weekly usage summary' => 'Weekly usage summary',
            'Product updates and announcements' => 'Product updates and announcements',
            'Save Changes' => 'Save Changes',
            
            // Admin
            'Admin Panel' => 'Admin Panel',
            'System overview and user management' => 'System overview and user management',
            'Total Users' => 'Total Users',
            'Active Users' => 'Active Users',
            'Total Spreadsheets' => 'Total Spreadsheets',
            'Total Charts' => 'Total Charts',
            'User Management' => 'User Management',
            'View all users and manage their roles' => 'View all users and manage their roles',
            'ID' => 'ID',
            'Name' => 'Name',
            'Email' => 'Email',
            'Role' => 'Role',
            'Status' => 'Status',
            'Last Login' => 'Last Login',
            'View' => 'View',
            'Toggle Role' => 'Toggle Role',
            'Toggle Status' => 'Toggle Status',
            'Delete User' => 'Delete User',
            'No users found' => 'No users found',
            'No users found. Users will appear here once they register for the platform' => 'No users found. Users will appear here once they register for the platform',
            'User' => 'User',
            'Joined' => 'Joined',
            'Charts' => 'Charts',
            'Spreadsheets' => 'Spreadsheets',
            'Actions' => 'Actions',
            'Make Admin' => 'Make Admin',
            'Make User' => 'Make User',
            'Deactivate' => 'Deactivate',
            'Activate' => 'Activate',
            'Delete' => 'Delete',
            'Delete this user? This action cannot be undone' => 'Delete this user? This action cannot be undone',
            '(current user)' => '(current user)',
            '(inactive)' => '(inactive)',
            'User Details' => 'User Details',
            'Account Information' => 'Account Information',
            'User ID' => 'User ID',
            'Registration Date' => 'Registration Date',
            'Account Status' => 'Account Status',
            'Active' => 'Active',
            'Inactive' => 'Inactive',
            'User Role' => 'User Role',
            'Change Role' => 'Change Role',
            'Change Status' => 'Change Status',
            'Back to Users' => 'Back to Users',
            
            // Messages
            'Changes saved successfully' => 'Changes saved successfully',
            'Error saving changes' => 'Error saving changes',
            'Account created successfully' => 'Account created successfully',
            'Invalid email or password' => 'Invalid email or password',
            'Passwords do not match' => 'Passwords do not match',
            'File uploaded successfully' => 'File uploaded successfully',
            'Error uploading file' => 'Error uploading file',
            'Chart generated successfully' => 'Chart generated successfully',
            'Error generating chart' => 'Error generating chart',
        ]
    ];

    public static function get($key, $lang = null)
    {
        if ($lang === null) {
            $lang = self::getCurrentLang();
        }

        return self::$translations[$lang][$key] ?? $key;
    }

    public static function getCurrentLang()
    {
        // Check session first
        if (isset($_SESSION['lang'])) {
            return $_SESSION['lang'];
        }

        // Check cookie
        if (isset($_COOKIE['lang'])) {
            return $_COOKIE['lang'];
        }

        // Default to Portuguese
        return 'pt';
    }

    public static function setLang($lang)
    {
        if (in_array($lang, ['pt', 'en'])) {
            $_SESSION['lang'] = $lang;
            setcookie('lang', $lang, time() + (86400 * 30), "/"); // 30 days
            return true;
        }
        return false;
    }

    public static function toggleLang()
    {
        $current = self::getCurrentLang();
        $new = $current === 'pt' ? 'en' : 'pt';
        return self::setLang($new);
    }
}
