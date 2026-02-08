<?php

class Router
{
    public static function dispatch()
    {
        $controllerName = $_GET['c'] ?? 'auth';
        $actionName     = $_GET['a'] ?? 'login';

        if (in_array($controllerName, ['plans', 'asaas'], true)) {
            http_response_code(404);
            echo 'Not found';
            exit;
        }

        if ($controllerName === 'admin' && in_array($actionName, ['plans', 'editPlan', 'deletePlan'], true)) {
            http_response_code(404);
            echo 'Not found';
            exit;
        }

        $controllerClass = ucfirst($controllerName) . 'Controller';
        $controllerFile  = __DIR__ . '/../controllers/' . $controllerClass . '.php';

        if (!file_exists($controllerFile)) {
            http_response_code(404);
            echo 'Controller not found';
            exit;
        }

        require_once $controllerFile;

        if (!class_exists($controllerClass)) {
            http_response_code(500);
            echo 'Controller class not found';
            exit;
        }

        $controller = new $controllerClass();

        if (!method_exists($controller, $actionName)) {
            http_response_code(404);
            echo 'Action not found';
            exit;
        }

        $controller->$actionName();
    }
}