<?php

// Подключение автозагрузки через composer
require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use DI\Container;
use Slim\Middleware\MethodOverrideMiddleware;
use Hexlet\Code\Connection;
use Hexlet\Code\CreatorTables;
use Hexlet\Code\PgsqlActions;
use Slim\Flash\Messages;
use Valitron\Validator;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Exception\ConnectException;
use DiDom\Document;
use Carbon\Carbon;

session_start();

$container = new Container();
$container->set('renderer', function () {
    return new Slim\Views\PhpRenderer(__DIR__ . '/../templates');
});

$container->set('flash', function () {
    return new Slim\Flash\Messages();
});

$container->set('connection', function () {
    $pdo = Connection::get()->connect();
    return $pdo;
});

$app = AppFactory::createFromContainer($container);
$app->add(MethodOverrideMiddleware::class);
$app->addErrorMiddleware(true, true, true);

$router = $app->getRouteCollector()->getRouteParser();

$app->get('/router', function ($request, $response) use ($router) {
    $router->urlFor('/');
    $router->urlFor('urlsId', ['id' => '']);
    $router->urlFor('urls');
    return $this->get('renderer')->render($response, 'index.phtml');
});

$app->get('/createTables', function ($request, $response) {
    $tableCreator = new CreatorTables($this->get('connection'));
    $tables = $tableCreator->createTables();
    $tablesCheck = $tableCreator->createTableWithChecks();
    return $response;
});

$app->get('/', function ($request, $response) {
    $params = [];
    return $this->get('renderer')->render($response, 'index.phtml', $params);
})->setName('/');

$app->get('/urls/{id}', function ($request, $response, $args) {
    $id = $args['id'];
    $messages = $messages = $this->get('flash')->getMessages();

    $dataBase = new PgsqlActions($this->get('connection'));
    $dataFromDB = $dataBase->query('SELECT * FROM urls WHERE id = :id', $args);
    $dataCheckUrl = $dataBase->query('SELECT * FROM urls_checks WHERE url_id = :id ORDER BY id DESC', $args);

    $params = ['id' => $dataFromDB[0]['id'],
                'name' => $dataFromDB[0]['name'],
                'created_at' => $dataFromDB[0]['created_at'],
                'flash' => $messages,
                'urls' => $dataCheckUrl];
    return $this->get('renderer')->render($response, 'urlsId.phtml', $params);
})->setName('urlsId');

$app->post('/urls', function ($request, $response) use ($router) {
    $urls = $request->getParsedBodyParam('url');
    $dataBase = new PgsqlActions($this->get('connection'));
    $error = [];

    try {
        $tableCreator = new CreatorTables($this->get('connection'));
        $tables = $tableCreator->createTables();
        $tablesCheck = $tableCreator->createTableWithChecks();
    } catch (\PDOException $e) {
        echo $e->getMessage();
    }

    $v = new Valitron\Validator(array('name' => $urls['name'], 'count' => strlen((string) $urls['name'])));
    $v->rule('required', 'name')->rule('lengthMax', 'count.*', 255)->rule('url', 'name');
    if ($v->validate()) {
        $parseUrl = parse_url($urls['name']);
        $urls['name'] = $parseUrl['scheme'] . '://' . $parseUrl['host'];

        $serachName = $dataBase->query('SELECT id FROM urls WHERE name = :name', $urls);

        if (count($serachName) !== 0) {
            $url = $router->urlFor('urlsId', ['id' => $serachName[0]['id']]);
            $this->get('flash')->addMessage('success', 'Страница уже существует');
            return $response->withRedirect($url);
        }
        $urls['time'] = Carbon::now();
        $isertInTable = $dataBase->query('INSERT INTO urls(name, created_at) VALUES(:name, :time) RETURNING id', $urls);

        $id = $dataBase->query('SELECT MAX(id) FROM urls');

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
    return $this->get('renderer')->render($response->withStatus(422), 'index.phtml', $params);
});

$app->get('/urls', function ($request, $response) {
    $dataBase = new PgsqlActions($this->get('connection'));
    $dataFromDB = $dataBase->query(
        'SELECT MAX(urls_checks.created_at) AS created_at, urls_checks.status_code, urls.id, urls.name 
        FROM urls 
        LEFT OUTER JOIN urls_checks ON urls_checks.url_id = urls.id 
        GROUP BY urls_checks.url_id, urls.id, urls_checks.status_code 
        ORDER BY urls.id DESC'
    );
    $params = ['data' => $dataFromDB];
    return $this->get('renderer')->render($response, 'urls.phtml', $params);
})->setName('urls');

$app->post('/urls/{url_id}/checks', function ($request, $response, $args) use ($router) {
    $url_id = $args['url_id'];
    $pdo = Connection::get()->connect();
    $dataBase = new PgsqlActions($pdo);

    $checkUrl['url_id'] = $args['url_id'];
    $name = $dataBase->query('SELECT name FROM urls WHERE id = :url_id', $checkUrl);

    try {
        $client = new Client();
        $res = $client->request('GET', $name[0]['name']);
        $checkUrl['status'] = $res->getStatusCode();
    } catch (ConnectException $e) {
        $this->get('flash')->addMessage('failure', 'Произошла ошибка при проверке, не удалось подключиться');
        $url = $router->urlFor('urlsId', ['id' => $url_id]);
        return $response->withRedirect($url);
    } catch (ClientException $e) {
        if ($e->getResponse()->getStatusCode() != 200) {
            $checkUrl['status'] = $e->getResponse()->getStatusCode();
            $checkUrl['title'] = 'Доступ ограничен: проблема с IP';
            $checkUrl['h1'] = 'Доступ ограничен: проблема с IP';
            $checkUrl['meta'] = 'Доступ ограничен: проблема с IP';
            $checkUrl['time'] = Carbon::now();
            $dataBase->query('INSERT INTO urls_checks(url_id, status_code, title, h1, description, created_at) 
            VALUES(:url_id, :status, :title, :h1, :meta, :time)', $checkUrl);
            $this->get('flash')->addMessage('warning', 'Проверка была выполнена успешно, но сервер ответил с ошибкой');
            $url = $router->urlFor('urlsId', ['id' => $url_id]);
            return $response->withRedirect($url);
        }
    }

    $document = new Document($name[0]['name'], true);
    $title = optional($document->first('title'));
    $h1 = optional($document->first('h1'));
    $meta = optional($document->first('meta[name="description"]'));

    if ($title?->text()) {
        $title = mb_substr($title->text(), 0, 255);
        $checkUrl['title'] = $title;
    } else {
        $checkUrl['title'] = '';
    }

    if ($h1?->text()) {
        $h1 = mb_substr($h1->text(), 0, 255);
        $checkUrl['h1'] = $h1;
    } else {
        $checkUrl['h1'] = '';
    }

    if ($meta?->getAttribute('content')) {
        $meta = mb_substr($meta->getAttribute('content'), 0, 255);
        $checkUrl['meta'] = $meta;
    } else {
        $checkUrl['meta'] = '';
    }

    $checkUrl['time'] = Carbon::now();

    if (isset($checkUrl['status'])) {
        try {
            $dataBase->query('INSERT INTO urls_checks(url_id, status_code, title, h1, description, created_at) 
            VALUES(:url_id, :status, :title, :h1, :meta, :time)', $checkUrl);
        } catch (\PDOException $e) {
            echo $e->getMessage();
        }
        $this->get('flash')->addMessage('success', 'Страница успешно проверена');
    }

    $url = $router->urlFor('urlsId', ['id' => $url_id]);
    return $response->withRedirect($url, 302);
});

$app->run();
