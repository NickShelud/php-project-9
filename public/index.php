<?php

// Подключение автозагрузки через composer
require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use DI\Container;
use Slim\Middleware\MethodOverrideMiddleware;
use Hexlet\Code\Connection;
use Hexlet\Code\CreateTable;
use Hexlet\Code\PgsqlData;
use Slim\Flash\Messages;
use Valitron\Validator;

session_start();

try {
    $pdo = Connection::get()->connect();
    $tableCreator = new CreateTable($pdo);
    $tables = $tableCreator->createTables();
    $tablesCheck = $tableCreator->createTableWithChecks();
} catch (\PDOException $e) {
    echo $e->getMessage();
}

$container = new Container();
$container->set('renderer', function () {
    return new Slim\Views\PhpRenderer(__DIR__ . '/../templates');
});

$container->set('flash', function () {
    return new Slim\Flash\Messages();
});

$pdo = Connection::get()->connect();
$dataBase = new PgsqlData($pdo);

$app = AppFactory::createFromContainer($container);
$app->add(MethodOverrideMiddleware::class);
$app->addErrorMiddleware(true, true, true);

$router = $app->getRouteCollector()->getRouteParser();

$app->get('/router', function ($request, $response) use ($router) {
    $router->urlFor('/');
    $router->urlFor('urlsId', ['id' => 4]);
    $router->urlFor('urls');
    return $this->get('renderer')->render($response, 'index.phtml');
});

$app->get('/', function ($request, $response) use ($router) {
    $urls = $request->getQueryParam('url');
    $pdo = Connection::get()->connect();
    $dataBase = new PgsqlData($pdo);
    $error = [];

    $v = new Valitron\Validator(array('name' => $urls['name'], 'count' => strlen($urls['name'])));
    $v->rule('required', 'name')->rule('lengthMax', 'count.*', 255)->rule('url', 'name');
    if ($v->validate()) {
        $parseUrl = parse_url($urls['name']);
        $urls['name'] = $parseUrl['scheme'] . '://' . $parseUrl['host'];

        $serachName = $dataBase->searchName($urls);

        if (count($serachName) !== 0) {
            $url = $router->urlFor('urlsId', ['id' => $serachName[0]['id']]);
            $this->get('flash')->addMessage('success', 'Страница уже существует');
            return $response->withRedirect($url);
        }

        $isertInTable = $dataBase->insertInTable($urls);

        $id = $dataBase->getLastId();
        var_dump($id);

        $url = $router->urlFor('urlsId', ['id' => $id[0]['max']]);
        $this->get('flash')->addMessage('success', 'Страница успешно добавлена');

        return $response->withRedirect($url);
    } else {
        if (isset($urls) and strlen($urls['name']) < 1) {
            $error['name'] = 'URL не должен быть пустым';
        } elseif (isset($urls)) {
            $error['name'] = 'Некорректный URL';
        }
    }
    $params = ['errors' => $error];
    return $this->get('renderer')->render($response, 'index.phtml', $params);
})->setName('/');

$app->get('/urls/{id}', function ($request, $response, $args) {
    $id = $args['id'];
    $messages = $messages = $this->get('flash')->getMessages();
    $pdo = Connection::get()->connect();
    $dataBase = new PgsqlData($pdo);
    $dataFromDB = $dataBase->findUrlForId($args);
    $dataCheckUrl = $dataBase->selectAllByIdFromCheck($args);
    //var_dump($dataCheckUrl);
    $params = ['id' => $dataFromDB[0]['id'],
                'name' => $dataFromDB[0]['name'],
                'created_at' => strstr($dataFromDB[0]['created_at'], '.', true),
                'flash' => $messages['success'][0],
                'urls' => $dataCheckUrl];
    return $this->get('renderer')->render($response, 'urlsId.phtml', $params);
})->setName('urlsId');

$app->get('/urls', function ($request, $response) {
    $pdo = Connection::get()->connect();
    $dataBase = new PgsqlData($pdo);
    $dataFromDB = $dataBase->getAll();
    $params = ['data' => $dataFromDB];
    return $this->get('renderer')->render($response, 'urls.phtml', $params);
})->setName('urls');

$app->post('/urls/{url_id}/checks', function ($request, $response, $args) use ($router) {
    $url_id = $args['url_id'];
    $pdo = Connection::get()->connect();
    $dataBase = new PgsqlData($pdo);
    $dataBase->insertInTableChecks(['url_id' => $url_id]);

    $id['id'] = $args['url_id'];
    $dataCheckUrl = $dataBase->selectAllByIdFromCheck($id);
    $params = ['urls' => $dataCheckUrl];

    $url = $router->urlFor('urlsId', ['id' => $url_id]);
    $this->get('flash')->addMessage('success', 'Страница успешно проверена');
    //return $response->withRedirect($url)

    //$this->get('renderer')->render($response, 'urlsId.phtml', $params);
    return $response->withRedirect($url);
    //$this->get('renderer')->render($response, 'urlsId.phtml', $params);
});

//phpinfo();
$app->run();
