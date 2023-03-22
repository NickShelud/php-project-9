<?php

namespace Hexlet\Code;

class PgsqlData
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
    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * создание таблиц
     */

    public function query($sql, $params = [])
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

    public function insertInTable($name)
    {
        return $this->query('INSERT INTO urls(name) VALUES(:name) RETURNING id', $name);
    }

    public function findUrlForId($id)
    {
        return $this->query('SELECT * FROM urls WHERE id = :id', $id);
    }

    public function getAll()
    {
        return $this->query('SELECT * FROM urls');
    }

    public function getLastId()
    {
        return $this->query('SELECT MAX(id) FROM urls');
    }

    public function searchName($name)
    {
        return $this->query('SELECT id FROM urls WHERE name = :name', $name);
    }
}
