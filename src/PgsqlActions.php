<?php

namespace Hexlet\Code;

class PgsqlActions
{
    /**
     * объект PDO
     * @var \PDO
     */
    private $pdo;

    /**
     * инициализация объекта с объектом \PDO
     * @тип параметра $pdo
     */
    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * создание таблиц
     */

    public function query(string $sql, array $params = [])
    {
        // Подготовка запроса
        $stmt = $this->pdo->prepare($sql);

        // Обход массива с параметрами
        // и подставляем значения
        if (!empty($params)) {
            foreach ($params as $key => $value) {
                $stmt->bindValue(":$key", $value);
            }
        }

        // Выполняя запрос
        $stmt->execute();
        // Возвращаем ответ
        return $stmt->fetchAll($this->pdo::FETCH_ASSOC);
    }

    public function insertInTable(array $name)
    {
        return $this->query('INSERT INTO urls(name, created_at) VALUES(:name, :time) RETURNING id', $name);
    }

    public function insertInTableChecks(array $url_id)
    {
        return $this->query('INSERT INTO urls_checks(url_id, status_code, title, h1, description, created_at) 
        VALUES(:url_id, :status, :title, :h1, :meta, :time)', $url_id);
    }

    public function findUrlForId(array $id)
    {
        return $this->query('SELECT * FROM urls WHERE id = :id', $id);
    }

    public function getAll()
    {
        return $this->query('
        SELECT MAX(urls_checks.created_at) AS created_at, urls_checks.status_code, urls.id, urls.name 
        FROM urls 
        LEFT OUTER JOIN urls_checks ON urls_checks.url_id = urls.id 
        GROUP BY urls_checks.url_id, urls.id, urls_checks.status_code 
        ORDER BY urls.id DESC');
    }

    public function getLastId()
    {
        return $this->query('SELECT MAX(id) FROM urls');
    }

    public function getAllFromChecks()
    {
        return $this->query('SELECT * FROM urls_checks');
    }

    public function searchName(array $name)
    {
        return $this->query('SELECT id FROM urls WHERE name = :name', $name);
    }

    public function selectAllByIdFromCheck(array $id)
    {
        return $this->query('SELECT * FROM urls_checks WHERE url_id = :id ORDER BY id DESC', $id);
    }

    public function selectNameByIdFromUrls(array $id)
    {
        return $this->query('SELECT name FROM urls WHERE id = :url_id', $id);
    }
}
