<?php

namespace Hexlet\Code;

/**
 * Создание в PostgreSQL таблицы из демонстрации PHP
 */
class CreateTable
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
    public function createTables()
    {
        $sql = 'CREATE TABLE IF NOT EXISTS urls (
                   id bigint PRIMARY KEY GENERATED ALWAYS AS IDENTITY,
                   name varchar(255),
                   created_at date default CURRENT_DATE
        );';

        $this->pdo->exec($sql);

        return $this;
    }
}
