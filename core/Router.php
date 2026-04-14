<?php

class Router
{
    private static array $controllerMap = [
        'auth'         => 'AuthController',
        'boards'       => 'BoardController',
        'lists'        => 'ListController',
        'cards'        => 'CardController',
        'comments'     => 'CommentController',
        'attachments'  => 'AttachmentController',
        'checklists'   => 'ChecklistController',
        'labels'       => 'LabelController',
        'notifications'=> 'NotificationController',
        'users'        => 'UserController',
    ];

    public static function dispatch(): void
    {
        $action = $_GET['action'] ?? '';
        if (empty($action)) {
            http_response_code(400);
            echo json_encode(['error' => 'No action specified']);
            return;
        }

        $parts = explode('.', $action, 2);
        if (count($parts) !== 2) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action format. Use: controller.method']);
            return;
        }

        [$controllerKey, $method] = $parts;

        if (!isset(self::$controllerMap[$controllerKey])) {
            http_response_code(404);
            echo json_encode(['error' => 'Unknown controller']);
            return;
        }

        $controllerClass = self::$controllerMap[$controllerKey];
        $controllerFile = __DIR__ . '/../controllers/' . $controllerClass . '.php';

        if (!file_exists($controllerFile)) {
            http_response_code(500);
            echo json_encode(['error' => 'Controller not found']);
            return;
        }

        require_once $controllerFile;
        $controller = new $controllerClass();

        // Convert snake_case method to camelCase
        $camelMethod = lcfirst(str_replace('_', '', ucwords($method, '_')));

        if (!method_exists($controller, $camelMethod)) {
            http_response_code(404);
            echo json_encode(['error' => 'Unknown method: ' . $method]);
            return;
        }

        $controller->$camelMethod();
    }
}
