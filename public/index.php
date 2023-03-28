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
use GuzzleHttp\Client;
use GuzzleHttp\Psr7;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\TransferException;
use DiDom\Document;
use Carbon\Carbon;

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
    $params = [];
    return $this->get('renderer')->render($response, 'index.phtml', $params);
})->setName('/');

$app->get('/urls/{id}', function ($request, $response, $args) {
    $id = $args['id'];
    $messages = $messages = $this->get('flash')->getMessages();

    $pdo = Connection::get()->connect();
    $dataBase = new PgsqlData($pdo);
    $dataFromDB = $dataBase->findUrlForId($args);
    $dataCheckUrl = $dataBase->selectAllByIdFromCheck($args);

    $params = ['id' => $dataFromDB[0]['id'],
                'name' => $dataFromDB[0]['name'],
                'created_at' => $dataFromDB[0]['created_at'],
                'flash' => $messages,
                'urls' => $dataCheckUrl];
    return $this->get('renderer')->render($response, 'urlsId.phtml', $params);
})->setName('urlsId');

$app->post('/urls', function ($request, $response) use ($router) {
    $urls = $request->getParsedBodyParam('url');
    $pdo = Connection::get()->connect();
    $dataBase = new PgsqlData($pdo);
    $error = [];

    $v = new Valitron\Validator(array('name' => $urls['name'], 'count' => strlen((string) $urls['name'])));
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
        $urls['time'] = Carbon::now();
        $isertInTable = $dataBase->insertInTable($urls);

        $id = $dataBase->getLastId();

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
});

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

    $checkUrl['url_id'] = $args['url_id'];
    $client = new Client();
    $name = $dataBase->selectNameByIdFromUrls($checkUrl);

    try {
        $res = $client->request('GET', $name[0]['name']);
        $checkUrl['status'] = $res->getStatusCode();
        $this->get('flash')->addMessage('success', 'Страница успешно проверена');
    } catch (TransferException $e) {
        $this->get('flash')->addMessage('failure', 'Произошла ошибка при проверке, не удалось подключиться');
        $url = $router->urlFor('urlsId', ['id' => $url_id]);
        $newResponse = $response->withStatus(422);
        return $newResponse->withRedirect($url);
    }

    $document = new Document($name[0]['name'], true);
    $title = optional($document->find('title')[0])->text();
    $h1 = optional($document->find('h1')[0])->text();
    $meta = optional($document->first('meta[name="description"]'))->getAttribute('content');

    if ($title !== null) {
        $checkUrl['title'] = $title;
    } else {
        $checkUrl['title'] = '';
    }

    if ($h1 !== null) {
        $checkUrl['h1'] = $h1;
    } else {
        $checkUrl['h1'] = '';
    }

    if ($meta !== null) {
        $checkUrl['meta'] = $meta;
    } else {
        $checkUrl['meta'] = '';
    }

    $checkUrl['time'] = Carbon::now();
    $dataBase->insertInTableChecks($checkUrl);

    $url = $router->urlFor('urlsId', ['id' => $url_id]);
    return $response->withRedirect($url);
});

$app->run();
