<?php

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../helpers/EmailHelper.php';

class AuthController
{
    public function login()
    {
        $error = '';

        if (!empty($_GET['msg']) && $_GET['msg'] === 'cpf_exists') {
            $error = 'CPF já cadastrado. Faça login para continuar.';
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $email    = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';

            $user = User::findByEmail($email);

            if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
                echo '<pre>';
                echo "DEBUG LOGIN\n";
                var_dump([
                    'email_enviado'      => $email,
                    'usuario_encontrado' => $user ? true : false,
                    'hash_no_banco'      => $user['password_hash'] ?? null,
                    'password_verify'    => $user ? password_verify($password, $user['password_hash']) : null,
                ]);
                echo '</pre>';
            }

            if ($user && password_verify($password, $user['password_hash'])) {
                // inicia sessão e redireciona para o dashboard
                $_SESSION['user'] = [
                    'id'        => $user['id'],
                    'full_name' => $user['full_name'],
                    'email'     => $user['email'],
                    'role'      => $user['role'] ?? 'user',
                ];

                // Atualiza last_login_at para medir usuários ativos
                require_once __DIR__ . '/../core/Database.php';
                $pdo = Database::getConnection();
                $stmt = $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
                $stmt->execute(['id' => $user['id']]);

                header('Location: ' . BASE_URL . '?c=dashboard&a=index');
                exit;
            } else {
                $error = 'Invalid email or password.';
            }
        }

        require __DIR__ . '/../views/auth/login.php';
    }

    public function register()
    {
        $error  = '';
        $success = false;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $fullName        = trim($_POST['full_name'] ?? '');
            $email           = trim($_POST['email'] ?? '');
            $password        = $_POST['password'] ?? '';
            $passwordConfirm = $_POST['password_confirm'] ?? '';

            if ($password !== $passwordConfirm) {
                $error = 'Passwords do not match.';
            } elseif (User::findByEmail($email)) {
                $error = 'Email already registered.';
            } else {
                if (User::create($fullName, $email, $password, null)) {
                    $success = true;
                } else {
                    $error = 'Error creating account.';
                }
            }
        }

        require __DIR__ . '/../views/auth/register.php';
    }

    public function forgotPassword()
    {
        $error = '';
        $success = false;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $email = trim($_POST['email'] ?? '');

            if ($email === '') {
                $error = 'Informe o e-mail cadastrado.';
            } else {
                $user = User::findByEmail($email);

                // Para não expor se o e-mail existe ou não, sempre mostra a mesma mensagem ao final
                if ($user) {
                    $pdo = Database::getConnection();

                    // Gera token seguro de 64 caracteres hexadecimais
                    $token = bin2hex(random_bytes(32));

                    // Expira em 1 hora
                    $expiresAt = (new DateTime('+1 hour'))->format('Y-m-d H:i:s');

                    // Opcional: invalidar tokens antigos do mesmo usuário
                    $del = $pdo->prepare('DELETE FROM password_resets WHERE user_id = :uid OR expires_at < NOW()');
                    $del->execute(['uid' => $user['id']]);

                    $stmt = $pdo->prepare('INSERT INTO password_resets (user_id, token, expires_at) VALUES (:uid, :token, :expires)');
                    $stmt->execute([
                        'uid'    => $user['id'],
                        'token'  => $token,
                        'expires'=> $expiresAt,
                    ]);

                    $resetLink = BASE_URL . '?c=auth&a=resetPassword&token=' . urlencode($token);

                    $subject = 'Redefinição de senha - EasyChart';
                    $message = "Olá,\n\n" .
                        "Foi solicitada uma redefinição de senha para sua conta no EasyChart.\n" .
                        "Clique no link abaixo para definir uma nova senha (válido por 1 hora):\n\n" .
                        $resetLink . "\n\n" .
                        "Se você não solicitou esta alteração, ignore este e-mail.";

                    EmailHelper::send($email, $subject, $message);
                }

                $success = true;
            }
        }

        require __DIR__ . '/../views/auth/forgot_password.php';
    }

    public function resetPassword()
    {
        $error = '';
        $success = false;

        $token = $_GET['token'] ?? ($_POST['token'] ?? '');
        $token = is_string($token) ? trim($token) : '';

        if ($token === '') {
            $error = 'Token de redefinição inválido.';
            require __DIR__ . '/../views/auth/reset_password.php';
            return;
        }

        $pdo = Database::getConnection();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $newPassword     = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if ($newPassword === '' || $confirmPassword === '') {
                $error = 'Informe a nova senha e a confirmação.';
            } elseif ($newPassword !== $confirmPassword) {
                $error = 'As senhas não conferem.';
            } else {
                $stmt = $pdo->prepare('SELECT * FROM password_resets WHERE token = :token AND expires_at > NOW() AND used_at IS NULL LIMIT 1');
                $stmt->execute(['token' => $token]);
                $reset = $stmt->fetch();

                if (!$reset) {
                    $error = 'Token inválido ou expirado.';
                } else {
                    $userStmt = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
                    $userStmt->execute(['id' => $reset['user_id']]);
                    $user = $userStmt->fetch();

                    if (!$user) {
                        $error = 'Usuário não encontrado para este token.';
                    } else {
                        $hash = password_hash($newPassword, PASSWORD_DEFAULT);

                        $upd = $pdo->prepare('UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :id');
                        $upd->execute([
                            'hash' => $hash,
                            'id'   => $user['id'],
                        ]);

                        $updToken = $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = :id');
                        $updToken->execute(['id' => $reset['id']]);

                        $success = true;
                    }
                }
            }
        } else {
            $stmt = $pdo->prepare('SELECT * FROM password_resets WHERE token = :token AND expires_at > NOW() AND used_at IS NULL LIMIT 1');
            $stmt->execute(['token' => $token]);
            $reset = $stmt->fetch();

            if (!$reset) {
                $error = 'Token inválido ou expirado.';
            }
        }

        require __DIR__ . '/../views/auth/reset_password.php';
    }
}


