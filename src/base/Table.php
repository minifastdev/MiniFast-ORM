<?php

namespace MiniFastORM;

class Table
{
    protected $columns = [];
    protected $values = [];
    protected $table = '';
    protected $connection;
    protected $query = false;
    protected $cols = [];
    protected $values = [];
    protected $filters = [];
    protected $filterValues = [];
    protected $limit;
    protected $offset;
    protected $criteria;
    protected $criterias = ['>=', '>', '<=', '<', '=', '<>'];
    protected $count;
    
    public function __call($name, $arguments)
    {
        if (strpos($name, 'set') === 0) {
            $name = substr($name, 0, 3);
            $name = unFormatName($name);
            
            $this->set($name, $value);
        }
    }
    
    public static function create()
    {
        $this->query = true;
        return new __CLASS__();
    }
    
    protected function set(string $col, $value)
    {
        $case = $this->query ? 'Update':'Insert';
        $this->columns[] = $col;
        $this->values[$col . $case] = $value;
    }

    /*
     * Insert data into the database
     */
    public function save()
    {
        $database = $this->getPDO();
        if (!$this->query) {
            if (!empty($this->table) and !empty($this->columns) and !empty($this->values)) {
                $query = 'INSERT INTO'
                    . $this->table
                    . '(' . implode(', ', $this->columns) . ') '
                    . 'VALUES(:' . implode($case .', :', $this->columns) . $case .')';
                $req = $database->prepare($query);
                $req->execute($this->values);
            } else {
                throw new Exception('You cannot save before inserting data.');
            }
        } else {
            if (!empty($this->table) and !empty($this->cols) and !empty($this->values)) {
                $query = 'UPDATE ' . $this->table . ' SET ';

                $i = 0;
                foreach ($this->cols as $col) {
                    $query .= ($i > 0 ? ', ':'') . $col . ' = :' . $col . 'Update';
                    $i++;
                }

                if (!empty($this->filters) and !empty($this->filterValues)) {
                    $query .= ' WHERE ';
                    $i = 0;
                    foreach ($this->filters as $col) {
                        $query .= ($i > 0 ? ' AND ':'') . $col . ' = :' . $col . 'Filter';
                    }
                }

                $req = $database->prepare($query);
                $req->execute(array_merge($this->values, $this->filterValues));
            } else {
                throw new Exception('Nothing to update');
            }
        }
    }
    
    protected function getPDO()
    {
        return Database::getInstance()->getPDO();
    }

    public static function now()
    {
        return date('AAA-MM-DD hh:mm:ss');
    }
    
    protected function unFormatName($name)
    {
        $exploded = preg_replace('/([A-Z])/', '_$1', $string);
        return strtolower($exploded);
    }

    private function createFind(string $table = '')
    {
        $table = !empty($table) ? $table : $this->table;
        $query = 'SELECT ' . $table . '.* ';

        // count
        if (!empty($this->count)) {
            $query .= ' , COUNT(' . $this->count['col'] . ') AS ' . $this->count['name'] . ' ';
        }

        $query .= 'FROM ' . $table;

        // where
        if (!empty($this->filters) and !empty($this->filterValues)) {
            $i = 0;
            foreach ($this->filters as $filter) {
                $query .= ($i > 0 ? ' AND ':' WHERE ') . $filter . ' ' . (!empty($this->criteria) ? $this->criteria : '=') . ' :' . $filter . 'Filter';
                $i++;
            }
        }

        // limit
        if (!empty($this->limit)) {
            $query .= ' LIMIT ' . (!empty($this->offset) ? $this->offset . ', ' : '') . $this->limit;
        }

        $req = $this->co->prepare($query);
        $req->execute($this->filterValues);

        return $req;
    }

    private function fetchForeign(string $col, $value, string $table)
    {
        $base = $table . 'Query';
        $base = $base::create();
        $filter = 'FilterBy' . self::formatName($col);
        $base->$filter($value);
        $columns = $base->find(); // TODO return foreing values

        return $columns;
    }

    public function find(string $table = '', string $class = '')
    {
        $table = !empty($table) ? $table : $this->table;
        $class = !empty($class) ? $class : $this->base;
        $fetch = self::createFind($table)->fetch(PDO::FETCH_NAMED); // We need foreign values
        $base = new $class();
        $columns = $base->getColumns();

        foreach ($columns as $key => $col) {
            if ($col['foreign']) {
                $fetch[$key] = self::fetchForeign($col['foreign']['col'], $fetch[$key], $col['foreign']['table']);
            }
        }

        return $fetch;
    }

    public function findAll(string $table = '', string $class = '')
    {
        $table = !empty($table) ? $table : $this->table;
        $class = !empty($class) ? $class : $this->base;
        $fetchAll = self::createFind($table)->fetchAll(PDO::FETCH_NAMED);
        $base = new $class();
        $columns = $base->getColumns();
        $fks = [];

        foreach ($columns as $key => $col) {
            if ($col['foreign']) {
                $fks[$key] = $col['foreign'];
            }
        }

        if (sizeof($fks) > 0) {
            foreach ($fetchAll as $key => $entry) {
                foreach ($fks as $k2 => $fk) {
                    $fetchAll[$key][$k2] = self::fetchForeign($fk['col'], $fetchAll[$key][$k2], $fk['table']);
                }
            }
        }

        return $fetchAll;
    }

    public function filterBy(string $col, $value, $criteria = self::EQUALS)
    {
        $this->filters[] = $col;
        $this->filterValues[$col . 'Filter'] = $value;

        if (in_array($criteria, $this->criterias)) {
            $this->criteria = $criteria;
        } else {
            throw new Exception("Unknow criteria `$criteria`.");
        }

        return $this;
    }

    public function limit(int $max)
    {
        $this->limit = abs($max);
        return $this;
    }

    public function offset(int $length)
    {
        $this->offset = abs($length);
        return $this;
    }

    public function count(string $col, string $name)
    {
        $this->count = [
            'col' => $col,
            'name' => $name
        ];
    }

    public function delete($all = false)
    {
        if (!empty($this->filters) and !empty($this->filterValues)) {
            $query = 'DELETE FROM ' . $this->table . ' WHERE ';
            $i = 0;
            foreach ($this->filters as $col) {
                $query .= ($i > 0 ? ' AND ':'') . $col . ' = :' . $col . 'Filter';
            }

            $req = $this->co->prepare($query);
            $req->execute($this->filterValues);
        } else {
            if ($all) {
                $req = $this->co->query("DELETE FROM $this->table");
            } else {
                throw new Exception("If you want to delete all from $this->table, you need to specify optional argument delete(\$all = true)\n");
            }
        }
    }

    private function formatName(string $name)
    {
        $newName = explode('_', $name);
        $names = [];
        foreach ($newName as $Name) {
            $names[] = ucfirst(strtolower($Name));
        }

        return implode($names);
    }
}